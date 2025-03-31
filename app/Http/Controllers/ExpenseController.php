<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Expense;
use App\Models\Channel;
use App\Models\DailyStatistic;
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
            'channel_id' => 'required|exists:channels,id',
            'amount' => 'required|numeric|min:0',
        ]);
        
        // 检查是否已存在该日期和渠道的消耗记录
        $exists = Expense::where('date', $request->input('date'))
                         ->where('channel_id', $request->input('channel_id'))
                         ->exists();
                         
        if ($exists) {
            return back()->with('error', '该日期和渠道的消耗记录已存在。');
        }
        
        $expense = Expense::create([
            'date' => $request->input('date'),
            'channel_id' => $request->input('channel_id'),
            'amount' => $request->input('amount'),
            'is_default' => false,
        ]);

        // 触发 ROI 重新计算
        \App\Models\RoiCalculation::batchCalculateRois(
            [$request->input('date')],
            [$request->input('channel_id')]
        );
        
        return redirect()->route('expenses.index')->with('success', '消耗添加成功。');
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
            'channel_id' => 'required|exists:channels,id',
            'default_amount' => 'required|numeric|min:0',
        ]);
        
        // 找到现有的默认消耗或创建新的
        Expense::updateOrCreate(
            [
                'channel_id' => $request->input('channel_id'),
                'is_default' => true,
            ],
            [
                'date' => Carbon::today(),
                'amount' => $request->input('default_amount'),
            ]
        );

        // 获取所有使用默认消耗的日期
        $dates = \App\Models\DailyStatistic::where('channel_id', $request->input('channel_id'))
            ->whereNotExists(function ($query) use ($request) {
                $query->select(\DB::raw(1))
                    ->from('expenses')
                    ->whereColumn('expenses.date', 'daily_statistics.date')
                    ->where('expenses.channel_id', $request->input('channel_id'))
                    ->where('expenses.is_default', false);
            })
            ->pluck('date')
            ->map(function ($date) {
                return $date->format('Y-m-d');
            })
            ->toArray();

        // 触发 ROI 重新计算
        if (!empty($dates)) {
            \App\Models\RoiCalculation::batchCalculateRois(
                $dates,
                [$request->input('channel_id')]
            );
        }
        
        return redirect()->route('expenses.index')->with('success', '默认消耗已更新。');
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
            'channel_id' => 'required|exists:channels,id',
            'amount' => 'required|numeric|min:0',
        ]);
        
        $startDate = Carbon::parse($request->input('start_date'));
        $endDate = Carbon::parse($request->input('end_date'));
        $channelId = $request->input('channel_id');
        $amount = $request->input('amount');
        
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
}
