<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * UUIDv7 string primary keys: non-guessable, globally unique across shards, and
 * time-ordered for index locality (docs/03 conventions).
 */
trait HasUuidv7
{
    public function initializeHasUuidv7(): void
    {
        $this->incrementing = false;
        $this->keyType = 'string';
    }

    public static function bootHasUuidv7(): void
    {
        static::creating(function (Model $model) {
            $key = $model->getKeyName();
            if (empty($model->{$key})) {
                $model->{$key} = (string) Str::uuid7();
            }
        });
    }
}
