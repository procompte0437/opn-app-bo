<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FluxBcGlobalLigne extends Model
{
    protected $table = 'flux_bc_global_lignes';

    protected $fillable = [
        'bc_global_id',
        'produit_id',
        'designation',
        'categorie',
        'quantite',
        'quantite_prise_siege',
        'prix_unitaire_xaf',
        'montant_ligne_xaf',
        'details_demandeurs',
    ];

    protected $casts = [
        'prix_unitaire_xaf' => 'decimal:2',
        'montant_ligne_xaf' => 'decimal:2',
        'details_demandeurs' => 'array',
    ];

    public function bcGlobal(): BelongsTo
    {
        return $this->belongsTo(FluxBcGlobal::class, 'bc_global_id');
    }
}
