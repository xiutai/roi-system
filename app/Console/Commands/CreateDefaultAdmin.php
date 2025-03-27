<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class CreateDefaultAdmin extends Command
{
    /**
     * 命令名称和签名
     *
     * @var string
     */
    protected $signature = 'admin:default';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '创建默认管理员账户（admin@example.com，密码：Aaa123123@）';

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle()
    {
        $this->info('创建默认管理员账户...');
        
        // 检查是否已存在管理员账户
        $adminExists = User::where('username', 'admin')->exists();
        
        if ($adminExists) {
            $this->info('默认管理员账户已存在！');
            return 0;
        }
        
        // 创建默认管理员账户
        User::create([
            'name' => 'Administrator',
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('Aaa123123@'),
            'is_admin' => true,
        ]);
        
        $this->info('默认管理员账户创建成功！');
        $this->table(
            ['姓名', '用户名', '邮箱', '密码', '角色'],
            [['Administrator', 'admin', 'admin@example.com', 'Aaa123123@', '管理员']]
        );
        
        return 0;
    }
} 