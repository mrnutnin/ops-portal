<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function instances(): HasMany
    {
        return $this->hasMany(Instance::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(ProductPlan::class);
    }
}
