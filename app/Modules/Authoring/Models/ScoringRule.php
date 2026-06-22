<?php

namespace App\Modules\Authoring\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A versioned, named scoring policy (docs/01 §4.4, docs/03 §3). Examples: +4/-1/0,
 * +1/0/0, partial credit, custom formula. A score always pins the rule version that
 * produced it, so results are reproducible.
 */
class ScoringRule extends Model
{
    use BelongsToTenant;

    protected $table = 'scoring_rules';

    protected $fillable = ['institution_id', 'name', 'version', 'policy'];

    protected $casts = ['policy' => 'array', 'version' => 'integer'];

    public function toPolicy(): ScoringPolicy
    {
        return new ScoringPolicy($this->policy ?? []);
    }
}
