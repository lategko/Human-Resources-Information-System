<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeImport extends Model
{
    protected $fillable = [
        'uploaded_by',
        'mapping_approved_by',
        'previewed_by',
        'confirmed_by',
        'original_name',
        'source_file_path',
        'source_sha256',
        'status',
        'source_headers',
        'field_mappings',
        'mapping_approved_at',
        'previewed_at',
        'confirmed_at',
        'row_count',
        'imported_count',
        'skipped_count',
        'review_count',
    ];

    protected function casts(): array
    {
        return [
            'source_headers' => 'array',
            'field_mappings' => 'array',
            'mapping_approved_at' => 'datetime',
            'previewed_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function mappingApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mapping_approved_by');
    }

    public function previewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'previewed_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(EmployeeImportRow::class);
    }

    public function mappingEvents(): HasMany
    {
        return $this->hasMany(EmployeeImportMappingEvent::class);
    }
}
