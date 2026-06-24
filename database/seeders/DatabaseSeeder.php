<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with an explorable end-to-end demo.
     *
     * NOTE: the default skeleton seeded a User via the generic factory; that is removed
     * because our `users` schema (password_hash / full_name) differs. The DemoSeeder
     * exercises the real domain services instead — events fire, so proctoring sessions
     * and certificates are produced exactly as they would be in production.
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
