<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImportController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// 导入进度API - 无需认证，支持跨域访问
Route::get('/import/{id}/progress', [ImportController::class, 'progress'])
    ->middleware('cors')
    ->name('api.import.progress.direct');

// 添加OPTIONS路由支持CORS预检请求
Route::options('/import/{id}/progress', function() {
    return response()->json(['status' => 'ok'], 200)
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, X-CSRF-TOKEN, Accept');
})->middleware('cors');
