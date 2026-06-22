<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Applied to tenant-scoped models (those with an institution_id column). It:
 *   1. adds a global scope filtering to the current institution;
 *   2. stamps institution_id on create from the tenant context.
 *
 * Combine with HasUuidv7 for the primary key. Platform-scope context
 * (TenantContext::actAsPlatform) bypasses the filter for super-admin/system work.
 * See docs/03 §9 (application-layer isolation, layer 1).
 */
trait BelongsToTenant
{
    use HasUuidv7;

    public static function bootBelongsToTenant(): void
    {
        $context = app(TenantContext::class);

        static::addGlobalScope('tenant', function (Builder $builder) use ($context) {
            if ($context->isPlatformScope()) {
                return;
            }
            if ($context->hasInstitution()) {
                $builder->where($builder->getModel()->getTable().'.institution_id', $context->institutionId());
            }
        });

        static::creating(function (Model $model) use ($context) {
            if (empty($model->institution_id) && $context->hasInstitution()) {
                $model->institution_id = $context->institutionId();
            }
        });
    }
}
