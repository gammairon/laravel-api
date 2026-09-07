<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    /**
     * The two suppliers required by the specification.
     * updateOrCreate keeps the seeder safe to run repeatedly.
     */
    public function run(): void
    {
        $suppliers = [
            ['code' => 'supplier-a', 'name' => 'Supplier A'],
            ['code' => 'supplier-b', 'name' => 'Supplier B'],
        ];

        foreach ($suppliers as $supplier) {
            Supplier::updateOrCreate(['code' => $supplier['code']], $supplier);
        }
    }
}
