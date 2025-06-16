<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\License;
use App\Models\User;

class LicenseSeeder extends Seeder
{
    public function run()
    {
        // Create a default user if not exists
        $user = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => bcrypt('password'),
            ]
        );

        $licenses = [
            [
                'key' => 'test-license-local',
                'website_url' => 'http://localhost:8000',
                'expires_at' => now()->addYear(),
                'status' => 'active',
                'user_id' => $user->id
            ],
            [
                'key' => 'test-license-1',
                'website_url' => 'https://example1.com',
                'expires_at' => now()->addYear(),
                'status' => 'active',
                'user_id' => $user->id
            ],
            [
                'key' => 'test-license-2',
                'website_url' => 'https://example2.com',
                'expires_at' => now()->addYear(),
                'status' => 'active',
                'user_id' => $user->id
            ],
            [
                'key' => 'test-license-expired',
                'website_url' => 'https://expired.com',
                'expires_at' => now()->subDay(),
                'status' => 'expired',
                'user_id' => $user->id
            ],
        ];

        foreach ($licenses as $license) {
            License::create($license);
        }
    }
}
