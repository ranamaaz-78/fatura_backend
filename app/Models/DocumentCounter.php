<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DocumentCounter extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'kind',
        'year',
        'last_number',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'last_number' => 'integer',
        ];
    }
}
