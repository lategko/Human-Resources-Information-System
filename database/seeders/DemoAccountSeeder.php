<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DemoAccountSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo accounts can only be seeded in local and testing environments.');
        }

        foreach ([
            ['DEMO Administrator', 'hradmin@gmail.com', 'admin', true],
            ['DEMO HRMO Officer', 'hrmo@gmail.com', 'hrmo', false],
            ['DEMO Office Head', 'officehead@gmail.com', 'office_head', false],
        ] as [$name, $email, $role, $canFinalApprove]) {
            $user = User::firstOrCreate(['email' => $email], [
                'name' => $name,
                'role' => $role,
                'is_active' => true,
                'can_final_approve' => $canFinalApprove,
                'password' => Hash::make('password'),
            ]);

            if (! $user->wasRecentlyCreated) {
                $user->forceFill(['password' => Hash::make('password')])->save();
            }
        }
    }
}
