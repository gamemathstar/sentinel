<?php

namespace App\Modules\Reporting\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The record of a generated report artifact (docs/01 §4.8). Tenant-scoped; the rendered
 * file lives on `disk` at `path`.
 */
class Report extends Model
{
    use BelongsToTenant;

    protected $table = 'reports';

    protected $fillable = [
        'institution_id', 'requested_by', 'type', 'format', 'status',
        'params', 'title', 'disk', 'path', 'rows', 'error', 'completed_at',
    ];

    protected $casts = [
        'params' => 'array',
        'rows' => 'integer',
        'completed_at' => 'datetime',
    ];
}
