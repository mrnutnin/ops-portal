<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['MINTERP' => 'MintERP', 'MINTPOS' => 'MintPOS', 'MINTHRM' => 'MintHRM'] as $code => $name) {
            Product::firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
        }
    }
}
