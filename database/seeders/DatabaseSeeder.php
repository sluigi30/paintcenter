<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'superadmin@paintcenter.com'],
            [
                'first_name' => 'Super',
                'last_name'  => 'Admin',
                'password'   => Hash::make('password'),
                'role'       => 'super_admin',
            ]
        );

        User::updateOrCreate(
            ['email' => 'admin@paintcenter.com'],
            [
                'first_name' => 'Admin',
                'last_name'  => 'User',
                'password'   => Hash::make('password'),
                'role'       => 'admin',
            ]
        );
    }
}
