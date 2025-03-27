<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ExchangeRateController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ChannelController;
use App\Http\Controllers\RoiController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

// 认证路由
Auth::routes(['register' => false, 'reset' => false, 'verify' => false]); // 禁用注册功能和密码重置功能

// 访问根路径重定向到登录页面
Route::get('/', function () {
    return redirect()->route('login');
});

// 登录后的首页重定向到仪表盘
Route::get('/home', function() {
    return redirect()->route('dashboard');
})->name('home');

// 所有需要登录才能访问的路由
Route::middleware(['auth'])->group(function () {
    // 仪表盘
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Excel导入
    Route::get('/import', [ImportController::class, 'index'])->name('import.index');
    Route::post('/import', [ImportController::class, 'store'])->name('import.store');
    Route::post('/import/clear', [ImportController::class, 'clearData'])->name('import.clear');
    Route::get('/import/{id}', [ImportController::class, 'show'])->name('import.show');
    Route::get('/import/{id}/progress', [ImportController::class, 'progress'])->name('import.progress');
    Route::delete('/import/{id}', [ImportController::class, 'destroy'])->name('import.destroy');

    // 汇率管理
    Route::get('/exchange-rates', [ExchangeRateController::class, 'index'])->name('exchange_rates.index');
    Route::get('/exchange-rates/create', [ExchangeRateController::class, 'create'])->name('exchange_rates.create');
    Route::post('/exchange-rates', [ExchangeRateController::class, 'store'])->name('exchange_rates.store');
    Route::get('/exchange-rates/{exchangeRate}/edit', [ExchangeRateController::class, 'edit'])->name('exchange_rates.edit');
    Route::put('/exchange-rates/{exchangeRate}', [ExchangeRateController::class, 'update'])->name('exchange_rates.update');
    Route::delete('/exchange-rates/{exchangeRate}', [ExchangeRateController::class, 'destroy'])->name('exchange_rates.destroy');
    Route::delete('/exchange-rates', [ExchangeRateController::class, 'batchDestroy'])->name('exchange_rates.batch_destroy');
    Route::post('/exchange-rates/default', [ExchangeRateController::class, 'updateDefault'])->name('exchange_rates.update_default');
    Route::post('/exchange-rates/batch', [ExchangeRateController::class, 'batch'])->name('exchange_rates.batch');

    // 消耗管理
    Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::get('/expenses/create', [ExpenseController::class, 'create'])->name('expenses.create');
    Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::get('/expenses/{expense}/edit', [ExpenseController::class, 'edit'])->name('expenses.edit');
    Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
    Route::delete('/expenses', [ExpenseController::class, 'batchDestroy'])->name('expenses.batch_destroy');
    Route::post('/expenses/default', [ExpenseController::class, 'updateDefault'])->name('expenses.update_default');
    Route::post('/expenses/batch', [ExpenseController::class, 'batch'])->name('expenses.batch');

    // 渠道管理
    Route::resource('channels', ChannelController::class);

    // ROI管理
    Route::get('/roi', [RoiController::class, 'index'])->name('roi.index');
    Route::post('/roi/recalculate', [RoiController::class, 'recalculate'])->name('roi.recalculate');
    Route::get('/roi/{date}/{channel}', [RoiController::class, 'show'])->name('roi.show');
    
    // 用户管理（仅管理员可用）
    Route::middleware(['admin'])->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });
    
    // 临时测试路由
    Route::get('/test-expense-delete/{id}', function($id) {
        $expense = App\Models\Expense::find($id);
        if (!$expense) {
            return redirect()->route('expenses.index')->with('error', '找不到指定的消耗记录');
        }
        
        if ($expense->is_default) {
            return redirect()->route('expenses.index')->with('error', '默认消耗不可删除');
        }
        
        try {
            $expense->delete();
            return redirect()->route('expenses.index')->with('success', '消耗记录已成功删除');
        } catch (\Exception $e) {
            return redirect()->route('expenses.index')->with('error', '删除失败：' . $e->getMessage());
        }
    })->name('test.expense.delete');
    
    // 直接使用SQL删除的测试路由
    Route::get('/test-expense-delete-sql/{id}', function($id) {
        $expense = DB::table('expenses')->where('id', $id)->first();
        if (!$expense) {
            return redirect()->route('expenses.index')->with('error', '找不到指定的消耗记录');
        }
        
        if ($expense->is_default) {
            return redirect()->route('expenses.index')->with('error', '默认消耗不可删除');
        }
        
        try {
            $result = DB::table('expenses')->where('id', $id)->delete();
            if ($result) {
                return redirect()->route('expenses.index')->with('success', '消耗记录已成功通过SQL删除');
            } else {
                return redirect()->route('expenses.index')->with('error', 'SQL删除失败');
            }
        } catch (\Exception $e) {
            return redirect()->route('expenses.index')->with('error', 'SQL删除失败：' . $e->getMessage());
        }
    })->name('test.expense.delete.sql');
    
    // 汇率管理测试路由
    Route::get('/test-exchange-rate-delete/{id}', function($id) {
        $exchangeRate = App\Models\ExchangeRate::find($id);
        if (!$exchangeRate) {
            return redirect()->route('exchange_rates.index')->with('error', '找不到指定的汇率记录');
        }
        
        if ($exchangeRate->is_default) {
            return redirect()->route('exchange_rates.index')->with('error', '默认汇率不可删除');
        }
        
        try {
            $exchangeRate->delete();
            return redirect()->route('exchange_rates.index')->with('success', '汇率记录已成功删除');
        } catch (\Exception $e) {
            return redirect()->route('exchange_rates.index')->with('error', '删除失败：' . $e->getMessage());
        }
    })->name('test.exchange_rate.delete');
    
    // 直接使用SQL删除汇率的测试路由
    Route::get('/test-exchange-rate-delete-sql/{id}', function($id) {
        $exchangeRate = DB::table('exchange_rates')->where('id', $id)->first();
        if (!$exchangeRate) {
            return redirect()->route('exchange_rates.index')->with('error', '找不到指定的汇率记录');
        }
        
        if ($exchangeRate->is_default) {
            return redirect()->route('exchange_rates.index')->with('error', '默认汇率不可删除');
        }
        
        try {
            $result = DB::table('exchange_rates')->where('id', $id)->delete();
            if ($result) {
                return redirect()->route('exchange_rates.index')->with('success', '汇率记录已成功通过SQL删除');
            } else {
                return redirect()->route('exchange_rates.index')->with('error', 'SQL删除失败');
            }
        } catch (\Exception $e) {
            return redirect()->route('exchange_rates.index')->with('error', 'SQL删除失败：' . $e->getMessage());
        }
    })->name('test.exchange_rate.delete.sql');

    // 测试用户控制器
    Route::get('/test-user-create', function() {
        try {
            $user = new \App\Models\User();
            $user->name = 'Test User';
            $user->username = 'testuser'.rand(1000,9999);
            $user->password = \Illuminate\Support\Facades\Hash::make('password123');
            $user->is_admin = 0;
            $user->save();
            
            // 记录创建日志
            \Illuminate\Support\Facades\Log::info('测试路由已创建用户', [
                'user_id' => $user->id,
                'username' => $user->username
            ]);
            
            return '用户创建成功，ID: '.$user->id;
        } catch (\Exception $e) {
            return '创建用户失败: '.$e->getMessage();
        }
    });
});
