<?php

namespace App\Models\Api;

use Illuminate\Database\Eloquent\Model;

class FailedDetrackJob extends Model
{
    protected $table    = 'failed_detrack_jobs';
    protected $guarded  = ['id'];
    protected $fillable = ['sale_id', 'payload', 'error'];
    protected $casts = [
        'payload' => 'array',
    ];
}
