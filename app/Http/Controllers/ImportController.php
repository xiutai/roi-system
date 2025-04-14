<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Channel;
use App\Models\Transaction;
use App\Models\RoiCalculation;
use App\Imports\TransactionsImport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use App\Models\Expense;
use App\Models\ExchangeRate;
use App\Models\ImportJob;
use App\Jobs\ProcessImport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ImportController extends Controller
{
    /**
     * 显示导入页面
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // 获取渠道列表，显示在导入页面
        $channels = Channel::orderBy('name')->get();
        $transactionCount = Transaction::count();
        
        // 获取当前用户的最近导入任务
        $importJobs = ImportJob::where('user_id', Auth::id())
                        ->orderBy('created_at', 'desc')
                        ->take(5)
                        ->get();
        
        return view('import.index', compact('channels', 'transactionCount', 'importJobs'));
    }

    /**
     * 处理Excel/CSV导入
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'excel_file' => 'required|file|mimes:csv,txt',
            'insert_date' => 'nullable|date',
        ]);

        try {
            $file = $request->file('excel_file');
            if (!$file) {
                return back()->with('error', '未能获取上传的文件');
            }
            
            $originalFilename = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            
            // 确保文件扩展名有效，只接受CSV格式
            if (!in_array(strtolower($extension), ['csv', 'txt'])) {
                return back()->with('error', '不支持的文件类型: ' . $extension . '，只支持CSV格式');
            }
            
            // 确保storage/app/imports目录存在
            $importDir = storage_path('app/imports');
            if (!file_exists($importDir)) {
                if (!mkdir($importDir, 0755, true)) {
                    return back()->with('error', '无法创建导入目录，请检查权限');
                }
            }
            
            // 生成唯一文件名
            $filename = Str::uuid() . '.' . $extension;
            $fullPath = $importDir . '/' . $filename;
            
            // 直接使用PHP的文件函数移动上传的文件
            if (!move_uploaded_file($file->getRealPath(), $fullPath)) {
                return back()->with('error', '文件保存失败，请检查存储权限');
            }
            
            // 验证文件是否可读
            if (!is_readable($fullPath)) {
                return back()->with('error', '保存的文件无法读取，请检查权限');
            }
            
            // 获取插入日期，默认为当天
            $insertDate = $request->filled('insert_date') 
                ? Carbon::parse($request->input('insert_date'))->format('Y-m-d')
                : Carbon::now()->format('Y-m-d');
            
            // 检查是否存在相同insert_date的数据
            $isReplacingExisting = Transaction::where('insert_date', $insertDate)->exists();
            
            // 创建导入任务记录
            $importJob = ImportJob::create([
                'filename' => $filename,
                'original_filename' => $originalFilename,
                'status' => 'pending',
                'user_id' => Auth::id(),
                'insert_date' => $insertDate,
                'is_replacing_existing' => $isReplacingExisting,
            ]);
            
            // 检查记录是否成功创建
            if (!$importJob || !$importJob->id) {
                // 删除已上传的文件
                @unlink($fullPath);
                return back()->with('error', '创建导入任务记录失败');
            }
            
            // 记录日志
            Log::info('导入任务创建成功', [
                'job_id' => $importJob->id,
                'filename' => $filename,
                'original_filename' => $originalFilename,
                'insert_date' => $insertDate
            ]);
            
            // 分发异步任务处理导入
            ProcessImport::dispatch($importJob);
            
            return redirect()->route('import.index')->with('success', 
                "文件已上传，正在后台处理导入。您可以在导入记录中查看进度。" . 
                ($isReplacingExisting ? "注意：将会替换已有的 {$insertDate} 数据。" : "")
            );
        } catch (\Exception $e) {
            Log::error('文件上传失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return back()->with('error', '导入失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 显示导入任务的详细进度
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $importJob = ImportJob::findOrFail($id);
        
        // 检查是否为当前用户的导入任务
        if ($importJob->user_id !== Auth::id()) {
            abort(403, '您无权查看此导入任务');
        }
        
        return view('import.show', compact('importJob'));
    }
    
    /**
     * 获取导入任务的进度
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function progress($id)
    {
        $importJob = ImportJob::findOrFail($id);
        
        // 检查是否为当前用户的导入任务
        if ($importJob->user_id !== Auth::id()) {
            return response()->json(['error' => '您无权查看此导入任务'], 403);
        }
        
        return response()->json([
            'status' => $importJob->status,
            'total_rows' => $importJob->total_rows,
            'processed_rows' => $importJob->processed_rows,
            'inserted_rows' => $importJob->inserted_rows,
            'updated_rows' => $importJob->updated_rows,
            'error_rows' => $importJob->error_rows,
            'error_message' => $importJob->error_message,
            'error_details' => $importJob->error_details_array,
            'progress_percentage' => $importJob->progress_percentage,
            'started_at' => $importJob->started_at ? $importJob->started_at->format('Y-m-d H:i:s') : null,
            'completed_at' => $importJob->completed_at ? $importJob->completed_at->format('Y-m-d H:i:s') : null,
        ]);
    }
    
    /**
     * 清空所有导入的数据
     *
     * @return \Illuminate\Http\Response
     */
    public function clearData()
    {
        Log::info('开始清空数据');
        
        try {
            // 统计要删除的数据量
            $transactionCount = Transaction::count();
            $roiCount = RoiCalculation::count();
            $channelCount = Channel::count();
            $expenseCount = Expense::count();
            $exchangeRateCount = ExchangeRate::count();
            
            // 禁用外键约束 - 不使用事务包装这部分操作，因为它可能会隐式提交事务
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            
            // 按照外键依赖顺序清空表
            Transaction::truncate();
            RoiCalculation::truncate();
            Expense::truncate();
            ExchangeRate::truncate();
            Channel::truncate();
            
            // 重新启用外键约束
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            
            Log::info('数据清空成功', [
                'transactions' => $transactionCount,
                'roi_calculations' => $roiCount,
                'channels' => $channelCount,
                'expenses' => $expenseCount,
                'exchange_rates' => $exchangeRateCount
            ]);
            
            return redirect()->route('import.index')->with('success', 
                "所有数据已清空。删除了 {$transactionCount} 条交易记录、{$roiCount} 条ROI计算记录、" . 
                "{$channelCount} 个渠道、{$expenseCount} 条消耗记录和 {$exchangeRateCount} 条汇率记录。");
            
        } catch (\Exception $e) {
            // 确保重新启用外键约束，防止数据库保持在不安全状态
            try {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            } catch (\Exception $ex) {
                Log::error('重新启用外键约束失败: ' . $ex->getMessage());
            }
            
            Log::error('清空数据失败', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()->route('import.index')->with('error', 
                '清空数据失败: ' . $e->getMessage());
        }
    }

    /**
     * 异步上传处理 Excel/CSV 导入
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function uploadAsync(Request $request)
    {
        $request->validate([
            'excel_file' => 'required|file|mimes:csv,txt',
            'insert_date' => 'nullable|date',
        ]);

        try {
            $file = $request->file('excel_file');
            if (!$file) {
                return response()->json(['success' => false, 'message' => '未能获取上传的文件'], 400);
            }
            
            $originalFilename = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            
            // 确保文件扩展名有效，只接受CSV格式
            if (!in_array(strtolower($extension), ['csv', 'txt'])) {
                return response()->json(['success' => false, 'message' => '不支持的文件类型: ' . $extension . '，只支持CSV格式'], 400);
            }
            
            // 获取插入日期，默认为当天
            $insertDate = $request->filled('insert_date') 
                ? Carbon::parse($request->input('insert_date'))->format('Y-m-d')
                : Carbon::now()->format('Y-m-d');
            
            // 检查是否存在相同insert_date的数据
            $isReplacingExisting = Transaction::where('insert_date', $insertDate)->exists();
            
            // 生成唯一文件名
            $filename = Str::uuid() . '.' . $extension;
            $importDir = storage_path('app/imports');
            $fullPath = $importDir . '/' . $filename;
            
            // 创建导入任务记录
            $importJob = ImportJob::create([
                'filename' => $filename,
                'original_filename' => $originalFilename,
                'status' => 'pending',
                'user_id' => Auth::id(),
                'insert_date' => $insertDate,
                'is_replacing_existing' => $isReplacingExisting,
            ]);
            
            // 检查记录是否成功创建
            if (!$importJob || !$importJob->id) {
                return response()->json(['success' => false, 'message' => '创建导入任务记录失败'], 500);
            }
            
            // 立即返回响应，让客户端知道我们已收到文件
            $jobId = $importJob->id;
            $response = response()->json([
                'success' => true, 
                'message' => '文件已接收，开始后台处理',
                'job_id' => $jobId,
                'is_replacing' => $isReplacingExisting
            ]);
            
            // 关闭输出缓冲区
            if (ob_get_level()) ob_end_clean();
            
            // 发送响应头
            header('Connection: close');
            header('Content-Length: '.ob_get_length());
            
            // 发送响应并关闭连接
            echo $response->getContent();
            
            // 刷新并关闭当前会话
            session_write_close();
            
            // 告诉PHP忽略用户中止
            ignore_user_abort(true);
            
            // 设置无限执行时间
            set_time_limit(0);
            
            // 进行文件处理，不阻塞客户端
            if (!file_exists($importDir)) {
                mkdir($importDir, 0755, true);
            }
            
            // 移动上传的文件
            if (move_uploaded_file($file->getRealPath(), $fullPath)) {
                // 记录日志
                Log::info('异步上传：文件保存成功', [
                    'job_id' => $jobId,
                    'filename' => $filename,
                    'original_filename' => $originalFilename
                ]);
                
                // 分发异步任务处理导入
                ProcessImport::dispatch($importJob);
            } else {
                // 记录错误并更新任务状态
                Log::error('异步上传：文件保存失败', [
                    'job_id' => $jobId,
                    'filename' => $filename
                ]);
                
                $importJob->update([
                    'status' => 'failed',
                    'error_message' => '文件保存失败',
                    'completed_at' => now()
                ]);
            }
            
            exit(0); // 脚本结束，但服务器继续处理
            
        } catch (\Exception $e) {
            Log::error('异步上传失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json(['success' => false, 'message' => '导入失败: ' . $e->getMessage()], 500);
        }
    }

    /**
     * 删除导入任务
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $importJob = ImportJob::findOrFail($id);
        
        // 检查是否为当前用户的导入任务
        if ($importJob->user_id !== Auth::id()) {
            abort(403, '您无权删除此导入任务');
        }
        
        try {
            // 如果文件存在，删除文件
            $filePath = storage_path('app/imports/' . $importJob->filename);
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            
            // 删除导入任务记录
            $importJob->delete();
            
            return redirect()->route('import.index')->with('success', '导入任务已成功删除');
        } catch (\Exception $e) {
            Log::error('删除导入任务失败', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            
            return redirect()->route('import.index')->with('error', '删除导入任务失败: ' . $e->getMessage());
        }
    }
}
