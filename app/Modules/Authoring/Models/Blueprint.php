<?php

namespace App\Modules\Authoring\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Reusable composition constraints for a balanced paper (docs/01 §4.4, docs/03 §3):
 * e.g. {"total":20,"difficulty":{"easy":0.4,"medium":0.4,"hard":0.2},
 *       "types":["single","multiple"],"topics":{"<orgNodeId>":0.5}}.
 */
class Blueprint extends Model
{
    use BelongsToTenant;

    protected $table = 'blueprints';

    protected $fillable = ['institution_id', 'name', 'constraints'];

    protected $casts = ['constraints' => 'array'];
}
