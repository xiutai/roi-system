<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ExchangeRate;
use Carbon\Carbon;

class ExchangeRateController extends Controller
{
    /**
     * 显示汇率列表
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        // 获取日期范围
        $startDate = $request->input('start_date', Carbon::today()->subDays(30)->format('Y-m-d'));
        $endDate = $request->input('end_date', Carbon::today()->format('Y-m-d'));
        
        // 获取默认汇率
        $defaultRate = ExchangeRate::where('is_default', true)->first();
        
        // 获取日期范围内的汇率
        $exchangeRates = ExchangeRate::whereBetween('date', [$startDate, $endDate])
                                    ->orderBy('date', 'desc')
                                    ->get();
                                    
        return view('exchange_rates.index', compact('exchangeRates', 'defaultRate', 'startDate', 'endDate'));
    }

    /**
     * 显示添加汇率表单
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        // 获取默认汇率
        $defaultRate = ExchangeRate::where('is_default', true)->first();
        
        return view('exchange_rates.create', compact('defaultRate'));
    }

    /**
     * 保存新汇率
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'date' => 'required|date|unique:exchange_rates,date',
            'rate' => 'required|numeric|min:0',
        ]);
        
        ExchangeRate::create([
            'date' => $request->input('date'),
            'rate' => $request->input('rate'),
            'is_default' => false,
        ]);
        
        return redirect()->route('exchange_rates.index')->with('success', '汇率添加成功。');
    }

    /**
     * 显示编辑汇率表单
     *
     * @param  \App\Models\ExchangeRate  $exchangeRate
     * @return \Illuminate\Http\Response
     */
    public function edit(ExchangeRate $exchangeRate)
    {
        return view('exchange_rates.edit', compact('exchangeRate'));
    }

    /**
     * 更新汇率
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\ExchangeRate  $exchangeRate
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, ExchangeRate $exchangeRate)
    {
        $request->validate([
            'rate' => 'required|numeric|min:0',
        ]);
        
        $exchangeRate->update([
            'rate' => $request->input('rate'),
        ]);
        
        return redirect()->route('exchange_rates.index')->with('success', '汇率更新成功。');
    }

    /**
     * 更新默认汇率
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function updateDefault(Request $request)
    {
        $request->validate([
            'default_rate' => 'required|numeric|min:0',
        ]);
        
        // 先找到现有的默认汇率
        $defaultRate = ExchangeRate::where('is_default', true)->first();
        
        if ($defaultRate) {
            $defaultRate->update([
                'rate' => $request->input('default_rate'),
            ]);
        } else {
            // 如果没有默认汇率，则创建一个
            // 使用一个特殊日期作为默认汇率的日期，避免与实际日期冲突
            $defaultDate = '1900-01-01'; // 使用远古日期作为默认汇率的标记
            
            // 检查是否已经存在这个特殊日期的记录
            $existingRate = ExchangeRate::where('date', $defaultDate)->first();
            
            if ($existingRate) {
                // 如果特殊日期已存在记录，则更新它
                $existingRate->update([
                    'rate' => $request->input('default_rate'),
                    'is_default' => true,
                ]);
            } else {
                // 创建新记录
                ExchangeRate::create([
                    'date' => $defaultDate,
                    'rate' => $request->input('default_rate'),
                    'is_default' => true,
                ]);
            }
        }
        
        return redirect()->route('exchange_rates.index')->with('success', '默认汇率已更新。');
    }

    /**
     * 批量设置汇率
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function batch(Request $request)
    {
        $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'rate' => 'required|numeric|min:0',
        ]);
        
        $startDate = Carbon::parse($request->input('start_date'));
        $endDate = Carbon::parse($request->input('end_date'));
        $rate = $request->input('rate');
        
        for ($date = clone $startDate; $date->lte($endDate); $date->addDay()) {
            ExchangeRate::updateOrCreate(
                ['date' => $date->format('Y-m-d')],
                ['rate' => $rate, 'is_default' => false]
            );
        }
        
        return redirect()->route('exchange_rates.index')->with('success', '汇率批量设置成功。');
    }

    /**
     * 删除单个汇率记录
     *
     * @param  \App\Models\ExchangeRate  $exchangeRate
     * @return \Illuminate\Http\Response
     */
    public function destroy(ExchangeRate $exchangeRate)
    {
        // 阻止删除默认汇率
        if ($exchangeRate->is_default) {
            return redirect()->route('exchange_rates.index')->with('error', '默认汇率不可删除，请先设置其他汇率为默认。');
        }

        $exchangeRate->delete();
        return redirect()->route('exchange_rates.index')->with('success', '汇率记录已成功删除。');
    }

    /**
     * 批量删除汇率记录
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function batchDestroy(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:exchange_rates,id'
        ]);

        $ids = $request->input('ids');
        
        // 检查是否包含默认汇率
        $containsDefault = ExchangeRate::whereIn('id', $ids)->where('is_default', true)->exists();
        
        if ($containsDefault) {
            return redirect()->route('exchange_rates.index')->with('error', '批量删除失败：不能删除默认汇率。');
        }
        
        // 执行批量删除
        ExchangeRate::whereIn('id', $ids)->delete();
        
        return redirect()->route('exchange_rates.index')->with('success', '已成功删除 ' . count($ids) . ' 条汇率记录。');
    }
}
