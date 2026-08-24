<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DocumentLink extends Model
{
    protected $fillable = ['document_id', 'target_type', 'target_key'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(LibraryDocument::class, 'document_id');
    }

    public function token(): string
    {
        return $this->target_type.':'.$this->target_key;
    }
}
