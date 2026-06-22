<?php

namespace App\Modules\Tenancy\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node in the academic hierarchy (docs/01 §4.2): faculty -> department -> programme ->
 * course -> topic -> learning_outcome, modelled as one self-referential tree.
 */
class OrgNode extends Model
{
    use BelongsToTenant;

    protected $table = 'org_nodes';

    protected $fillable = ['institution_id', 'parent_id', 'type', 'name', 'code', 'depth', 'path'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
