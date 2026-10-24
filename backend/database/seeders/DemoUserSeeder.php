<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('DEMO_EMAIL');
        $password = env('DEMO_PASSWORD');

        if (! $email || ! $password) {
            throw new RuntimeException(
                'DEMO_EMAIL dan DEMO_PASSWORD harus diatur di .env.'
            );
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Pengguna Demo',
                'password' => Hash::make($password),
            ]
        );
    }
}
