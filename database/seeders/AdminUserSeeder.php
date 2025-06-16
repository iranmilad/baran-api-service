<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'name' => 'مدیر سیستم',
            'email' => 'admin@baran.com',
            'password' => Hash::make('admin123'),
            'is_admin' => true,
        ]);
    }
}
