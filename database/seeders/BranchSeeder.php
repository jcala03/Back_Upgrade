<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->branches() as $branch) {
            Branch::updateOrCreate(['code' => $branch['code']], $branch);
        }
    }

    private function branches(): array
    {
        return [
            [
                'code' => 'BAQ',
                'slug' => 'barranquilla',
                'name' => 'Barranquilla',
                'city' => 'Barranquilla',
                'is_active' => true,
            ],
            [
                'code' => 'BOG',
                'slug' => 'bogota',
                'name' => 'Bogotá',
                'city' => 'Bogotá',
                'is_active' => true,
            ],
        ];
    }
}
