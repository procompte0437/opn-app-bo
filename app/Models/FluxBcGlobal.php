<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FluxBcGlobal extends Model
{
    protected $table = 'flux_bc_globaux';

    protected $fillable = [
        'numero',
        'antenne_id',
        'antenne_nom',
        'statut',
        'nb_demandes',
        'nb_demandeurs',
        'quantite_totale',
        'montant_total_xaf',
        'date_livraison_prevue',
        'cree_par_user_id',
        'cree_par_nom',
        'transferee_au_siege_at',
        'prise_en_charge_siege_at',
        'prise_en_charge_siege_par_nom',
        'retour_siege_at',
        'note',
    ];

    protected $casts = [
        'montant_total_xaf' => 'decimal:2',
        'date_livraison_prevue' => 'date',
        'transferee_au_siege_at' => 'datetime',
        'prise_en_charge_siege_at' => 'datetime',
        'retour_siege_at' => 'datetime',
    ];

    public function antenne(): BelongsTo
    {
        return $this->belongsTo(AntenneProvinciale::class, 'antenne_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(FluxBcGlobalLigne::class, 'bc_global_id');
    }

    public function commandes(): HasMany
    {
        return $this->hasMany(CommandeTerritoriale::class, 'bc_global_id');
    }
}
