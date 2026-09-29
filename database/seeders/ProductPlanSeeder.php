<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductPlan;
use Illuminate\Database\Seeder;

class ProductPlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['MINTERP', 'MINTPOS', 'MINTHRM'] as $code) {
            $product = Product::query()->where('code', $code)->firstOrFail();
            foreach ([
                'CORE' => [5, 1, 2],
                'BUSINESS' => [15, 3, 6],
            ] as $planCode => [$users, $branches, $warehouses]) {
                $defaults = [
                    'quota_users' => $users,
                    'quota_branches' => $branches,
                    'quota_warehouses' => $warehouses,
                ];
                if ($code === 'MINTERP') {
                    $defaults['included_modules'] = $planCode === 'BUSINESS' ? ['crm', 'asset'] : [];
                }
                ProductPlan::firstOrCreate(
                    ['product_id' => $product->id, 'code' => $planCode, 'version' => '2026.1'],
                    ['name' => ucfirst(strtolower($planCode)), 'entitlement_defaults' => $defaults, 'is_active' => true],
                );
            }
        }
    }
}
