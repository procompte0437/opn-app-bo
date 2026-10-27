<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FluxBonLivraison extends Model
{
    protected $table = 'flux_bons_livraison';

    protected $fillable = [
        'numero',
        'commande_id',
        'bc_global_id',
        'antenne_id',
        'antenne_nom',
        'destinataire_nom',
        'destinataire_structure',
        'quantite_totale',
        'montant_total_xaf',
        'mode_signature',
        'signataire_nom',
        'signature_data',
        'signe_at',
        'emis_par_user_id',
        'emis_par_nom',
        'emitted_at',
        'lignes',
    ];

    protected $casts = [
        'montant_total_xaf' => 'decimal:2',
        'signe_at' => 'datetime',
        'emitted_at' => 'datetime',
        'lignes' => 'array',
    ];

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeTerritoriale::class, 'commande_id');
    }

    public function bcGlobal(): BelongsTo
    {
        return $this->belongsTo(FluxBcGlobal::class, 'bc_global_id');
    }
}
