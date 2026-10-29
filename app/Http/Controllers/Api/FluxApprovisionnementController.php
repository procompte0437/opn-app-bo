<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AntenneProvinciale;
use App\Models\CommandeTerritoriale;
use App\Models\FluxNotification;
use App\Models\FluxPieceCommerciale;
use App\Models\FluxPriseEnChargeSiege;
use App\Models\FluxTransfertSiege;
use App\Models\ProduitPharmaceutique;
use App\Models\User;
use App\Services\FluxPieceCommercialeService;
use App\Services\FluxPiecePdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FluxApprovisionnementController extends Controller
{
    private const ROLES_COMMANDE = ['DEMANDEUR_PHARMACIEN', 'DEMANDEUR_STRUCTURE', 'SUPER_ADMIN', 'ADMIN_METIER', 'CHEF_ANTENNE_PROVINCIALE', 'PHARMACIEN_CHEF_ANTENNE', 'DG'];
    private const ROLES_ANTENNE = ['CHEF_ANTENNE_PROVINCIALE', 'PHARMACIEN_CHEF_ANTENNE', 'SUPER_ADMIN', 'ADMIN_METIER'];
    private const ROLES_SIEGE = ['APPRO_NATIONAL', 'COLLABORATEUR_APPRO', 'DG', 'DIR', 'DAF', 'ACHATS', 'PHARMACIEN_CHEF_NATIONAL', 'SUPER_ADMIN', 'ADMIN_METIER'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing(['profil:id,code', 'structure:id,nom']);
        $stage = $this->voitToutesLesDemandes($user) ? 'supervision' : $this->stage($user->code_role);
        $antenneCompte = $this->antenneDuCompte($user);

        $query = CommandeTerritoriale::with(['antenne', 'lignes', 'journal', 'pieces.lignes'])->orderByDesc('id');

        /* Seuls le demandeur et l'antenne sont limités. L'admin métier voit tout. */
        if (!$this->voitToutesLesDemandes($user) && $stage === 'commande') {
            $query->where('demandeur_user_id', $user->id);
        } elseif (!$this->voitToutesLesDemandes($user) && $stage === 'antenne') {
            $antenneId = $this->antenneIdUtilisateur($user);
            if ($antenneId) {
                $query->where('antenne_id', $antenneId);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($request->filled('statut')) {
            $query->where('statut', $request->query('statut'));
        }

        $commandes = $query->get()->map(fn ($c) => $this->enrichirCommande($c));

        $payload = [
            'success' => true,
            'stage' => $stage,
            'server_time' => now()->toIso8601String(),
            'compteurs' => $this->compteurs($commandes, $stage),
            'peut_supprimer' => $this->peutSupprimer($user),
            'antenne_compte' => $antenneCompte ? [
                'id' => $antenneCompte->id,
                'nom' => $antenneCompte->nom,
                'chef_lieu' => $antenneCompte->chef_lieu,
            ] : null,
            'structure_compte' => $user->structure?->nom ?? $user->entite,
            'commandes' => $commandes,
        ];

        // Siège (Direction générale) et supervision (admin métier, super admin) :
        // année → mois → antenne qui a transféré → demandeur, ou l'antenne elle-même.
        if (in_array($stage, ['siege', 'supervision'], true)) {
            $payload['groupees'] = $this->grouperPourSiege($commandes);
        }

        // Chef d'antenne : demandes organisées par demandeur.
        if ($stage === 'antenne') {
            $payload['par_demandeurs'] = $this->grouperParDemandeurs($commandes);
        }

        return response()->json($payload);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!in_array($user->code_role, self::ROLES_COMMANDE, true)) {
            return response()->json(['success' => false, 'message' => 'Seul un partenaire ou une antenne peut enregistrer une commande.'], 403);
        }

        $data = $request->validate([
            'antenne_id' => 'nullable|exists:antennes_provinciales,id',
            'partenaire_nom' => 'required|string|max:200',
            'partenaire_type' => 'nullable|string|max:40',
            'motif' => 'nullable|string|max:1000',
            'categorie' => 'nullable|string|max:120',
            'kit_medical' => 'nullable|string|max:120',
            'brouillon' => 'nullable|boolean',
            'date_livraison_prevue' => 'nullable|date',
            'lignes' => 'required|array|min:1',
            'lignes.*.produit_id' => 'nullable|exists:produits_pharmaceutiques,id',
            'lignes.*.designation' => 'required|string|max:200',
            'lignes.*.categorie' => 'nullable|string|max:120',
            'lignes.*.quantite_demandee' => 'required|integer|min:1',
        ]);

        /* L'antenne de la commande est celle du compte, sauf pour la Direction générale qui choisit l'antenne de réception. */
        $antenneCompte = $this->antenneIdUtilisateur($user);
        $choisitAntenne = in_array($user->code_role, ['SUPER_ADMIN', 'ADMIN_METIER', 'DG'], true);
        if (!$choisitAntenne) {
            if (!$antenneCompte) {
                return response()->json(['success' => false, 'message' => 'Aucune antenne n\'est rattachée à ce compte.'], 422);
            }
            $data['antenne_id'] = $antenneCompte;
        } elseif (empty($data['antenne_id'])) {
            $data['antenne_id'] = $antenneCompte;
        }
        if (empty($data['antenne_id'])) {
            return response()->json(['success' => false, 'message' => 'Choisissez une antenne destinataire.'], 422);
        }

        if (in_array($user->code_role, ['CHEF_ANTENNE_PROVINCIALE', 'PHARMACIEN_CHEF_ANTENNE'], true)
            && (int) $antenneCompte !== (int) $data['antenne_id']) {
            return response()->json(['success' => false, 'message' => 'Cette commande doit concerner votre antenne.'], 403);
        }

        $commande = DB::transaction(function () use ($data, $user) {
            $montant = 0;
            $lignes = [];
            foreach ($data['lignes'] as $ligne) {
                $prix = 0;
                if (!empty($ligne['produit_id'])) {
                    $prix = (float) (ProduitPharmaceutique::find($ligne['produit_id'])->prix_unitaire_xaf ?? 0);
                }
                $montant += $prix * (int) $ligne['quantite_demandee'];
                $qte = (int) $ligne['quantite_demandee'];
                $row = [
                    'produit_id' => $ligne['produit_id'] ?? null,
                    'designation' => $ligne['designation'],
                    'quantite_demandee' => $qte,
                    'quantite_prise_en_charge' => 0,
                    'quantite_reliquat' => $qte,
                    'quantite_dispersee' => 0,
                    'prix_unitaire_xaf' => $prix,
                ];
                if (Schema::hasColumn('lignes_commande_territoriale', 'categorie')) {
                    $row['categorie'] = $ligne['categorie'] ?? ($data['categorie'] ?? null);
                }
                if (Schema::hasColumn('lignes_commande_territoriale', 'quantite_prise_antenne')) {
                    $row['quantite_prise_antenne'] = 0;
                }
                $lignes[] = $row;
            }

            $creeeParChef = in_array($user->code_role, ['CHEF_ANTENNE_PROVINCIALE', 'PHARMACIEN_CHEF_ANTENNE'], true);
            $creeeParDg = $user->code_role === 'DG';
            $estDemandeur = in_array($user->code_role, ['DEMANDEUR_PHARMACIEN', 'DEMANDEUR_STRUCTURE'], true);
            $now = now();
            // Demandeur : brouillon possible. Chef : part tout de suite vers la DG.
            if ($creeeParChef) {
                $statutInitial = 'TRANSFEREE_SIEGE';
            } elseif ($estDemandeur && !empty($data['brouillon'])) {
                $statutInitial = 'BROUILLON';
            } else {
                $statutInitial = 'SOUMISE_ANTENNE';
            }
            // Chef : à son nom. Direction générale : identifiable comme telle, destinée à l'antenne choisie.
            $partenaireNom = $creeeParDg ? 'Direction générale' : ($creeeParChef ? $user->name : $data['partenaire_nom']);
            $partenaireType = $creeeParDg ? 'DIRECTION_GENERALE' : ($creeeParChef ? 'ANTENNE' : ($data['partenaire_type'] ?? 'PHARMACIE'));

            $payload = [
                'numero_commande' => 'CMD-'.now()->format('Ymd').'-'.str_pad((string) (CommandeTerritoriale::count() + 1), 4, '0', STR_PAD_LEFT),
                'antenne_id' => $data['antenne_id'],
                'demandeur_user_id' => $user->id,
                'demandeur_nom' => $user->name,
                'demandeur_role' => $user->role,
                'partenaire_nom' => $partenaireNom,
                'partenaire_type' => $partenaireType,
                'statut' => $statutInitial,
                'motif' => $data['motif'] ?? null,
                'montant_total_xaf' => $montant,
                'transferee_au_siege_at' => $creeeParChef ? $now : null,
                'transferee_au_siege_par_user_id' => $creeeParChef ? $user->id : null,
                'transferee_au_siege_par_nom' => $creeeParChef ? $user->name : null,
                'transmise_at' => $creeeParChef ? $now : null,
                'transmise_par_nom' => $creeeParChef ? $user->name : null,
            ];
            foreach (['categorie' => $data['categorie'] ?? null, 'kit_medical' => $data['kit_medical'] ?? null, 'date_livraison_prevue' => $data['date_livraison_prevue'] ?? null] as $col => $val) {
                if (Schema::hasColumn('commandes_territoriales', $col)) {
                    $payload[$col] = $val;
                }
            }

            $commande = CommandeTerritoriale::create($payload);
            foreach ($lignes as $ligne) {
                $commande->lignes()->create($ligne);
            }

            $commande->load('antenne');
            $antenneNom = $commande->antenne?->nom ?? ('Antenne #'.$commande->antenne_id);
            if ($creeeParDg) {
                $detailCreation = 'Commande de la Direction générale ('.$user->name.') passée à l\'antenne '.$antenneNom;
            } elseif ($creeeParChef) {
                $detailCreation = 'Commande générée par l\'antenne '.$antenneNom.' ('.$user->name.')';
            } else {
                $detailCreation = 'Commande de '.$user->name.' pour l\'antenne '.$antenneNom;
            }
            $this->journaliser($commande, $user, 'CREATION', $detailCreation);

            if ($creeeParChef) {
                $qteTotale = collect($lignes)->sum('quantite_demandee');
                FluxTransfertSiege::create([
                    'commande_id' => $commande->id,
                    'antenne_id' => $commande->antenne_id,
                    'user_id' => $user->id,
                    'auteur_nom' => $user->name,
                    'auteur_role' => $user->role,
                    'antenne_nom' => $antenneNom,
                    'annee' => (int) $now->format('Y'),
                    'mois' => (int) $now->format('n'),
                    'nb_lignes' => count($lignes),
                    'quantite_totale' => $qteTotale,
                    'transferee_at' => $now,
                    'detail' => 'Bon du chef transféré à la Direction générale dès l\'enregistrement',
                ]);
                $this->journaliser(
                    $commande,
                    $user,
                    'TRANSFERT',
                    'Transférée à la Direction générale par '.$user->name.' (antenne '.$antenneNom.')'
                );
            }

            return $commande->load(['antenne', 'lignes', 'journal']);
        });

        return response()->json(['success' => true, 'commande' => $commande], 201);
    }

    /**
     * Le demandeur corrige sa propre demande tant qu'elle n'a pas quitté l'antenne.
     */
    public function modifier(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $commande = CommandeTerritoriale::with('lignes')->findOrFail($id);
        if (!$this->demandeurPeutEditer($user, $commande)) {
            return response()->json([
                'success' => false,
                'message' => 'Cette demande ne peut plus être modifiée : elle a déjà été traitée.',
            ], 403);
        }

        $data = $request->validate([
            'motif' => 'nullable|string|max:1000',
            'lignes' => 'required|array|min:1',
            'lignes.*.produit_id' => 'nullable|exists:produits_pharmaceutiques,id',
            'lignes.*.designation' => 'required|string|max:200',
            'lignes.*.quantite_demandee' => 'required|integer|min:1',
        ]);

        $commande = DB::transaction(function () use ($data, $user, $commande) {
            $montant = 0;
            $lignes = [];
            foreach ($data['lignes'] as $ligne) {
                $prix = 0;
                if (!empty($ligne['produit_id'])) {
                    $prix = (float) (ProduitPharmaceutique::find($ligne['produit_id'])->prix_unitaire_xaf ?? 0);
                }
                $qte = (int) $ligne['quantite_demandee'];
                $montant += $prix * $qte;
                $lignes[] = [
                    'produit_id' => $ligne['produit_id'] ?? null,
                    'designation' => $ligne['designation'],
                    'quantite_demandee' => $qte,
                    'quantite_prise_en_charge' => 0,
                    'quantite_reliquat' => $qte,
                    'quantite_dispersee' => 0,
                    'prix_unitaire_xaf' => $prix,
                ];
            }

            $commande->lignes()->delete();
            foreach ($lignes as $ligne) {
                $commande->lignes()->create($ligne);
            }
            $commande->update([
                'motif' => $data['motif'] ?? null,
                'montant_total_xaf' => $montant,
            ]);
            $this->journaliser($commande, $user, 'MODIFICATION', 'Demande corrigée par '.$user->name);

            return $commande->fresh(['antenne', 'lignes', 'journal']);
        });

        return response()->json(['success' => true, 'commande' => $commande]);
    }

    /**
     * Transfert antenne → siège (sans prise en charge de quantités).
     * Le chef d'antenne envoie la demande telle quelle au siège.
     */
    public function transferer(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!in_array($user->code_role, self::ROLES_ANTENNE, true)) {
            return response()->json(['success' => false, 'message' => 'Seul le chef d\'antenne peut transférer une commande au siège.'], 403);
        }

        $data = $request->validate([
            'note' => 'nullable|string|max:1000',
            'date_livraison_prevue' => 'nullable|date',
            'date_livraison_reliquat' => 'nullable|date',
        ]);
        $commande = CommandeTerritoriale::with(['lignes', 'antenne'])->findOrFail($id);
        $antenneUser = $this->antenneIdUtilisateur($user);
        if (!in_array($user->code_role, ['SUPER_ADMIN', 'ADMIN_METIER'], true) && (int) $commande->antenne_id !== (int) $antenneUser) {
            return response()->json(['success' => false, 'message' => 'Cette commande n\'appartient pas à votre antenne.'], 403);
        }
        if (!in_array($commande->statut, ['SOUMISE_ANTENNE', 'EFFECTUEE_CHEF_ANTENNE', 'PARTIELLEMENT_ANTENNE'], true)) {
            return response()->json(['success' => false, 'message' => 'Seules les commandes reçues à l\'antenne (y compris partiellement servies) peuvent être transférées au siège.'], 422);
        }

        $now = now();
        $qteTotale = $commande->lignes->sum(fn ($l) => max(
            0,
            (int) $l->quantite_demandee - (int) ($l->quantite_prise_antenne ?? 0)
        ));
        if ($qteTotale <= 0) {
            return response()->json(['success' => false, 'message' => 'Tout a déjà été servi en antenne — rien à transférer au siège.'], 422);
        }
        $dateLivraison = $data['date_livraison_reliquat'] ?? $data['date_livraison_prevue'] ?? $commande->date_livraison_reliquat ?? $commande->date_livraison_prevue;
        $antenneNom = $commande->antenne?->nom ?? ('Antenne #'.$commande->antenne_id);

        DB::transaction(function () use ($commande, $user, $data, $now, $qteTotale, $antenneNom, $dateLivraison) {
            foreach ($commande->lignes as $ligne) {
                $reste = max(0, (int) $ligne->quantite_demandee - (int) ($ligne->quantite_prise_antenne ?? 0));
                $ligne->update(['quantite_reliquat' => $reste]);
            }

            $commande->update([
                'statut' => 'TRANSFEREE_SIEGE',
                'transferee_au_siege_at' => $now,
                'transferee_au_siege_par_user_id' => $user->id,
                'transferee_au_siege_par_nom' => $user->name,
                'transmise_at' => $commande->transmise_at ?: $now,
                'transmise_par_nom' => $user->name,
                'date_livraison_prevue' => $dateLivraison ?: $commande->date_livraison_prevue,
                'date_livraison_reliquat' => $dateLivraison ?: $commande->date_livraison_reliquat,
            ]);

            FluxTransfertSiege::create([
                'commande_id' => $commande->id,
                'antenne_id' => $commande->antenne_id,
                'user_id' => $user->id,
                'auteur_nom' => $user->name,
                'auteur_role' => $user->role,
                'antenne_nom' => $antenneNom,
                'annee' => (int) $now->format('Y'),
                'mois' => (int) $now->format('n'),
                'nb_lignes' => $commande->lignes->count(),
                'quantite_totale' => $qteTotale,
                'transferee_at' => $now,
                'detail' => $data['note'] ?? 'Transfert de la commande au siège OPN (reste après stock antenne)',
            ]);

            $detail = $data['note']
                ?? 'Transférée au siège par '.$user->name.' (antenne '.$antenneNom.', '.$qteTotale.' unité(s) restantes)'
                    .($dateLivraison ? ', livraison prévue '.$dateLivraison : '');
            $this->journaliser($commande->fresh(), $user, 'TRANSFERT', $detail);
        });

        return response()->json([
            'success' => true,
            'commande' => $this->enrichirCommande($commande->fresh(['antenne', 'lignes', 'journal'])),
        ]);
    }

    /**
     * Prise en charge siège : selon disponibilité (tout ou partie des quantités).
     * Le reliquat reste en attente d'un prochain arrivage.
     */
    public function transmettre(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!in_array($user->code_role, self::ROLES_SIEGE, true)) {
            return response()->json(['success' => false, 'message' => 'Seul le siège peut prendre en charge une commande selon la disponibilité.'], 403);
        }

        $data = $request->validate([
            'lignes' => 'nullable|array',
            'lignes.*.id' => 'required_with:lignes|integer',
            'lignes.*.quantite' => 'required_with:lignes|integer|min:0',
            'tout' => 'nullable|boolean',
            'note' => 'nullable|string|max:1000',
            'date_livraison_reliquat' => 'nullable|date',
            'date_livraison_prevue' => 'nullable|date',
            'urgence_specifique' => 'nullable|boolean',
        ]);

        $commande = CommandeTerritoriale::with('lignes')->findOrFail($id);
        if (!in_array($commande->statut, ['TRANSFEREE_SIEGE', 'TRANSMISE_SIEGE', 'PARTIELLEMENT_TRANSMISE', 'PARTIELLEMENT_ARCHIVEE', 'PARTIELLEMENT_DISPERSEE'], true)) {
            return response()->json(['success' => false, 'message' => 'La commande doit d\'abord être transférée par l\'antenne.'], 422);
        }
        // Prise individuelle hors BC = cas urgent/rare : le flag est obligatoire pour traçabilité + notif chef.
        $urgence = (bool) ($data['urgence_specifique'] ?? false);
        if ($commande->bc_global_id && !$urgence) {
            return response()->json([
                'success' => false,
                'message' => 'Cette demande fait partie d\'un BC global. Prenez en charge le BC global antenne, ou confirmez une prise urgente spécifique.',
                'bc_global_id' => $commande->bc_global_id,
                'requiert_urgence' => true,
            ], 422);
        }

        $selections = collect($data['lignes'] ?? []);
        $prendreTout = (bool) ($data['tout'] ?? false) || $selections->isEmpty();

        $plan = [];
        foreach ($commande->lignes as $row) {
            $priseAntenne = (int) ($row->quantite_prise_antenne ?? 0);
            $reliquat = max(0, (int) $row->quantite_demandee - $priseAntenne - (int) $row->quantite_prise_en_charge);
            if ($reliquat <= 0) {
                continue;
            }
            if ($prendreTout) {
                $qte = $reliquat;
            } else {
                $choix = $selections->firstWhere('id', $row->id);
                $qte = $choix ? min($reliquat, max(0, (int) $choix['quantite'])) : 0;
            }
            if ($qte > 0) {
                $plan[] = ['row' => $row, 'qte' => $qte];
            }
        }

        if ($plan === []) {
            return response()->json(['success' => false, 'message' => 'Indiquez au moins une quantité à prendre en charge.'], 422);
        }

        $totalPris = array_sum(array_column($plan, 'qte'));
        $piecesGenerees = null;

        DB::transaction(function () use ($commande, $plan, $user, $data, $totalPris, $urgence, &$piecesGenerees) {
            foreach ($plan as $item) {
                $row = $item['row'];
                $priseAntenne = (int) ($row->quantite_prise_antenne ?? 0);
                $prise = (int) $row->quantite_prise_en_charge + $item['qte'];
                $row->update([
                    'quantite_prise_en_charge' => $prise,
                    'quantite_reliquat' => max(0, (int) $row->quantite_demandee - $priseAntenne - $prise),
                ]);
            }

            $commande->refresh()->load(['lignes', 'antenne']);
            $statut = $this->calculerStatut($commande);
            $archive = $commande->lignes->sum(fn ($l) => max(
                0,
                (int) $l->quantite_demandee - (int) ($l->quantite_prise_antenne ?? 0) - (int) $l->quantite_prise_en_charge
            ));
            $dateReliquat = $data['date_livraison_reliquat'] ?? $data['date_livraison_prevue'] ?? null;
            if ($archive > 0 && empty($dateReliquat) && empty($commande->date_livraison_reliquat)) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'Indiquez la date de livraison prévue du reliquat.',
                ], 422));
            }
            $maj = [
                'statut' => $statut,
                'prise_en_charge_siege_at' => $commande->prise_en_charge_siege_at ?: now(),
                'prise_en_charge_siege_par_nom' => $user->name,
                'transmise_at' => $commande->transmise_at ?: now(),
                'transmise_par_nom' => $user->name,
            ];
            if ($archive > 0 && $dateReliquat) {
                $maj['date_livraison_reliquat'] = $dateReliquat;
                $maj['date_livraison_prevue'] = $dateReliquat;
            } elseif ($archive <= 0) {
                $maj['date_livraison_reliquat'] = null;
            }
            $commande->update($maj);

            $detail = $data['note']
                ?? ($archive > 0
                    ? "Prise en charge siège : {$totalPris} unité(s). {$archive} unité(s) en reliquat".($dateReliquat ? " (livraison {$dateReliquat})" : '').'.'
                    : "Prise en charge siège complète ({$totalPris} unité(s)).");

            $prise = FluxPriseEnChargeSiege::create([
                'commande_id' => $commande->id,
                'antenne_id' => $commande->antenne_id,
                'user_id' => $user->id,
                'auteur_nom' => $user->name,
                'auteur_role' => $user->role,
                'quantite_prise' => $totalPris,
                'quantite_reliquat' => $archive,
                'statut_apres' => $statut,
                'detail' => $detail,
                'prise_at' => now(),
            ]);

            $piecesGenerees = app(FluxPieceCommercialeService::class)->genererApresPriseEnCharge(
                $commande->fresh(['antenne', 'lignes']),
                $prise,
                $plan,
                $user,
                $totalPris,
                $archive,
                $detail
            );

            $this->journaliser(
                $commande->fresh(),
                $user,
                'PRISE_EN_CHARGE',
                ($urgence ? '[URGENCE SPÉCIFIQUE] ' : '').$detail.' Documents : '.$piecesGenerees['bon_commande']->numero.' / '.$piecesGenerees['facture']->numero
            );
        });

        $commandeFraiche = $commande->fresh(['antenne', 'lignes', 'journal', 'pieces']);
        if ($urgence) {
            $this->notifierChefPriseUrgente($commandeFraiche, $user, $totalPris, $data['note'] ?? null);
        }

        return response()->json([
            'success' => true,
            'commande' => $this->enrichirCommande($commandeFraiche),
            'bon_commande' => $piecesGenerees['bon_commande'] ?? null,
            'facture' => $piecesGenerees['facture'] ?? null,
            'urgence_specifique' => $urgence,
            'chef_notifie' => $urgence,
        ]);
    }

    /** Notifie le(s) chef(s) d'antenne qu'une prise individuelle urgente a été faite hors BC global. */
    private function notifierChefPriseUrgente(CommandeTerritoriale $commande, $user, int $totalPris, ?string $note = null): void
    {
        $antenneId = $commande->antenne_id;
        $titre = 'Prise urgente siège hors BC global';
        $message = sprintf(
            '%s a pris en charge de manière spécifique la demande %s (%s, %d u.)%s. Action urgente hors commande globale antenne.',
            $user->name,
            $commande->numero_commande,
            $commande->demandeur_nom ?: 'demandeur',
            $totalPris,
            $note ? ' — '.$note : ''
        );

        $chefs = User::query()
            ->whereIn('code_role', ['CHEF_ANTENNE_PROVINCIALE', 'PHARMACIEN_CHEF_ANTENNE'])
            ->where(function ($q) use ($antenneId) {
                $q->where('antenne_id', $antenneId)->orWhere('province_id', $antenneId);
            })
            ->get();

        if ($chefs->isEmpty()) {
            FluxNotification::create([
                'destinataire_user_id' => null,
                'antenne_id' => $antenneId,
                'type' => 'PRISE_URGENTE_SIEGE',
                'titre' => $titre,
                'message' => $message,
                'commande_id' => $commande->id,
                'bc_global_id' => $commande->bc_global_id,
                'auteur_nom' => $user->name,
                'auteur_role' => $user->role ?: $user->code_role,
            ]);

            return;
        }

        foreach ($chefs as $chef) {
            FluxNotification::create([
                'destinataire_user_id' => $chef->id,
                'antenne_id' => $antenneId,
                'type' => 'PRISE_URGENTE_SIEGE',
                'titre' => $titre,
                'message' => $message,
                'commande_id' => $commande->id,
                'bc_global_id' => $commande->bc_global_id,
                'auteur_nom' => $user->name,
                'auteur_role' => $user->role ?: $user->code_role,
            ]);
        }
    }

    /**
     * Liste des bons de commande / factures selon le profil :
     * - demandeur : ses pièces
     * - chef d'antenne : pièces des demandeurs de son antenne
     * - siège / DG / admin / super admin : toutes, groupées année → mois → antenne → demandeur
     */
    public function documents(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing(['profil:id,code']);
        $stage = $this->voitToutesLesDemandes($user) ? 'supervision' : $this->stage($user->code_role);

        $query = FluxPieceCommerciale::with(['lignes', 'antenne'])
            ->orderByDesc('emitted_at')
            ->orderByDesc('id');

        if ($request->filled('type') && in_array($request->query('type'), [FluxPieceCommerciale::TYPE_BC, FluxPieceCommerciale::TYPE_FACTURE], true)) {
            $query->where('type', $request->query('type'));
        }

        if (!$this->voitToutesLesDemandes($user) && $stage === 'commande') {
            $query->where('demandeur_user_id', $user->id);
        } elseif (!$this->voitToutesLesDemandes($user) && $stage === 'antenne') {
            $antenneId = $this->antenneIdUtilisateur($user);
            if ($antenneId) {
                $query->where('antenne_id', $antenneId);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if ($request->filled('annee')) {
            $query->where('annee', (int) $request->query('annee'));
        }
        if ($request->filled('mois')) {
            $query->where('mois', (int) $request->query('mois'));
        }
        if ($request->filled('antenne_id')) {
            $query->where('antenne_id', (int) $request->query('antenne_id'));
        }

        $pieces = $query->get();

        $payload = [
            'success' => true,
            'stage' => $stage,
            'compteurs' => [
                'total' => $pieces->count(),
                'bons_commande' => $pieces->where('type', FluxPieceCommerciale::TYPE_BC)->count(),
                'factures' => $pieces->where('type', FluxPieceCommerciale::TYPE_FACTURE)->count(),
            ],
            'pieces' => $pieces,
        ];

        if (in_array($stage, ['siege', 'supervision', 'antenne'], true)) {
            $payload['groupees'] = $this->grouperPieces($pieces);
        }

        return response()->json($payload);
    }

    public function document(Request $request, int $id): JsonResponse
    {
        $user = $request->user()->loadMissing(['profil:id,code']);
        $piece = FluxPieceCommerciale::with(['lignes', 'antenne', 'commande'])->findOrFail($id);

        if (!$this->pieceVisible($user, $piece)) {
            return response()->json(['success' => false, 'message' => 'Ce document n\'est pas accessible à ce profil.'], 403);
        }

        return response()->json(['success' => true, 'piece' => $piece]);
    }

    public function documentPdf(Request $request, int $id, FluxPiecePdfService $pdfService): Response|JsonResponse
    {
        $user = $request->user()->loadMissing(['profil:id,code']);
        $piece = FluxPieceCommerciale::with(['lignes', 'antenne'])->findOrFail($id);

        if (!$this->pieceVisible($user, $piece)) {
            return response()->json(['success' => false, 'message' => 'Ce document n\'est pas accessible à ce profil.'], 403);
        }

        return $pdfService->telecharger($piece);
    }

    /**
     * Dispersion siège : tout ou une partie des quantités déjà prises en charge.
     * Le solde reste ouvert pour une dispersion ultérieure.
     */
    public function disperser(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!in_array($user->code_role, self::ROLES_SIEGE, true)) {
            return response()->json(['success' => false, 'message' => 'Seul le siège peut disperser une commande.'], 403);
        }

        $data = $request->validate([
            'note_dispersion' => 'nullable|string|max:1000',
            'lignes' => 'nullable|array',
            'lignes.*.id' => 'required_with:lignes|integer',
            'lignes.*.quantite' => 'nullable|integer|min:0',
            'lignes.*.quantite_dispersee' => 'nullable|integer|min:0',
            'tout' => 'nullable|boolean',
        ]);

        $commande = CommandeTerritoriale::with('lignes')->findOrFail($id);
        if (!in_array($commande->statut, ['TRANSMISE_SIEGE', 'PARTIELLEMENT_TRANSMISE', 'PARTIELLEMENT_DISPERSEE', 'PARTIELLEMENT_ARCHIVEE'], true)) {
            return response()->json(['success' => false, 'message' => 'La commande doit d\'abord être prise en charge par le siège.'], 422);
        }

        $selections = collect($data['lignes'] ?? []);
        $disperserTout = (bool) ($data['tout'] ?? false) || $selections->isEmpty();

        $plan = [];
        foreach ($commande->lignes as $row) {
            $reste = max(0, (int) $row->quantite_prise_en_charge - (int) $row->quantite_dispersee);
            if ($reste <= 0) {
                continue;
            }
            if ($disperserTout) {
                $qte = $reste;
            } else {
                $choix = $selections->firstWhere('id', $row->id);
                if (!$choix) {
                    continue;
                }
                // `quantite` = vague actuelle ; `quantite_dispersee` = compat ancien front (cumul voulu).
                if (array_key_exists('quantite', $choix) && $choix['quantite'] !== null) {
                    $qte = min($reste, max(0, (int) $choix['quantite']));
                } else {
                    $cible = min((int) $row->quantite_prise_en_charge, max((int) $row->quantite_dispersee, (int) ($choix['quantite_dispersee'] ?? 0)));
                    $qte = $cible - (int) $row->quantite_dispersee;
                }
            }
            if ($qte > 0) {
                $plan[] = ['row' => $row, 'qte' => $qte];
            }
        }

        if ($plan === []) {
            return response()->json(['success' => false, 'message' => 'Indiquez au moins une quantité à disperser.'], 422);
        }

        $totalDisperse = array_sum(array_column($plan, 'qte'));

        DB::transaction(function () use ($commande, $data, $user, $plan, $totalDisperse) {
            foreach ($plan as $item) {
                $row = $item['row'];
                $row->update(['quantite_dispersee' => (int) $row->quantite_dispersee + $item['qte']]);
            }

            $commande->refresh()->load('lignes');
            $statut = $this->calculerStatut($commande);
            $commande->update([
                'statut' => $statut,
                'dispersee_at' => now(),
                'dispersee_par_nom' => $user->name,
                'note_dispersion' => $data['note_dispersion'] ?? $commande->note_dispersion,
            ]);

            $detail = $data['note_dispersion']
                ?? ($statut === 'DISPERSEE'
                    ? 'Dispersion complète'
                    : "Dispersion partielle ({$totalDisperse} unité(s)) — solde pour arrivage ultérieur");
            $this->journaliser($commande->fresh(), $user, 'DISPERSION', $detail);
        });

        $fraiche = $this->enrichirCommande($commande->fresh(['antenne', 'lignes', 'journal', 'pieces.lignes']));

        return response()->json(['success' => true, 'commande' => $fraiche]);
    }

    public function rejeter(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['motif' => 'required|string|max:500']);
        $commande = CommandeTerritoriale::findOrFail($id);
        $stage = $this->stage($user->code_role);

        if ($stage === 'antenne' && in_array($commande->statut, ['SOUMISE_ANTENNE', 'EFFECTUEE_CHEF_ANTENNE'], true)) {
            $statut = 'REJETEE_ANTENNE';
        } elseif (in_array($stage, ['siege', 'supervision'], true) && in_array($commande->statut, ['TRANSFEREE_SIEGE', 'TRANSMISE_SIEGE', 'PARTIELLEMENT_DISPERSEE', 'PARTIELLEMENT_TRANSMISE', 'PARTIELLEMENT_ARCHIVEE'], true)) {
            $statut = 'REJETEE_SIEGE';
        } else {
            return response()->json(['success' => false, 'message' => 'Cette commande ne peut pas être rejetée à cette étape.'], 422);
        }

        $commande->update(['statut' => $statut]);
        $this->journaliser($commande, $user, 'REJET', $data['motif']);

        return response()->json(['success' => true, 'commande' => $commande->load(['antenne', 'lignes', 'journal'])]);
    }

    /** Déduit le statut global à partir des quantités ligne + transfert. */
    private function calculerStatut(CommandeTerritoriale $commande): string
    {
        $lignes = $commande->lignes;
        $reliquat = $lignes->sum(fn ($l) => max(
            0,
            (int) $l->quantite_demandee - (int) ($l->quantite_prise_antenne ?? 0) - (int) $l->quantite_prise_en_charge
        ));
        $prise = $lignes->sum(fn ($l) => (int) $l->quantite_prise_en_charge);
        $dispersee = $lignes->sum(fn ($l) => (int) $l->quantite_dispersee);
        $resteDispersion = $lignes->sum(fn ($l) => max(0, (int) $l->quantite_prise_en_charge - (int) $l->quantite_dispersee));
        $dejaTransferee = $commande->transferee_au_siege_at
            || in_array($commande->statut, ['TRANSFEREE_SIEGE', 'TRANSMISE_SIEGE', 'PARTIELLEMENT_ARCHIVEE', 'PARTIELLEMENT_DISPERSEE', 'DISPERSEE'], true);

        if ($prise <= 0) {
            if ($dejaTransferee) {
                return 'TRANSFEREE_SIEGE';
            }

            return $commande->statut === 'EFFECTUEE_CHEF_ANTENNE' ? 'EFFECTUEE_CHEF_ANTENNE' : 'SOUMISE_ANTENNE';
        }
        if ($dispersee > 0 && $resteDispersion <= 0 && $reliquat <= 0) {
            return 'DISPERSEE';
        }
        if ($dispersee > 0) {
            return 'PARTIELLEMENT_DISPERSEE';
        }
        if ($reliquat > 0) {
            return 'PARTIELLEMENT_ARCHIVEE';
        }

        return 'TRANSMISE_SIEGE';
    }

    /** Compteurs selon le stage (antenne vs siège). */
    private function compteurs($commandes, string $stage): array
    {
        $base = [
            'total' => $commandes->count(),
            'recues' => $commandes->whereIn('statut', ['SOUMISE_ANTENNE', 'EFFECTUEE_CHEF_ANTENNE'])->count(),
            'transferees' => $commandes->whereIn('statut', ['TRANSFEREE_SIEGE', 'TRANSMISE_SIEGE', 'PARTIELLEMENT_ARCHIVEE', 'PARTIELLEMENT_DISPERSEE', 'DISPERSEE'])->count(),
            'en_attente' => $commandes->where('statut', 'TRANSFEREE_SIEGE')->count(),
            'satisfaites' => $commandes->where('statut', 'DISPERSEE')->count(),
            'reliquats' => $commandes->where('statut', 'PARTIELLEMENT_ARCHIVEE')->count(),
            'dispersees' => $commandes->where('statut', 'DISPERSEE')->count(),
            'archivees' => $commandes->where('statut', 'PARTIELLEMENT_ARCHIVEE')->count(),
            'transmises' => $commandes->whereIn('statut', ['TRANSFEREE_SIEGE', 'TRANSMISE_SIEGE', 'PARTIELLEMENT_DISPERSEE', 'PARTIELLEMENT_ARCHIVEE'])->count(),
        ];

        return $base;
    }

    /** Regroupe les commandes du siège : année → mois → antenne → demandeur (ou l'antenne elle-même). */
    private function grouperPourSiege($commandes): array
    {
        $siege = $commandes->filter(fn ($c) => in_array($c->statut, [
            'TRANSFEREE_SIEGE', 'TRANSMISE_SIEGE', 'PARTIELLEMENT_ARCHIVEE',
            'PARTIELLEMENT_TRANSMISE', 'PARTIELLEMENT_DISPERSEE', 'DISPERSEE', 'REJETEE_SIEGE',
        ], true));

        return $siege
            ->groupBy(function ($c) {
                $ref = $c->transferee_au_siege_at ?: $c->created_at;

                return $ref ? (int) $ref->format('Y') : (int) now()->format('Y');
            })
            ->map(function ($cmdsAnnee, $annee) {
                $mois = $cmdsAnnee->groupBy(function ($c) {
                    $ref = $c->transferee_au_siege_at ?: $c->created_at;

                    return $ref ? (int) $ref->format('n') : (int) now()->format('n');
                })->map(function ($cmdsMois, $moisNum) {
                    $antennes = $cmdsMois->groupBy(fn ($c) => (string) ($c->antenne_id ?? 0))
                        ->map(function ($cmdsAntenne, $antenneId) {
                            $premiere = $cmdsAntenne->first();
                            $origines = $cmdsAntenne->groupBy(function ($c) {
                                if (($c->partenaire_type ?? '') === 'DIRECTION_GENERALE') {
                                    return 'direction_generale';
                                }
                                if (($c->partenaire_type ?? '') === 'ANTENNE') {
                                    return 'antenne';
                                }

                                return 'demandeur:'.($c->demandeur_user_id ?: mb_strtolower((string) $c->demandeur_nom));
                            })->map(function ($cmdsOrigine, $cle) {
                                $premiereOrigine = $cmdsOrigine->first();
                                $parDg = $cle === 'direction_generale';
                                $parAntenne = $cle === 'antenne';

                                return [
                                    'cle' => (string) $cle,
                                    'type' => $parDg ? 'direction_generale' : ($parAntenne ? 'antenne' : 'demandeur'),
                                    'nom' => $parDg ? 'Direction générale' : ($premiereOrigine->demandeur_nom ?: ($parAntenne ? 'Antenne' : 'Demandeur')),
                                    'role' => $premiereOrigine->demandeur_role,
                                    'partenaire_nom' => $premiereOrigine->partenaire_nom,
                                    'nb_commandes' => $cmdsOrigine->count(),
                                    'commandes' => $cmdsOrigine->values(),
                                ];
                            })->sortBy(fn ($origine) => match ($origine['type']) {
                                'direction_generale' => '0',
                                'antenne' => '1',
                                default => '2',
                            }.$origine['nom'])->values();

                            return [
                                'antenne_id' => (int) $antenneId,
                                'antenne_nom' => $premiere->antenne->nom ?? ('Antenne #'.$antenneId),
                                'chef_lieu' => $premiere->antenne->chef_lieu ?? null,
                                'transferee_par' => $cmdsAntenne->pluck('transferee_au_siege_par_nom')->filter()->unique()->values()->all(),
                                'nb_commandes' => $cmdsAntenne->count(),
                                'en_attente' => $cmdsAntenne->where('statut', 'TRANSFEREE_SIEGE')->count(),
                                'reliquats' => $cmdsAntenne->where('statut', 'PARTIELLEMENT_ARCHIVEE')->count(),
                                'satisfaites' => $cmdsAntenne->where('statut', 'DISPERSEE')->count(),
                                'origines' => $origines,
                            ];
                        })
                        ->sortBy('antenne_nom')
                        ->values();

                    return [
                        'mois' => (int) $moisNum,
                        'libelle' => $this->libelleMois((int) $moisNum),
                        'nb_commandes' => $cmdsMois->count(),
                        'antennes' => $antennes,
                    ];
                })->sortByDesc('mois')->values();

                return [
                    'annee' => (int) $annee,
                    'nb_commandes' => $cmdsAnnee->count(),
                    'mois' => $mois,
                ];
            })
            ->sortByDesc('annee')
            ->values()
            ->all();
    }

    private function libelleMois(int $mois): string
    {
        $noms = [1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
            7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'];

        return $noms[$mois] ?? (string) $mois;
    }

    /** Regroupe BC/factures : année → mois → antenne → demandeur. */
    private function grouperPieces($pieces): array
    {
        return $pieces
            ->groupBy(fn ($p) => (int) ($p->annee ?: now()->year))
            ->map(function ($cmdsAnnee, $annee) {
                $mois = $cmdsAnnee->groupBy(fn ($p) => (int) ($p->mois ?: 1))
                    ->map(function ($cmdsMois, $numeroMois) {
                        $antennes = $cmdsMois->groupBy(fn ($p) => (string) ($p->antenne_id ?: '0'))
                            ->map(function ($cmdsAntenne) {
                                $first = $cmdsAntenne->first();
                                $demandeurs = $cmdsAntenne->groupBy(fn ($p) => (string) ($p->demandeur_user_id ?: $p->demandeur_nom ?: 'n/a'))
                                    ->map(function ($cmdsDem) {
                                        $d = $cmdsDem->first();

                                        return [
                                            'demandeur_user_id' => $d->demandeur_user_id,
                                            'demandeur_nom' => $d->demandeur_nom,
                                            'partenaire_nom' => $d->partenaire_nom,
                                            'nb_pieces' => $cmdsDem->count(),
                                            'bons_commande' => $cmdsDem->where('type', FluxPieceCommerciale::TYPE_BC)->values(),
                                            'factures' => $cmdsDem->where('type', FluxPieceCommerciale::TYPE_FACTURE)->values(),
                                            'pieces' => $cmdsDem->values(),
                                        ];
                                    })
                                    ->sortBy('demandeur_nom')
                                    ->values();

                                return [
                                    'antenne_id' => $first->antenne_id,
                                    'antenne_nom' => $first->antenne_nom ?: ($first->antenne?->nom ?? 'Antenne'),
                                    'nb_pieces' => $cmdsAntenne->count(),
                                    'demandeurs' => $demandeurs,
                                ];
                            })
                            ->sortBy('antenne_nom')
                            ->values();

                        return [
                            'mois' => (int) $numeroMois,
                            'libelle' => $this->libelleMois((int) $numeroMois),
                            'nb_pieces' => $cmdsMois->count(),
                            'antennes' => $antennes,
                        ];
                    })
                    ->sortByDesc('mois')
                    ->values();

                return [
                    'annee' => (int) $annee,
                    'nb_pieces' => $cmdsAnnee->count(),
                    'mois' => $mois,
                ];
            })
            ->sortByDesc('annee')
            ->values()
            ->all();
    }

    private function pieceVisible($user, FluxPieceCommerciale $piece): bool
    {
        if ($this->voitToutesLesDemandes($user)) {
            return true;
        }

        $stage = $this->stage($user->code_role);
        if ($stage === 'commande') {
            return (int) $piece->demandeur_user_id === (int) $user->id;
        }
        if ($stage === 'antenne') {
            $antenneId = $this->antenneIdUtilisateur($user);

            return $antenneId && (int) $piece->antenne_id === (int) $antenneId;
        }

        return true;
    }

    /** Ajoute la traçabilité et les totaux quantités à une commande. */
    private function enrichirCommande(CommandeTerritoriale $commande): CommandeTerritoriale
    {
        $lignes = $commande->lignes ?? collect();
        $demande = $lignes->sum(fn ($l) => (int) $l->quantite_demandee);
        $prise = $lignes->sum(fn ($l) => (int) $l->quantite_prise_en_charge);
        $dispersee = $lignes->sum(fn ($l) => (int) $l->quantite_dispersee);
        $priseAntenne = $lignes->sum(fn ($l) => (int) ($l->quantite_prise_antenne ?? 0));
        $reliquat = max(0, $demande - $priseAntenne - $prise);

        $commande->setAttribute('tracabilite', [
            'initiee_par' => $commande->demandeur_nom,
            'initiee_role' => $commande->demandeur_role,
            'partenaire' => $commande->partenaire_nom,
            'antenne' => $commande->antenne?->nom,
            'antenne_chef_lieu' => $commande->antenne?->chef_lieu,
            'transferee_par' => $commande->transferee_au_siege_par_nom,
            'transferee_at' => $commande->transferee_au_siege_at,
            'prise_en_charge_par' => $commande->prise_en_charge_siege_par_nom,
            'prise_en_charge_at' => $commande->prise_en_charge_siege_at,
            'prise_antenne_par' => $commande->prise_en_charge_antenne_par_nom,
            'prise_antenne_at' => $commande->prise_en_charge_antenne_at,
            'dispersee_par' => $commande->dispersee_par_nom,
            'dispersee_at' => $commande->dispersee_at,
        ]);
        $commande->setAttribute('initiee_par_antenne', ($commande->partenaire_type ?? '') === 'ANTENNE');
        $commande->setAttribute('initiee_par_direction_generale', ($commande->partenaire_type ?? '') === 'DIRECTION_GENERALE');
        $commande->setAttribute('quantites', [
            'demandee' => $demande,
            'prise_antenne' => $priseAntenne,
            'prise' => $prise,
            'reliquat' => $reliquat,
            'dispersee' => $dispersee,
        ]);
        $commande->setAttribute('categorie', $commande->categorie);
        $commande->setAttribute('kit_medical', $commande->kit_medical);
        $commande->setAttribute('date_livraison_prevue', optional($commande->date_livraison_prevue)->format('Y-m-d'));
        $commande->setAttribute('date_livraison_reliquat', optional($commande->date_livraison_reliquat)->format('Y-m-d'));
        $commande->setAttribute('bc_global_id', $commande->bc_global_id);

        if ($commande->relationLoaded('pieces')) {
            $commande->setAttribute('bons_commande', $commande->pieces->where('type', FluxPieceCommerciale::TYPE_BC)->values());
            $commande->setAttribute('factures', $commande->pieces->where('type', FluxPieceCommerciale::TYPE_FACTURE)->values());
        }

        return $commande;
    }

    /** Supprime une commande visible par le profil, si la case Supprimer est cochée sur le flux. */
    public function supprimer(Request $request, int $id): JsonResponse
    {
        $user = $request->user()->loadMissing('profil:id,code');
        $commande = CommandeTerritoriale::findOrFail($id);
        $retraitDemandeur = $this->demandeurPeutEditer($user, $commande);
        if (!$this->peutSupprimer($user) && !$retraitDemandeur) {
            return response()->json(['success' => false, 'message' => 'Ce profil n\'a pas le droit de supprimer une commande.'], 403);
        }

        if (!$this->commandeVisible($user, $commande)) {
            return response()->json(['success' => false, 'message' => 'Cette commande n\'est pas accessible à ce profil.'], 403);
        }

        $commande->delete();

        return response()->json(['success' => true]);
    }

    private function journaliser(CommandeTerritoriale $commande, $user, string $action, ?string $detail = null): void
    {
        $commande->journal()->create([
            'user_id' => $user->id,
            'antenne_id' => $commande->antenne_id,
            'auteur_nom' => $user->name,
            'auteur_role' => $user->role,
            'action' => $action,
            'statut' => $commande->statut,
            'detail' => $detail,
        ]);
    }

    /** Le demandeur corrige ou retire sa demande tant qu'elle est encore à l'antenne. */
    private function demandeurPeutEditer($user, CommandeTerritoriale $commande): bool
    {
        return in_array($user->code_role, ['DEMANDEUR_PHARMACIEN', 'DEMANDEUR_STRUCTURE'], true)
            && (int) $commande->demandeur_user_id === (int) $user->id
            && in_array($commande->statut, ['BROUILLON', 'SOUMISE_ANTENNE'], true);
    }

    /** Regroupe les demandes antenne par demandeur (pharmacie / structure). */
    private function grouperParDemandeurs($commandes): array
    {
        return $commandes
            ->groupBy(fn ($c) => (string) ($c->demandeur_user_id ?: $c->demandeur_nom ?: 'n/a'))
            ->map(function ($cmds) {
                $first = $cmds->first();
                return [
                    'demandeur_user_id' => $first->demandeur_user_id,
                    'demandeur_nom' => $first->demandeur_nom,
                    'demandeur_role' => $first->demandeur_role,
                    'partenaire_nom' => $first->partenaire_nom,
                    'nb_commandes' => $cmds->count(),
                    'a_transferer' => $cmds->whereIn('statut', ['SOUMISE_ANTENNE', 'EFFECTUEE_CHEF_ANTENNE', 'PARTIELLEMENT_ANTENNE'])->count(),
                    'commandes' => $cmds->values(),
                ];
            })
            ->sortBy('demandeur_nom')
            ->values()
            ->all();
    }

    /** La case Supprimer de l'habilitation flux autorise la suppression des commandes. */
    private function peutSupprimer($user): bool
    {
        $user->loadMissing('profil.habilitations');
        $habilitations = $user->profil?->habilitations;
        if (!$habilitations) {
            return false;
        }

        return $habilitations
            ->whereIn('code', ['flux', 'demandes'])
            ->contains(fn ($habilitation) => (bool) $habilitation->pivot->supprimer);
    }

    /** Même périmètre que la liste : ses commandes, celles de son antenne, ou toutes. */
    private function commandeVisible($user, CommandeTerritoriale $commande): bool
    {
        if ($this->voitToutesLesDemandes($user)) {
            return true;
        }

        $stage = $this->stage($user->code_role);
        if ($stage === 'commande') {
            return (int) $commande->demandeur_user_id === (int) $user->id;
        }
        if ($stage === 'antenne') {
            $antenneId = $this->antenneIdUtilisateur($user);

            return $antenneId && (int) $commande->antenne_id === (int) $antenneId;
        }

        return true;
    }

    /** Antenne rattachée au compte, celle choisie à la création du profil. */
    private function antenneDuCompte($user): ?AntenneProvinciale
    {
        $id = $this->antenneIdUtilisateur($user);

        return $id ? AntenneProvinciale::find($id) : null;
    }

    /** Antenne rattachée au compte (antenne_id prioritaire, sinon province_id historique). */
    private function antenneIdUtilisateur($user): ?int
    {
        $id = $user->antenne_id ?: $user->province_id;

        return $id ? (int) $id : null;
    }

    /** L'admin métier et le super administrateur consultent toutes les demandes, quelle que soit l'antenne. */
    private function voitToutesLesDemandes($user): bool
    {
        return in_array($user->code_role, ['SUPER_ADMIN', 'ADMIN_METIER'], true)
            || in_array($user->profil?->code, ['sa', 'admin_metier', 'dsi'], true);
    }

    private function stage(?string $codeRole): string
    {
        if (in_array($codeRole, ['SUPER_ADMIN', 'ADMIN_METIER'], true)) {
            return 'supervision';
        }
        if (in_array($codeRole, ['DEMANDEUR_PHARMACIEN', 'DEMANDEUR_STRUCTURE'], true)) {
            return 'commande';
        }
        if (in_array($codeRole, ['CHEF_ANTENNE_PROVINCIALE', 'PHARMACIEN_CHEF_ANTENNE'], true)) {
            return 'antenne';
        }
        if (in_array($codeRole, ['APPRO_NATIONAL', 'COLLABORATEUR_APPRO', 'DG', 'DIR', 'DAF', 'ACHATS', 'PHARMACIEN_CHEF_NATIONAL'], true)) {
            return 'siege';
        }

        return 'suivi';
    }
}
