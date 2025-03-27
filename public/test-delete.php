<?php

// 加载Laravel环境
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

// 启动应用程序服务
$app->boot();

// 使用Facade
use Illuminate\Support\Facades\DB;

// 获取一个非默认的expense记录
$expense = DB::table('expenses')->where('is_default', false)->first();

if (!$expense) {
    echo "未找到非默认的expense记录！<br>";
    echo "<a href='/expenses'>返回消耗管理</a>";
    exit;
}

echo "尝试删除ID为 {$expense->id} 的消耗记录...<br>";

try {
    // 直接从数据库删除
    $result = DB::table('expenses')->where('id', $expense->id)->delete();
    
    if ($result) {
        echo "删除成功！<br>";
    } else {
        echo "删除失败！<br>";
    }
} catch (Exception $e) {
    echo "删除时出现异常：" . $e->getMessage() . "<br>";
}

echo "<a href='/expenses'>返回消耗管理</a>"; 