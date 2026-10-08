<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pieces of a proforma that came back. They go back into stock and are neither paid nor owed. */
class SalesDocumentReturn extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'sales_document_id', 'created_by', 'note', 'total_cents'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'sales_document_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesDocumentReturnLine::class);
    }

    protected function casts(): array
    {
        return ['total_cents' => 'integer'];
    }
}
