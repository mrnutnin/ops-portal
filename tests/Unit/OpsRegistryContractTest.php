<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Instance;
use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class OpsRegistryContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_catalog_seeds_once_without_overwriting_admin_changes(): void
    {
        $this->seed(ProductSeeder::class);
        Product::where('code', 'MINTERP')->update(['name' => 'Custom ERP Name']);
        $this->seed(ProductSeeder::class);

        $this->assertSame(3, Product::count());
        $this->assertSame('Custom ERP Name', Product::where('code', 'MINTERP')->value('name'));
        $this->assertSame(['MINTERP', 'MINTHRM', 'MINTPOS'], Product::orderBy('code')->pluck('code')->all());
    }

    public function test_instance_binds_one_customer_and_product_with_immutable_uuidv7_reference(): void
    {
        $customer = Customer::create(['name' => 'Example Co', 'is_active' => true]);
        $product = Product::create(['code' => 'MINTERP', 'name' => 'MintERP', 'is_active' => true]);
        $instance = Instance::create([
            'name' => 'Main ERP',
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'is_active' => true,
        ]);

        $this->assertSame($customer->id, $instance->customer->id);
        $this->assertSame($product->id, $instance->product->id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $instance->instance_ref);

        $instance->instance_ref = (string) Str::uuid7();
        $this->expectException(LogicException::class);
        $instance->save();
    }
}
