<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommandeTerritoriale;
use App\Models\FluxBcGlobal;
use App\Models\FluxBcGlobalLigne;
use App\Models\FluxBonLivraison;
use App\Models\LotProduit;
use App\Models\ProduitPharmaceutique;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extensions MAJ 2026 : brouillon, prise antenne, BC global, stock, BL signé.
 */
class FluxCircuitController extends Controller
{
    private const ROLES_COMMANDE = ['DEMANDEUR_PHARMACIEN', 'DEMANDEUR_STRUCTURE', 'SUPER_ADMIN', 'ADMIN_METIER'];
    private const ROLES_ANTENNE = ['CHEF_ANTENNE_PROVINCIALE', 'PHARMACIEN_CHEF_ANTENNE', 'SUPER_ADMIN', 'ADMIN_METIER'];
    private const ROLES_SIEGE = ['APPRO_NATIONAL', 'COLLABORATEUR_APPRO', 'DG', 'DIR', 'DAF', 'ACHATS', 'PHARMACIEN_CHEF_NATIONAL', 'SUPER_ADMIN', 'ADMIN_METIER'];

    /** Envoie une demande brouillon au chef d'antenne. */
    public function envoyer(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $commande = CommandeTerritoriale::with('lignes')->findOrFail($id);

        if ((int) $commande->demandeur_user_id !== (int) $user->id
            && !in_array($user->code_role, ['SUPER_ADMIN', 'ADMIN_METIER'], true)) {
            return response()->json(['success' => false, 'message' => 'Seul le demandeur peut envoyer cette demande.'], 403);
        }
        if ($commande->statut !== 'BROUILLON') {
            return response()->json(['success' => false, 'message' => 'Seule une demande en brouillon peut être envoyée.'], 422);
        }
        if ($commande->lignes->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Ajoutez au moins un médicament avant l\'envoi.'], 422);
        }

        $commande->update(['statut' => 'SOUMISE_ANTENNE']);
        $commande->journal()->create([
            'user_id' => $user->id,
            'antenne_id' => $commande->antenne_id,
            'auteur_nom' => $user->name,
            'auteur_role' => $user->role,
            'action' => 'ENVOI',
            'statut' => 'SOUMISE_ANTENNE',
            'detail' => 'Demande envoyée au chef d\'antenne',
        ]);

        return response()->json(['success' => true, 'commande' => $commande->fresh(['antenne', 'lignes', 'journal'])]);
    }

    /** Stock de l'antenne du chef (lots FEFO) + alertes rupture. */
    public function stockAntenne(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!in_array($user->code_role, self::ROLES_ANTENNE, true)) {
            return response()->json(['success' => false, 'message' => 'Réservé au chef d\'antenne.'], 403);
        }

        $antenneId = $user->antenne_id ?: $user->province_id;
        if (!$antenneId) {
            return response()->json(['success' => true, 'lots' => [], 'alertes_rupture' => [], 'date_livraison_prevue' => null]);
        }

        $lots = LotProduit::query()
            ->with('produit:id,designation,categorie,code_produit,seuil_critique_national,seuil_min_national')
            ->where('antenne_id', $antenneId)
            ->orderBy('date_peremption')
            ->get()
            ->map(function (LotProduit $lot) {
                $qte = (int) ($lot->quantite_disponible ?? 0);
                $seuil = (int) ($lot->produit?->seuil_critique_national ?? 0);
                return [
                    'id' => $lot->id,
                    'produit_id' => $lot->produit_id,
                    'designation' => $lot->produit?->designation ?: $lot->designation,
                    'categorie' => $lot->produit?->categorie,
                    'numero_lot' => $lot->numero_lot,
                    'quantite_disponible' => $qte,
                    'date_peremption' => $lot->date_peremption,
                    'rupture' => $qte <= 0,
                    'critique' => $seuil > 0 && $qte > 0 && $qte <= $seuil,
                ];
            });

        $alertes = $lots->filter(fn ($l) => $l['rupture'] || $l['critique'])->values();

        $prochaineLivraison = CommandeTerritoriale::query()
            ->where('antenne_id', $antenneId)
            ->whereNotNull('date_livraison_prevue')
            ->where('date_livraison_prevue', '>=', now()->toDateString())
            ->orderBy('date_livraison_prevue')
            ->value('date_livraison_prevue');

        return response()->json([
            'success' => true,
            'antenne_id' => $antenneId,
            'lots' => $lots,
            'alertes_rupture' => $alertes,
            'date_livraison_prevue' => $prochaineLivraison,
        ]);
    }

    /** Prise en charge partielle depuis le stock antenne. */
    public function priseAntenne(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!in_array($user->code_role, self::ROLES_ANTENNE, true)) {
            return response()->json(['success' => false, 'message' => 'Seul le chef d\'antenne peut prendre en charge depuis le stock local.'], 403);
        }

        $data = $request->validate([
            'lignes' => 'required|array|min:1',
            'lignes.*.id' => 'required|integer',
            'lignes.*.quantite' => 'required|integer|min:0',
            'note' => 'nullable|string|max:1000',
            'date_livraison_reliquat' => 'nullable|date',
            'date_livraison_prevue' => 'nullable|date',
        ]);

        $commande = CommandeTerritoriale::with('lignes')->findOrFail($id);
        $antenneId = $user->antenne_id ?: $user->province_id;
        if ((int) $commande->antenne_id !== (int) $antenneId
            && !in_array($user->code_role, ['SUPER_ADMIN', 'ADMIN_METIER'], true)) {
            return response()->json(['success' => false, 'message' => 'Cette demande n\'appartient pas à votre antenne.'], 403);
        }
        if (!in_array($commande->statut, ['SOUMISE_ANTENNE', 'EFFECTUEE_CHEF_ANTENNE', 'PARTIELLEMENT_ANTENNE'], true)) {
            return response()->json(['success' => false, 'message' => 'Cette demande n\'est plus à l\'étape antenne.'], 422);
        }

        $resultat = DB::transaction(function () use ($commande, $data, $user) {
            $totalPris = 0;
            $totalDemande = 0;
            foreach ($data['lignes'] as $item) {
                $ligne = $commande->lignes->firstWhere('id', (int) $item['id']);
                if (!$ligne) {
                    continue;
                }
                $reste = max(0, (int) $ligne->quantite_demandee - (int) ($ligne->quantite_prise_antenne ?? 0));
                $qteDemandee = min($reste, (int) $item['quantite']);
                if ($qteDemandee <= 0) {
                    continue;
                }
                $qte = $qteDemandee;
                if ($ligne->produit_id) {
                    $qte = $this->debiterStockAntenne((int) $commande->antenne_id, (int) $ligne->produit_id, $qteDemandee);
                }
                if ($qte <= 0) {
                    continue;
                }
                $priseAntenne = (int) ($ligne->quantite_prise_antenne ?? 0) + $qte;
                $reliquat = max(0, (int) $ligne->quantite_demandee - $priseAntenne);
                $ligne->update([
                    'quantite_prise_antenne' => $priseAntenne,
                    'quantite_reliquat' => $reliquat,
                ]);
                $totalPris += $qte;
            }

            $commande->refresh()->load('lignes');
            $demande = $commande->lignes->sum(fn ($l) => (int) $l->quantite_demandee);
            $priseAntenne = $commande->lignes->sum(fn ($l) => (int) ($l->quantite_prise_antenne ?? 0));
            $reliquat = max(0, $demande - $priseAntenne);
            $statut = $reliquat <= 0 ? 'SATISFAITE_ANTENNE' : 'PARTIELLEMENT_ANTENNE';

            $dateReliquat = $data['date_livraison_reliquat'] ?? $data['date_livraison_prevue'] ?? null;
            if ($reliquat > 0 && empty($dateReliquat)) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'Indiquez la date de livraison prévue du reliquat (stock insuffisant).',
                ], 422));
            }

            $maj = [
                'statut' => $statut,
                'prise_en_charge_antenne_at' => now(),
                'prise_en_charge_antenne_par_nom' => $user->name,
            ];
            if ($reliquat > 0 && $dateReliquat) {
                $maj['date_livraison_reliquat'] = $dateReliquat;
                $maj['date_livraison_prevue'] = $dateReliquat;
            } elseif ($reliquat <= 0) {
                $maj['date_livraison_reliquat'] = null;
            }
            $commande->update($maj);

            $detail = ($data['note'] ?? null)
                ?: ("Prise stock antenne : {$totalPris} unité(s)"
                    .($reliquat > 0 ? " · reliquat {$reliquat} prévu le {$dateReliquat}" : ' · satisfaite localement'));
            $commande->journal()->create([
                'user_id' => $user->id,
                'antenne_id' => $commande->antenne_id,
                'auteur_nom' => $user->name,
                'auteur_role' => $user->role,
                'action' => 'PRISE_ANTENNE',
                'statut' => $statut,
                'detail' => $detail,
            ]);

            return ['total_pris' => $totalPris, 'reliquat' => $reliquat, 'demande' => $demande];
        });

        if (($resultat['total_pris'] ?? 0) <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Stock insuffisant : aucune quantité n\'a pu être débitée.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'commande' => $commande->fresh(['antenne', 'lignes', 'journal']),
            'prise' => $resultat,
        ]);
    }

    /** Entrée de stock (lot FEFO) pour l'antenne du chef. */
    public function entreeStockAntenne(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!in_array($user->code_role, self::ROLES_ANTENNE, true)) {
            return response()->json(['success' => false, 'message' => 'Réservé au chef d\'antenne.'], 403);
        }
        if (!Schema::hasTable('lots_produits')) {
            return response()->json(['success' => false, 'message' => 'Table des lots indisponible.'], 422);
        }

        $data = $request->validate([
            'produit_id' => 'required|integer|exists:produits_pharmaceutiques,id',
            'quantite' => 'required|integer|min:1',
            'numero_lot' => 'nullable|string|max:80',
            'date_peremption' => 'nullable|date',
            'laboratoire_fabricant' => 'nullable|string|max:120',
        ]);

        $antenneId = $user->antenne_id ?: $user->province_id;
        $produit = ProduitPharmaceutique::findOrFail($data['produit_id']);
        $lot = LotProduit::create([
            'produit_id' => $produit->id,
            'antenne_id' => $antenneId,
            'numero_lot' => $data['numero_lot'] ?: ('LOT-'.now()->format('YmdHis')),
            'quantite_disponible' => (int) $data['quantite'],
            'quantite_reservee' => 0,
            'date_peremption' => $data['date_peremption'] ?? now()->addYear()->toDateString(),
            'laboratoire_fabricant' => $data['laboratoire_fabricant'] ?? null,
            'statut_fefo' => 'ACTIF',
        ]);

        return response()->json([
            'success' => true,
            'lot' => [
                'id' => $lot->id,
                'produit_id' => $lot->produit_id,
                'designation' => $produit->designation,
                'numero_lot' => $lot->numero_lot,
                'quantite_disponible' => (int) $lot->quantite_disponible,
                'date_peremption' => optional($lot->date_peremption)->format('Y-m-d'),
            ],
        ], 201);
    }

    /** Ajuste la quantité d'un lot de l'antenne (inventaire / correction). */
    public function ajusterStockAntenne(Request $request, int $lotId): JsonResponse
    {
        $user = $request->user();
        if (!in_array($user->code_role, self::ROLES_ANTENNE, true)) {
            return response()->json(['success' => false, 'message' => 'Réservé au chef d\'antenne.'], 403);
        }

        $data = $request->validate([
            'quantite_disponible' => 'required|integer|min:0',
            'motif' => 'nullable|string|max:255',
        ]);

        $antenneId = $user->antenne_id ?: $user->province_id;
        $lot = LotProduit::with('produit:id,designation')->findOrFail($lotId);
        if ((int) $lot->antenne_id !== (int) $antenneId
            && !in_array($user->code_role, ['SUPER_ADMIN', 'ADMIN_METIER'], true)) {
            return response()->json(['success' => false, 'message' => 'Ce lot n\'appartient pas à votre antenne.'], 403);
        }

        $avant = (int) $lot->quantite_disponible;
        $lot->update(['quantite_disponible' => (int) $data['quantite_disponible']]);

        return response()->json([
            'success' => true,
            'lot' => [
                'id' => $lot->id,
                'produit_id' => $lot->produit_id,
                'designation' => $lot->produit?->designation,
                'numero_lot' => $lot->numero_lot,
                'quantite_disponible' => (int) $lot->quantite_disponible,
                'avant' => $avant,
                'motif' => $data['motif'] ?? null,
            ],
        ]);
    }

    /**
     * Constitue un BC global pour les demandes sélectionnées.
     * Le détail par demandeur reste en antenne ; le transfert siège n'envoie que l'agrégat.
     */
    public function creerBcGlobal(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!in_array($user->code_role, self::ROLES_ANTENNE, true)) {
            return response()->json(['success' => false, 'message' => 'Seul le chef d\'antenne peut constituer le BC global.'], 403);
        }

        $data = $request->validate([
            'commande_ids' => 'required|array|min:1',
            'commande_ids.*' => 'integer|exists:commandes_territoriales,id',
            'date_livraison_prevue' => 'nullable|date',
            'note' => 'nullable|string|max:1000',
        ]);

        $antenneId = $user->antenne_id ?: $user->province_id;
        $commandes = CommandeTerritoriale::with(['lignes', 'antenne'])
            ->whereIn('id', $data['commande_ids'])
            ->whereIn('statut', ['SOUMISE_ANTENNE', 'EFFECTUEE_CHEF_ANTENNE', 'PARTIELLEMENT_ANTENNE'])
            ->when(!in_array($user->code_role, ['SUPER_ADMIN', 'ADMIN_METIER'], true), fn ($q) => $q->where('antenne_id', $antenneId))
            ->get();

        if ($commandes->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Aucune demande éligible pour le BC global.'], 422);
        }

        $bc = DB::transaction(function () use ($commandes, $user, $data) {
            $now = now();
            $antenne = $commandes->first()->antenne;
            $agg = [];
            foreach ($commandes as $cmd) {
                foreach ($cmd->lignes as $ligne) {
                    $resteSiege = max(0, (int) $ligne->quantite_demandee - (int) ($ligne->quantite_prise_antenne ?? 0));
                    if ($resteSiege <= 0) {
                        continue;
                    }
                    $cle = ($ligne->produit_id ?: 'x').'|'.mb_strtolower(trim($ligne->designation));
                    if (!isset($agg[$cle])) {
                        $agg[$cle] = [
                            'produit_id' => $ligne->produit_id,
                            'designation' => $ligne->designation,
                            'categorie' => $ligne->categorie,
                            'quantite' => 0,
                            'prix_unitaire_xaf' => (float) $ligne->prix_unitaire_xaf,
                            'details_demandeurs' => [],
                        ];
                    }
                    $agg[$cle]['quantite'] += $resteSiege;
                    $agg[$cle]['details_demandeurs'][] = [
                        'demandeur_user_id' => $cmd->demandeur_user_id,
                        'demandeur_nom' => $cmd->demandeur_nom,
                        'partenaire_nom' => $cmd->partenaire_nom,
                        'commande_id' => $cmd->id,
                        'numero_commande' => $cmd->numero_commande,
                        'quantite' => $resteSiege,
                    ];
                }
            }

            if (!$agg) {
                abort(422, 'Tout a déjà été servi depuis le stock antenne.');
            }

            $qteTotale = collect($agg)->sum('quantite');
            $montant = collect($agg)->sum(fn ($l) => $l['quantite'] * $l['prix_unitaire_xaf']);
            $seq = FluxBcGlobal::whereYear('created_at', $now->year)->count() + 1;

            $bc = FluxBcGlobal::create([
                'numero' => sprintf('BCG-%s-%04d', $now->format('Ymd'), $seq),
                'antenne_id' => $antenne?->id,
                'antenne_nom' => $antenne?->nom,
                'statut' => 'BROUILLON',
                'nb_demandes' => $commandes->count(),
                'nb_demandeurs' => $commandes->pluck('demandeur_user_id')->unique()->count(),
                'quantite_totale' => $qteTotale,
                'montant_total_xaf' => $montant,
                'date_livraison_prevue' => $data['date_livraison_prevue'] ?? null,
                'cree_par_user_id' => $user->id,
                'cree_par_nom' => $user->name,
                'note' => $data['note'] ?? null,
            ]);

            foreach ($agg as $ligne) {
                FluxBcGlobalLigne::create([
                    'bc_global_id' => $bc->id,
                    'produit_id' => $ligne['produit_id'],
                    'designation' => $ligne['designation'],
                    'categorie' => $ligne['categorie'],
                    'quantite' => $ligne['quantite'],
                    'prix_unitaire_xaf' => $ligne['prix_unitaire_xaf'],
                    'montant_ligne_xaf' => $ligne['quantite'] * $ligne['prix_unitaire_xaf'],
                    'details_demandeurs' => $ligne['details_demandeurs'],
                ]);
            }

            foreach ($commandes as $cmd) {
                $cmd->update([
                    'bc_global_id' => $bc->id,
                    'date_livraison_prevue' => $data['date_livraison_prevue'] ?? $cmd->date_livraison_prevue,
                    'statut' => 'EN_BC_GLOBAL',
                ]);
            }

            return $bc->load('lignes');
        });

        return response()->json(['success' => true, 'bc_global' => $this->serialiserBc($bc, true)], 201);
    }

    /** Transfert du BC global au siège — sans détail demandeur. */
    public function transfererBcGlobal(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!in_array($user->code_role, self::ROLES_ANTENNE, true)) {
            return response()->json(['success' => false, 'message' => 'Seul le chef d\'antenne peut transférer le BC global.'], 403);
        }

        $bc = FluxBcGlobal::with(['lignes', 'commandes.lignes'])->findOrFail($id);
        if ($bc->statut === 'TRANSFERE_SIEGE') {
            return response()->json(['success' => false, 'message' => 'Ce BC global est déjà au siège.'], 422);
        }

        DB::transaction(function () use ($bc, $user, $request) {
            $now = now();
            $bc->update([
                'statut' => 'TRANSFERE_SIEGE',
                'transferee_au_siege_at' => $now,
                'date_livraison_prevue' => $request->input('date_livraison_prevue') ?: $bc->date_livraison_prevue,
            ]);

            foreach ($bc->commandes as $cmd) {
                $cmd->update([
                    'statut' => 'TRANSFEREE_SIEGE',
                    'transferee_au_siege_at' => $now,
                    'transferee_au_siege_par_user_id' => $user->id,
                    'transferee_au_siege_par_nom' => $user->name,
                    'date_livraison_prevue' => $bc->date_livraison_prevue,
                ]);
                $cmd->journal()->create([
                    'user_id' => $user->id,
                    'antenne_id' => $cmd->antenne_id,
                    'auteur_nom' => $user->name,
                    'auteur_role' => $user->role,
                    'action' => 'TRANSFERT',
                    'statut' => 'TRANSFEREE_SIEGE',
                    'detail' => 'Transfert via BC global '.$bc->numero.' (agrégé, sans détail demandeur)',
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'bc_global' => $this->serialiserBc($bc->fresh('lignes'), false),
            'message' => 'BC global transmis au siège sans le détail par demandeur.',
        ]);
    }

    public function listerBcGlobaux(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = FluxBcGlobal::with('lignes')->orderByDesc('id');

        if (in_array($user->code_role, self::ROLES_ANTENNE, true)
            && !in_array($user->code_role, ['SUPER_ADMIN', 'ADMIN_METIER'], true)) {
            $antenneId = $user->antenne_id ?: $user->province_id;
            $query->where('antenne_id', $antenneId);
        }

        $avecDetails = in_array($user->code_role, self::ROLES_ANTENNE, true)
            || in_array($user->code_role, ['SUPER_ADMIN', 'ADMIN_METIER'], true);

        // Siège : jamais les détails demandeurs
        if (in_array($user->code_role, self::ROLES_SIEGE, true)
            && !in_array($user->code_role, ['SUPER_ADMIN', 'ADMIN_METIER'], true)) {
            $avecDetails = false;
        }

        $liste = $query->get()->map(fn ($bc) => $this->serialiserBc($bc, $avecDetails));

        return response()->json(['success' => true, 'bc_globaux' => $liste]);
    }

    /** Génère un BL avec signature numérique ou manuscrite (après / pendant dispersion). */
    public function genererBl(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'commande_id' => 'nullable|integer|exists:commandes_territoriales,id',
            'bc_global_id' => 'nullable|integer|exists:flux_bc_globaux,id',
            'mode_signature' => 'required|in:NUMERIQUE,MANUSCRITE',
            'signataire_nom' => 'required|string|max:150',
            'signature_data' => 'nullable|string',
            'lignes' => 'nullable|array',
        ]);

        if (empty($data['commande_id']) && empty($data['bc_global_id'])) {
            return response()->json(['success' => false, 'message' => 'Indiquez une commande ou un BC global.'], 422);
        }

        $commande = !empty($data['commande_id'])
            ? CommandeTerritoriale::with(['lignes', 'antenne'])->findOrFail($data['commande_id'])
            : null;
        $bc = !empty($data['bc_global_id'])
            ? FluxBcGlobal::with('lignes')->findOrFail($data['bc_global_id'])
            : null;

        $lignes = $data['lignes'] ?? [];
        if (!$lignes) {
            if ($commande) {
                $lignes = $commande->lignes->map(fn ($l) => [
                    'designation' => $l->designation,
                    'quantite' => (int) $l->quantite_dispersee ?: (int) $l->quantite_prise_en_charge ?: (int) $l->quantite_prise_antenne,
                    'prix_unitaire_xaf' => (float) $l->prix_unitaire_xaf,
                ])->filter(fn ($l) => $l['quantite'] > 0)->values()->all();
            } elseif ($bc) {
                $lignes = $bc->lignes->map(fn ($l) => [
                    'designation' => $l->designation,
                    'quantite' => (int) $l->quantite,
                    'prix_unitaire_xaf' => (float) $l->prix_unitaire_xaf,
                ])->all();
            }
        }

        $qte = collect($lignes)->sum('quantite');
        $montant = collect($lignes)->sum(fn ($l) => ($l['quantite'] ?? 0) * ($l['prix_unitaire_xaf'] ?? 0));
        $now = now();
        $seq = FluxBonLivraison::whereYear('created_at', $now->year)->count() + 1;

        $bl = FluxBonLivraison::create([
            'numero' => sprintf('BL-%s-%04d', $now->format('Ymd'), $seq),
            'commande_id' => $commande?->id,
            'bc_global_id' => $bc?->id,
            'antenne_id' => $commande?->antenne_id ?: $bc?->antenne_id,
            'antenne_nom' => $commande?->antenne?->nom ?: $bc?->antenne_nom,
            'destinataire_nom' => $commande?->demandeur_nom,
            'destinataire_structure' => $commande?->partenaire_nom,
            'quantite_totale' => $qte,
            'montant_total_xaf' => $montant,
            'mode_signature' => $data['mode_signature'],
            'signataire_nom' => $data['signataire_nom'],
            'signature_data' => $data['signature_data'] ?? null,
            'signe_at' => $now,
            'emis_par_user_id' => $user->id,
            'emis_par_nom' => $user->name,
            'emitted_at' => $now,
            'lignes' => $lignes,
        ]);

        return response()->json(['success' => true, 'bon_livraison' => $bl], 201);
    }

    public function categoriesProduits(): JsonResponse
    {
        $cats = ProduitPharmaceutique::query()
            ->whereNotNull('categorie')
            ->where('categorie', '!=', '')
            ->distinct()
            ->orderBy('categorie')
            ->pluck('categorie')
            ->values();

        $kits = [
            'Kit urgence maternité',
            'Kit antipaludique ACT',
            'Kit réanimation',
            'Kit froid / vaccins',
            'Kit chirurgie de base',
            'Kit solutés massifs',
            'Sans kit (libre)',
        ];

        return response()->json([
            'success' => true,
            'categories' => $cats,
            'kits_medicaux' => $kits,
        ]);
    }

    private function serialiserBc(FluxBcGlobal $bc, bool $avecDetails): array
    {
        return [
            'id' => $bc->id,
            'numero' => $bc->numero,
            'antenne_id' => $bc->antenne_id,
            'antenne_nom' => $bc->antenne_nom,
            'statut' => $bc->statut,
            'nb_demandes' => $bc->nb_demandes,
            'nb_demandeurs' => $bc->nb_demandeurs,
            'quantite_totale' => $bc->quantite_totale,
            'montant_total_xaf' => $bc->montant_total_xaf,
            'date_livraison_prevue' => optional($bc->date_livraison_prevue)->format('Y-m-d'),
            'cree_par_nom' => $bc->cree_par_nom,
            'transferee_au_siege_at' => optional($bc->transferee_au_siege_at)->toIso8601String(),
            'note' => $bc->note,
            'lignes' => $bc->lignes->map(function (FluxBcGlobalLigne $l) use ($avecDetails) {
                return [
                    'id' => $l->id,
                    'produit_id' => $l->produit_id,
                    'designation' => $l->designation,
                    'categorie' => $l->categorie,
                    'quantite' => $l->quantite,
                    'prix_unitaire_xaf' => $l->prix_unitaire_xaf,
                    'montant_ligne_xaf' => $l->montant_ligne_xaf,
                    'details_demandeurs' => $avecDetails ? ($l->details_demandeurs ?: []) : [],
                ];
            })->values(),
        ];
    }

    /** Débite le stock FEFO et retourne la quantité réellement servie. */
    private function debiterStockAntenne(int $antenneId, int $produitId, int $qte): int
    {
        if ($qte <= 0) {
            return 0;
        }
        if (!Schema::hasTable('lots_produits')) {
            return $qte; // sans table lots : on autorise (mode démo / seed partiel)
        }
        $reste = $qte;
        $servi = 0;
        $lots = LotProduit::query()
            ->where('antenne_id', $antenneId)
            ->where('produit_id', $produitId)
            ->where('quantite_disponible', '>', 0)
            ->orderBy('date_peremption')
            ->lockForUpdate()
            ->get();

        foreach ($lots as $lot) {
            if ($reste <= 0) {
                break;
            }
            $dispo = (int) $lot->quantite_disponible;
            $prise = min($dispo, $reste);
            $lot->update(['quantite_disponible' => $dispo - $prise]);
            $reste -= $prise;
            $servi += $prise;
        }

        return $servi;
    }
}
