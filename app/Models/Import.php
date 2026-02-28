<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Import extends Model
{
    protected $fillable = [
        'type',
        'filename',
        'file_size',
        'total_rows',
        'imported_count',
        'duplicate_count',
        'error_count',
        'empty_row_count',
        'error_details',
        'status',
        'import_started_at',
        'import_completed_at',
    ];

    protected $casts = [
        'import_started_at' => 'datetime',
        'import_completed_at' => 'datetime',
    ];

    public function getFileSizeMbAttribute()
    {
        return round($this->file_size / 1024 / 1024, 2);
    }

    public function getDurationAttribute()
    {
        if ($this->import_started_at && $this->import_completed_at) {
            return $this->import_started_at->diffInSeconds($this->import_completed_at);
        }
        return null;
    }
}
