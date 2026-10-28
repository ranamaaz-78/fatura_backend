<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One time a customer went into the shared demo account. */
class DemoSession extends Model
{
    protected $fillable = ['user_id', 'token_id', 'entered_at', 'exited_at', 'ip'];

    protected function casts(): array
    {
        return [
            'entered_at' => 'datetime',
            'exited_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
