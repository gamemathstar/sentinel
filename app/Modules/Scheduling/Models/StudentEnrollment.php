<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\OrgNode;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student's academic placement: programme (an org node) + level. Faculty and department
 * are derived from the programme node's materialized path, so a single enrollment supports
 * selection at any tier of the hierarchy.
 */
class StudentEnrollment extends Model
{
    use BelongsToTenant;

    protected $table = 'student_enrollments';

    protected $fillable = ['institution_id', 'user_id', 'programme_org_node_id', 'level', 'entry_year', 'status'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(OrgNode::class, 'programme_org_node_id');
    }
}
