<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as department data: queries are filtered to the current
 * tenant and new rows are stamped with its id. Writing a row for a
 * different tenant than the active one is refused.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $tenantId = app(TenantContext::class)->id();

            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', $tenantId);
            } elseif ($model->getAttribute('tenant_id') !== $tenantId) {
                throw new MissingTenantContext('Refusing to create a row for a different tenant.');
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw new MissingTenantContext('The tenant of a row cannot be changed.');
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
