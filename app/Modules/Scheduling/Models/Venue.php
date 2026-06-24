<?php

namespace App\Modules\Scheduling\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A physical exam location with a seating capacity (Scheduling context). */
class Venue extends Model
{
    use BelongsToTenant;

    protected $table = 'venues';

    protected $fillable = ['institution_id', 'name', 'code', 'location', 'capacity', 'status'];

    protected $casts = ['capacity' => 'integer'];

    public function sessions(): HasMany
    {
        return $this->hasMany(ExamSession::class);
    }
}
