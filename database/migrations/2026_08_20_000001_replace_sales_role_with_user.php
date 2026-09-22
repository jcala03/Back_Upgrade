<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertKnownRoles(['admin', 'sales', 'user']);
        DB::table('users')->where('role', 'sales')->update(['role' => 'user']);
        DB::statement("ALTER TABLE users MODIFY role VARCHAR(255) NOT NULL DEFAULT 'user'");
    }

    public function down(): void
    {
        $this->assertKnownRoles(['admin', 'user', 'sales']);
        DB::table('users')->where('role', 'user')->update(['role' => 'sales']);
        DB::statement("ALTER TABLE users MODIFY role VARCHAR(255) NOT NULL DEFAULT 'sales'");
    }

    private function assertKnownRoles(array $allowed): void
    {
        $unexpected = DB::table('users')->whereNotIn('role', $allowed)->distinct()->pluck('role');
        if ($unexpected->isNotEmpty()) {
            throw new RuntimeException('No se puede migrar users.role: existen roles inesperados: '.$unexpected->join(', '));
        }
    }
};
