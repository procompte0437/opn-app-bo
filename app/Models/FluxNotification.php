<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FluxNotification extends Model
{
    protected $table = 'flux_notifications';

    protected $fillable = [
        'destinataire_user_id',
        'antenne_id',
        'type',
        'titre',
        'message',
        'commande_id',
        'bc_global_id',
        'auteur_nom',
        'auteur_role',
        'lu_at',
    ];

    protected $casts = [
        'lu_at' => 'datetime',
    ];

    public function destinataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destinataire_user_id');
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeTerritoriale::class, 'commande_id');
    }
}
