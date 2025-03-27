<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '创建初始管理员用户';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('创建管理员用户');
        $this->info('==================');

        // 获取用户输入
        $name = $this->ask('请输入管理员姓名');
        $email = $this->ask('请输入管理员邮箱');
        $password = $this->secret('请输入管理员密码（至少8个字符）');
        $confirmPassword = $this->secret('请确认密码');

        // 验证输入
        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmPassword,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return 1;
        }

        // 创建管理员用户
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_admin' => true,
        ]);

        $this->info('管理员用户已成功创建！');
        $this->table(
            ['姓名', '邮箱', '角色'],
            [[$user->name, $user->email, '管理员']]
        );

        return 0;
    }
}
