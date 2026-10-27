<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommandeTerritoriale extends Model
{
    protected $table = 'commandes_territoriales';

    protected $fillable = [
        'numero_commande',
        'antenne_id',
        'demandeur_user_id',
        'demandeur_nom',
        'demandeur_role',
        'partenaire_nom',
        'partenaire_type',
        'statut',
        'motif',
        'categorie',
        'kit_medical',
        'bc_global_id',
        'date_livraison_prevue',
        'prise_en_charge_antenne_at',
        'prise_en_charge_antenne_par_nom',
        'montant_total_xaf',
        'transmise_at',
        'transmise_par_nom',
        'transferee_au_siege_at',
        'transferee_au_siege_par_user_id',
        'transferee_au_siege_par_nom',
        'prise_en_charge_siege_at',
        'prise_en_charge_siege_par_nom',
        'dispersee_at',
        'dispersee_par_nom',
        'note_dispersion',
    ];

    protected $casts = [
        'montant_total_xaf' => 'decimal:2',
        'transmise_at' => 'datetime',
        'transferee_au_siege_at' => 'datetime',
        'prise_en_charge_siege_at' => 'datetime',
        'prise_en_charge_antenne_at' => 'datetime',
        'date_livraison_prevue' => 'date',
        'dispersee_at' => 'datetime',
    ];


    public function antenne(): BelongsTo
    {
        return $this->belongsTo(AntenneProvinciale::class, 'antenne_id');
    }

    public function demandeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'demandeur_user_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneCommandeTerritoriale::class, 'commande_id');
    }

    public function journal(): HasMany
    {
        return $this->hasMany(JournalFluxCommande::class, 'commande_id')->orderBy('id');
    }

    public function transfertsSiege(): HasMany
    {
        return $this->hasMany(FluxTransfertSiege::class, 'commande_id')->orderBy('id');
    }

    public function prisesEnChargeSiege(): HasMany
    {
        return $this->hasMany(FluxPriseEnChargeSiege::class, 'commande_id')->orderBy('id');
    }

    public function pieces(): HasMany
    {
        return $this->hasMany(FluxPieceCommerciale::class, 'commande_id')->orderByDesc('id');
    }

    public function bcGlobal(): BelongsTo
    {
        return $this->belongsTo(FluxBcGlobal::class, 'bc_global_id');
    }

    public function bonsLivraison(): HasMany
    {
        return $this->hasMany(FluxBonLivraison::class, 'commande_id')->orderByDesc('id');
    }
}
