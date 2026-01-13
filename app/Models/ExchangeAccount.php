<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeAccount extends Model
{
    protected $fillable = [
        'user_id',
        'api_key',
        'api_secret',
        'exchange',
        'is_testnet',
    ];

    protected $casts = [
        'api_secret' => 'encrypted',
        'is_testnet' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
