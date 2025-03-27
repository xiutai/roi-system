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
            'excel_file' => 'required|file|mimes:xlsx,xls,csv,txt',
        ]);

        try {
            $file = $request->file('excel_file');
            $originalFilename = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            
            // 生成唯一文件名
            $filename = Str::uuid() . '.' . $extension;
            
            // 将文件保存到storage/app/imports目录
            $file->storeAs('imports', $filename);
            
            // 创建导入任务记录
            $importJob = ImportJob::create([
                'filename' => $filename,
                'original_filename' => $originalFilename,
                'status' => 'pending',
                'user_id' => Auth::id(),
            ]);
            
            // 分发异步任务处理导入
            ProcessImport::dispatch($importJob);
            
            return redirect()->route('import.index')->with('success', 
                "文件已上传，正在后台处理导入。您可以在导入记录中查看进度。"
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
        if ($importJob->user_id !== Auth::id() && !Auth::user()->isAdmin()) {
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
        if ($importJob->user_id !== Auth::id() && !Auth::user()->isAdmin()) {
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
     * 删除导入任务
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $importJob = ImportJob::findOrFail($id);
        
        // 检查是否为当前用户的导入任务
        if ($importJob->user_id !== Auth::id() && !Auth::user()->isAdmin()) {
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
