<?php
// 检查是否有ID
$id = $_GET['id'] ?? null;
if (!$id) {
    echo json_encode(['success' => false, 'message' => 'ID参数缺失']);
    exit;
}

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

// 获取指定的expense记录
$expense = DB::table('expenses')->where('id', $id)->first();

if (!$expense) {
    echo json_encode(['success' => false, 'message' => '未找到指定的消耗记录']);
    exit;
}

if ($expense->is_default) {
    echo json_encode(['success' => false, 'message' => '默认消耗不可删除']);
    exit;
}

try {
    // 直接从数据库删除
    $result = DB::table('expenses')->where('id', $id)->delete();
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => '删除成功！']);
    } else {
        echo json_encode(['success' => false, 'message' => '删除失败！']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '删除时出现异常：' . $e->getMessage()]);
} 