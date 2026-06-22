<?php

namespace App\Modules\QuestionBank\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared content (passage/image/audio/video/case study) that multiple items attach to
 * (docs/01 §4.3).
 */
class Stimulus extends Model
{
    use BelongsToTenant;

    protected $table = 'stimuli';

    protected $fillable = ['institution_id', 'kind', 's3_key', 'meta'];

    protected $casts = ['meta' => 'array'];
}
