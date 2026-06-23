<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(['email' => 'admin@tpka.com'], ['name' => 'Admin TPA', 'password' => Hash::make('password'), 'role' => 'administrator', 'is_active' => true, 'email_verified_at' => now()]);
        User::updateOrCreate(['email' => 'operator@tpka.com'], ['name' => 'Operator TPA', 'password' => Hash::make('password'), 'role' => 'operator', 'is_active' => true, 'email_verified_at' => now()]);
        User::updateOrCreate(['email' => 'peserta@tpka.com'], ['name' => 'Peserta Demo', 'password' => Hash::make('password'), 'role' => 'peserta', 'is_active' => true, 'email_verified_at' => now()]);
    }
}
