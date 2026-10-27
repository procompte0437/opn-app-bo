<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MAJ circuit territorial :
 * - brouillon demandeur + kit/catégorie
 * - prise en charge antenne (stock local)
 * - BC global antenne → siège (agrégé)
 * - délai livraison + BL signé
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_territoriales', function (Blueprint $table) {
            if (!Schema::hasColumn('commandes_territoriales', 'categorie')) {
                $table->string('categorie', 120)->nullable()->after('motif');
            }
            if (!Schema::hasColumn('commandes_territoriales', 'kit_medical')) {
                $table->string('kit_medical', 120)->nullable()->after('categorie');
            }
            if (!Schema::hasColumn('commandes_territoriales', 'bc_global_id')) {
                $table->unsignedBigInteger('bc_global_id')->nullable()->after('kit_medical');
            }
            if (!Schema::hasColumn('commandes_territoriales', 'date_livraison_prevue')) {
                $table->date('date_livraison_prevue')->nullable()->after('bc_global_id');
            }
            if (!Schema::hasColumn('commandes_territoriales', 'prise_en_charge_antenne_at')) {
                $table->timestamp('prise_en_charge_antenne_at')->nullable()->after('date_livraison_prevue');
            }
            if (!Schema::hasColumn('commandes_territoriales', 'prise_en_charge_antenne_par_nom')) {
                $table->string('prise_en_charge_antenne_par_nom')->nullable()->after('prise_en_charge_antenne_at');
            }
        });

        Schema::table('lignes_commande_territoriale', function (Blueprint $table) {
            if (!Schema::hasColumn('lignes_commande_territoriale', 'quantite_prise_antenne')) {
                $table->unsignedInteger('quantite_prise_antenne')->default(0)->after('quantite_demandee');
            }
            if (!Schema::hasColumn('lignes_commande_territoriale', 'categorie')) {
                $table->string('categorie', 120)->nullable()->after('designation');
            }
        });

        if (!Schema::hasTable('flux_bc_globaux')) {
            Schema::create('flux_bc_globaux', function (Blueprint $table) {
                $table->id();
                $table->string('numero', 40)->unique();
                $table->foreignId('antenne_id')->constrained('antennes_provinciales')->cascadeOnDelete();
                $table->string('antenne_nom')->nullable();
                $table->string('statut', 40)->default('BROUILLON'); // BROUILLON | TRANSFERE_SIEGE | RETOUR_SIEGE | LIVRE
                $table->unsignedInteger('nb_demandes')->default(0);
                $table->unsignedInteger('nb_demandeurs')->default(0);
                $table->unsignedInteger('quantite_totale')->default(0);
                $table->decimal('montant_total_xaf', 14, 2)->default(0);
                $table->date('date_livraison_prevue')->nullable();
                $table->foreignId('cree_par_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('cree_par_nom')->nullable();
                $table->timestamp('transferee_au_siege_at')->nullable();
                $table->timestamp('retour_siege_at')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('flux_bc_global_lignes')) {
            Schema::create('flux_bc_global_lignes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bc_global_id')->constrained('flux_bc_globaux')->cascadeOnDelete();
                $table->foreignId('produit_id')->nullable()->constrained('produits_pharmaceutiques')->nullOnDelete();
                $table->string('designation');
                $table->string('categorie', 120)->nullable();
                $table->unsignedInteger('quantite')->default(0);
                $table->decimal('prix_unitaire_xaf', 12, 2)->default(0);
                $table->decimal('montant_ligne_xaf', 14, 2)->default(0);
                /** JSON: [{demandeur_user_id, demandeur_nom, commande_id, quantite}] — détail antenne uniquement */
                $table->json('details_demandeurs')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('flux_bons_livraison')) {
            Schema::create('flux_bons_livraison', function (Blueprint $table) {
                $table->id();
                $table->string('numero', 40)->unique();
                $table->foreignId('commande_id')->nullable()->constrained('commandes_territoriales')->nullOnDelete();
                $table->foreignId('bc_global_id')->nullable()->constrained('flux_bc_globaux')->nullOnDelete();
                $table->foreignId('antenne_id')->nullable()->constrained('antennes_provinciales')->nullOnDelete();
                $table->string('antenne_nom')->nullable();
                $table->string('destinataire_nom')->nullable();
                $table->string('destinataire_structure')->nullable();
                $table->unsignedInteger('quantite_totale')->default(0);
                $table->decimal('montant_total_xaf', 14, 2)->default(0);
                $table->string('mode_signature', 20)->default('NUMERIQUE'); // NUMERIQUE | MANUSCRITE
                $table->string('signataire_nom')->nullable();
                $table->text('signature_data')->nullable(); // base64 PNG ou texte visa
                $table->timestamp('signe_at')->nullable();
                $table->foreignId('emis_par_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('emis_par_nom')->nullable();
                $table->timestamp('emitted_at')->nullable();
                $table->json('lignes')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('flux_bc_globaux') && Schema::hasColumn('commandes_territoriales', 'bc_global_id')) {
            Schema::table('commandes_territoriales', function (Blueprint $table) {
                // Index soft FK (évite échec si déjà liée)
                $table->index('bc_global_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flux_bons_livraison');
        Schema::dropIfExists('flux_bc_global_lignes');
        Schema::dropIfExists('flux_bc_globaux');

        Schema::table('lignes_commande_territoriale', function (Blueprint $table) {
            if (Schema::hasColumn('lignes_commande_territoriale', 'categorie')) {
                $table->dropColumn('categorie');
            }
            if (Schema::hasColumn('lignes_commande_territoriale', 'quantite_prise_antenne')) {
                $table->dropColumn('quantite_prise_antenne');
            }
        });

        Schema::table('commandes_territoriales', function (Blueprint $table) {
            foreach (['prise_en_charge_antenne_par_nom', 'prise_en_charge_antenne_at', 'date_livraison_prevue', 'bc_global_id', 'kit_medical', 'categorie'] as $col) {
                if (Schema::hasColumn('commandes_territoriales', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
