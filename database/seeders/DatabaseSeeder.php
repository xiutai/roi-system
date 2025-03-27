<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        // \App\Models\User::factory(10)->create();

        // \App\Models\User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);

        // 创建默认管理员账户
        if (!\App\Models\User::where('username', 'admin')->exists()) {
            \App\Models\User::create([
                'name' => 'Administrator',
                'username' => 'admin',
                'email' => 'admin@example.com',
                'password' => \Illuminate\Support\Facades\Hash::make('Aaa123123@'),
                'is_admin' => true,
            ]);
            
            $this->command->info('创建了默认管理员账户: admin');
        } else {
            $this->command->info('默认管理员账户已存在，跳过创建');
        }
    }
}
