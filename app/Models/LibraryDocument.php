<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class LibraryDocument extends Model
{
    use SoftDeletes;

    protected $fillable = ['folder_id', 'original_name', 'storage_name', 'mime_type', 'size_bytes'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id')->withTrashed();
    }

    public function links(): HasMany
    {
        return $this->hasMany(DocumentLink::class, 'document_id');
    }
}
