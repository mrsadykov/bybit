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
        'exchange'
    ];

    protected $casts = [
        'api_secret' => 'encrypted',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
