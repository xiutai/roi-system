<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

return new class extends Migration
{
    /**
     * 执行迁移，创建默认管理员账户
     *
     * @return void
     */
    public function up()
    {
        // 检查是否已存在管理员账户
        $adminExists = User::where('is_admin', true)->exists();
        
        // 如果不存在管理员账户，创建一个默认账户
        if (!$adminExists) {
            User::create([
                'name' => 'Administrator',
                'username' => 'admin',
                'email' => 'admin@example.com',
                'password' => Hash::make('Aaa123123@'),
                'is_admin' => true,
            ]);
            
            // 将创建信息写入日志
            \Illuminate\Support\Facades\Log::info('已创建默认管理员账户: admin@example.com');
        }
    }

    /**
     * 回滚迁移
     *
     * @return void
     */
    public function down()
    {
        // 回滚时不删除管理员账户，因为这可能是生产环境中唯一的访问入口
        // 如果一定要删除，可以取消下面的注释
        /*
        User::where('email', 'admin@example.com')
            ->where('is_admin', true)
            ->delete();
        */
    }
}; 