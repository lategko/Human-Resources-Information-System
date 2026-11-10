<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveApplicationDocument extends Model
{
    protected $fillable = [
        'leave_application_id', 'uploaded_by', 'document_type', 'original_name',
        'stored_path', 'mime_type', 'size_bytes', 'is_sensitive',
    ];

    protected function casts(): array
    {
        return ['is_sensitive' => 'boolean'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(LeaveApplication::class, 'leave_application_id');
    }
}
