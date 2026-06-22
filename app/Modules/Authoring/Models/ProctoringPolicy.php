<?php

namespace App\Modules\Authoring\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A reusable proctoring configuration attached to assessments (docs/05). */
class ProctoringPolicy extends Model
{
    use BelongsToTenant;

    protected $table = 'proctoring_policies';

    protected $fillable = ['institution_id', 'name', 'signals', 'mode', 'lockdown_required'];

    protected $casts = ['signals' => 'array', 'lockdown_required' => 'boolean'];
}
