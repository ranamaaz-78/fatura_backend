<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProductImage extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id',
        'created_by',
        'name',
        'name_key',
        'path',
        'mime',
        'size_bytes',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProductImage $image) {
            $image->uuid ??= (string) Str::uuid();
        });
    }

    /** URLs carry the uuid, never the row id and never the stored path. */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }
}
