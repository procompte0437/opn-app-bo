<?php

namespace Database\Seeders;

use App\Models\AntenneProvinciale;
use App\Models\Province;
use Illuminate\Database\Seeder;

/** Garantit une province pour chaque antenne (corrige listes vides à la création d'utilisateur). */
class ProvincesDepuisAntennesSeeder extends Seeder
{
    public function run(): void
    {
        $antennes = AntenneProvinciale::query()->orderBy('id')->get();
        foreach ($antennes as $antenne) {
            if ($antenne->province_id && Province::where('id', $antenne->province_id)->exists()) {
                continue;
            }
            $nom = $antenne->nom;
            // « Antenne Provinciale Ngounié - Mouila » → Ngounié
            if (preg_match('/Antenne\s+Provinciale\s+(.+?)(?:\s+-|\s*$)/iu', $antenne->nom, $m)) {
                $nom = trim($m[1]);
            }
            $code = $antenne->code_antenne ?: ('P'.$antenne->id);
            $province = Province::firstOrCreate(
                ['code' => substr($code, 0, 20)],
                ['nom' => $nom]
            );
            if (!$antenne->province_id || (int) $antenne->province_id !== (int) $province->id) {
                $antenne->update(['province_id' => $province->id]);
            }
        }
    }
}
