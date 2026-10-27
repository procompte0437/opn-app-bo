<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LigneCommandeTerritoriale extends Model
{
    protected $table = 'lignes_commande_territoriale';

    protected $fillable = [
        'commande_id',
        'produit_id',
        'designation',
        'categorie',
        'quantite_demandee',
        'quantite_prise_antenne',
        'quantite_prise_en_charge',
        'quantite_reliquat',
        'quantite_dispersee',
        'prix_unitaire_xaf',
    ];

    protected $casts = [
        'prix_unitaire_xaf' => 'decimal:2',
    ];

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeTerritoriale::class, 'commande_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitPharmaceutique::class, 'produit_id');
    }
}
