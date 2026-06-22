<?php

namespace App\Modules\Tenancy\Models;

use App\Support\Tenancy\HasUuidv7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The tenant boundary (docs/01 §4.2). Not itself tenant-scoped — it IS the tenant.
 */
class Institution extends Model
{
    use HasUuidv7, SoftDeletes;

    protected $table = 'institutions';

    protected $fillable = ['name', 'slug', 'status', 'encryption_key_ref', 'settings'];

    protected $casts = ['settings' => 'array'];
}
