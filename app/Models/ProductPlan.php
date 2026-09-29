<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProductPlan extends Model
{
    protected $fillable = ['product_id', 'code', 'version', 'name', 'entitlement_defaults', 'is_active'];

    protected static function booted(): void
    {
        static::updating(function (ProductPlan $plan): void {
            if ($plan->isDirty(['product_id', 'code', 'version', 'name', 'entitlement_defaults'])) {
                throw new LogicException('Product plan versions are immutable; create a new version.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'entitlement_defaults' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
