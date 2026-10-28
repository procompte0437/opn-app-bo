<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('commandes_territoriales')) {
            Schema::table('commandes_territoriales', function (Blueprint $table) {
                if (!Schema::hasColumn('commandes_territoriales', 'date_livraison_reliquat')) {
                    $table->date('date_livraison_reliquat')->nullable()->after('date_livraison_prevue');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('commandes_territoriales') && Schema::hasColumn('commandes_territoriales', 'date_livraison_reliquat')) {
            Schema::table('commandes_territoriales', function (Blueprint $table) {
                $table->dropColumn('date_livraison_reliquat');
            });
        }
    }
};
