<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Expense;
use App\Models\Channel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller
{
    /**
     * 显示消耗列表
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        // 获取日期范围
        $startDate = $request->input('start_date', Carbon::today()->subDays(30)->format('Y-m-d'));
        $endDate = $request->input('end_date', Carbon::today()->format('Y-m-d'));
        $channelId = $request->input('channel_id');
        
        // 获取所有渠道
        $channels = Channel::all();
        
        // 获取默认消耗
        $defaultExpenses = Expense::where('is_default', true)->get();
        
        // 构建查询
        $query = Expense::with('channel')
            ->where('is_default', false)
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date', 'desc');
            
        // 渠道筛选
        if ($channelId) {
            $query->where('channel_id', $channelId);
        }
        
        $expenses = $query->get();
        
        return view('expenses.index', compact('expenses', 'defaultExpenses', 'channels', 'startDate', 'endDate', 'channelId'));
    }

    /**
     * 显示添加消耗表单
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $channels = Channel::all();
        
        return view('expenses.create', compact('channels'));
    }

    /**
     * 保存新消耗
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'channel_id' => 'required',
            'amount' => 'required|numeric|min:0',
        ]);
        
        $date = $request->input('date');
        $channelId = $request->input('channel_id');
        $amount = $request->input('amount');
        
        // 处理全部渠道的情况
        if ($channelId === 'all') {
            // 获取所有渠道
            $channels = Channel::all();
            $successCount = 0;
            $skipCount = 0;
            
            foreach ($channels as $channel) {
                // 检查是否已存在该日期和渠道的消耗记录
                $exists = Expense::where('date', $date)
                         ->where('channel_id', $channel->id)
                         ->exists();
                         
                if ($exists) {
                    $skipCount++;
                    continue; // 跳过已存在的记录
                }
                
                // 为每个渠道创建消耗记录
                Expense::create([
                    'date' => $date,
                    'channel_id' => $channel->id,
                    'amount' => $amount,
                    'is_default' => false,
                ]);
                
                $successCount++;
            }
            
            // 触发所有渠道的ROI重新计算
            \App\Models\RoiCalculation::batchCalculateRois(
                [$date],
                $channels->pluck('id')->toArray()
            );
            
            $message = "成功为{$successCount}个渠道添加消耗";
            if ($skipCount > 0) {
                $message .= "，{$skipCount}个渠道已有消耗记录被跳过";
            }
            
            return redirect()->route('expenses.index')->with('success', $message);
        } else {
            // 原有的单个渠道处理逻辑
            // 检查是否已存在该日期和渠道的消耗记录
            $exists = Expense::where('date', $date)
                     ->where('channel_id', $channelId)
                     ->exists();
                     
            if ($exists) {
                return back()->with('error', '该日期和渠道的消耗记录已存在。');
            }
            
            $expense = Expense::create([
                'date' => $date,
                'channel_id' => $channelId,
                'amount' => $amount,
                'is_default' => false,
            ]);

            // 触发 ROI 重新计算
            \App\Models\RoiCalculation::batchCalculateRois(
                [$date],
                [$channelId]
            );
            
            return redirect()->route('expenses.index')->with('success', '消耗添加成功。');
        }
    }

    /**
     * 显示编辑消耗表单
     *
     * @param  \App\Models\Expense  $expense
     * @return \Illuminate\Http\Response
     */
    public function edit(Expense $expense)
    {
        $channels = Channel::all();
        
        return view('expenses.edit', compact('expense', 'channels'));
    }

    /**
     * 更新消耗
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Expense  $expense
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Expense $expense)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
        ]);
        
        $expense->update([
            'amount' => $request->input('amount'),
        ]);

        // 触发 ROI 重新计算
        \App\Models\RoiCalculation::batchCalculateRois(
            [$expense->date->format('Y-m-d')],
            [$expense->channel_id]
        );
        
        return redirect()->route('expenses.index')->with('success', '消耗更新成功。');
    }

    /**
     * 更新默认消耗
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function updateDefault(Request $request)
    {
        $request->validate([
            'channel_id' => 'required',
            'default_amount' => 'required|numeric|min:0',
        ]);
        
        $defaultAmount = $request->input('default_amount');
        $channelId = $request->input('channel_id');
        
        // 处理全部渠道的情况
        if ($channelId === 'all') {
            // 获取所有渠道
            $channels = Channel::all();
            foreach ($channels as $channel) {
                // 为每个渠道设置默认消耗
                Expense::updateOrCreate(
                    [
                        'channel_id' => $channel->id,
                        'is_default' => true,
                    ],
                    [
                        'date' => Carbon::today(),
                        'amount' => $defaultAmount,
                    ]
                );
            }
            
            // 简化: 不需要检查具体日期，直接通知用户已更新
            return redirect()->route('expenses.index')->with('success', '所有渠道的默认消耗已更新。');
        } else {
            // 原有的单个渠道处理逻辑
            // 找到现有的默认消耗或创建新的
            Expense::updateOrCreate(
                [
                    'channel_id' => $channelId,
                    'is_default' => true,
                ],
                [
                    'date' => Carbon::today(),
                    'amount' => $defaultAmount,
                ]
            );

            return redirect()->route('expenses.index')->with('success', '默认消耗已更新。');
        }
    }

    /**
     * 批量设置消耗
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function batch(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'channel_id' => 'required',
            'amount' => 'required|numeric|min:0',
        ]);
        
        $startDate = Carbon::parse($request->input('start_date'));
        $endDate = Carbon::parse($request->input('end_date'));
        $channelId = $request->input('channel_id');
        $amount = $request->input('amount');
        
        // 处理全部渠道的情况
        if ($channelId === 'all') {
            // 获取所有渠道
            $channels = Channel::all();
            $successCount = 0;
            $updateCount = 0;
            $totalDays = $startDate->diffInDays($endDate) + 1;
            
            // 为每个渠道设置指定日期范围的消耗
            foreach ($channels as $channel) {
                $channelSuccessCount = 0;
                $channelUpdateCount = 0;
                $dates = [];
                
                for ($date = clone $startDate; $date->lte($endDate); $date->addDay()) {
                    $dateStr = $date->format('Y-m-d');
                    $dates[] = $dateStr;
                    
                    // 检查是否已存在记录，计算更新和新增数量
                    $exists = Expense::where('date', $dateStr)
                            ->where('channel_id', $channel->id)
                            ->exists();
                            
                    if ($exists) {
                        $channelUpdateCount++;
                    } else {
                        $channelSuccessCount++;
                    }
                    
                    // 更新或创建记录
                    Expense::updateOrCreate(
                        [
                            'date' => $dateStr,
                            'channel_id' => $channel->id,
                        ],
                        [
                            'amount' => $amount,
                            'is_default' => false,
                        ]
                    );
                }
                
                // 触发 ROI 重新计算
                \App\Models\RoiCalculation::batchCalculateRois(
                    $dates,
                    [$channel->id]
                );
                
                $successCount += $channelSuccessCount;
                $updateCount += $channelUpdateCount;
            }
            
            $message = "成功为所有渠道设置消耗 —— 共{$totalDays}天，{$channels->count()}个渠道，{$successCount}条新增，{$updateCount}条更新";
            return redirect()->route('expenses.index')->with('success', $message);
        } else {
            // 原有的单个渠道处理逻辑
            $dates = [];
            for ($date = clone $startDate; $date->lte($endDate); $date->addDay()) {
                $dateStr = $date->format('Y-m-d');
                $dates[] = $dateStr;
                
                Expense::updateOrCreate(
                    [
                        'date' => $dateStr,
                        'channel_id' => $channelId,
                    ],
                    [
                        'amount' => $amount,
                        'is_default' => false,
                    ]
                );
            }

            // 触发 ROI 重新计算
            \App\Models\RoiCalculation::batchCalculateRois(
                $dates,
                [$channelId]
            );
            
            return redirect()->route('expenses.index')->with('success', '消耗批量设置成功。');
        }
    }

    /**
     * 删除单个消耗记录
     *
     * @param  \App\Models\Expense  $expense
     * @return \Illuminate\Http\Response
     */
    public function destroy(Expense $expense)
    {
        // 阻止删除默认消耗
        if ($expense->is_default) {
            return redirect()->route('expenses.index')->with('error', '默认消耗不可删除，请先设置其他消耗。');
        }

        $expense->delete();
        return redirect()->route('expenses.index')->with('success', '消耗记录已成功删除。');
    }

    /**
     * 批量删除消耗记录
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function batchDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:expenses,id'
        ]);

        $ids = $request->input('ids');
        
        // 检查是否包含默认消耗
        $containsDefault = Expense::whereIn('id', $ids)->where('is_default', true)->exists();
        
        if ($containsDefault) {
            return redirect()->route('expenses.index')->with('error', '批量删除失败：不能删除默认消耗。');
        }
        
        // 执行批量删除
        Expense::whereIn('id', $ids)->delete();
        
        return redirect()->route('expenses.index')->with('success', '已成功删除 ' . count($ids) . ' 条消耗记录。');
    }

    /**
     * 清除默认消耗
     *
     * @return \Illuminate\Http\Response
     */
    public function clearDefault()
    {
        // 删除所有默认消耗记录
        $count = Expense::where('is_default', true)->delete();
        
        return redirect()->route('expenses.index')->with('success', "已成功清除 {$count} 个渠道的默认消耗设置。");
    }
}
