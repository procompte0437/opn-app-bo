<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('flux_bc_global_lignes') && !Schema::hasColumn('flux_bc_global_lignes', 'quantite_prise_siege')) {
            Schema::table('flux_bc_global_lignes', function (Blueprint $table) {
                $table->unsignedInteger('quantite_prise_siege')->default(0)->after('quantite');
            });
        }

        if (Schema::hasTable('flux_bc_globaux') && !Schema::hasColumn('flux_bc_globaux', 'prise_en_charge_siege_at')) {
            Schema::table('flux_bc_globaux', function (Blueprint $table) {
                $table->timestamp('prise_en_charge_siege_at')->nullable()->after('transferee_au_siege_at');
                $table->string('prise_en_charge_siege_par_nom', 150)->nullable()->after('prise_en_charge_siege_at');
            });
        }

        if (!Schema::hasTable('flux_notifications')) {
            Schema::create('flux_notifications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('destinataire_user_id')->nullable()->index();
                $table->unsignedBigInteger('antenne_id')->nullable()->index();
                $table->string('type', 60)->index();
                $table->string('titre', 200);
                $table->text('message')->nullable();
                $table->unsignedBigInteger('commande_id')->nullable()->index();
                $table->unsignedBigInteger('bc_global_id')->nullable()->index();
                $table->string('auteur_nom', 150)->nullable();
                $table->string('auteur_role', 120)->nullable();
                $table->timestamp('lu_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('flux_notifications');
        if (Schema::hasTable('flux_bc_globaux') && Schema::hasColumn('flux_bc_globaux', 'prise_en_charge_siege_at')) {
            Schema::table('flux_bc_globaux', function (Blueprint $table) {
                $table->dropColumn(['prise_en_charge_siege_at', 'prise_en_charge_siege_par_nom']);
            });
        }
        if (Schema::hasTable('flux_bc_global_lignes') && Schema::hasColumn('flux_bc_global_lignes', 'quantite_prise_siege')) {
            Schema::table('flux_bc_global_lignes', function (Blueprint $table) {
                $table->dropColumn('quantite_prise_siege');
            });
        }
    }
};
