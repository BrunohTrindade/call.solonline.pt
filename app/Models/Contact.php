<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $fillable = [
        'empresa',
        'nome',
        'telefone',
        'email',
        'nif',
        'observacao',
        'info_adicional',
        'processed_at',
        'processed_by',
        'numero',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function viewers()
    {
        return $this->belongsToMany(User::class, 'contact_user', 'contact_id', 'user_id');
    }
}
