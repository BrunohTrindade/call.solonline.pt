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
        'processed_at',
        'processed_by',
        'numero',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];
}
