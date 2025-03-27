<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Channel;
use App\Models\Expense;
use Illuminate\Support\Facades\DB;

class ChannelController extends Controller
{
    /**
     * 显示渠道列表
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $channels = Channel::withCount(['transactions'])
                        ->withSum('transactions', 'balance_difference')
                        ->orderBy('name')
                        ->get();
        
        // 计算total_balance（如果withSum不能正常工作）
        foreach ($channels as $channel) {
            if (!isset($channel->transactions_sum_balance_difference)) {
                $channel->total_balance = $channel->getTotalBalanceAttribute();
            } else {
                $channel->total_balance = $channel->transactions_sum_balance_difference;
            }
        }
                        
        return view('channels.index', compact('channels'));
    }

    /**
     * 显示添加渠道表单
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('channels.create');
    }

    /**
     * 保存新渠道
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:channels,name',
            'description' => 'nullable|string',
        ]);
        
        Channel::create([
            'name' => $request->input('name'),
            'description' => $request->input('description'),
        ]);
        
        return redirect()->route('channels.index')->with('success', '渠道添加成功。');
    }

    /**
     * 显示编辑渠道表单
     *
     * @param  \App\Models\Channel  $channel
     * @return \Illuminate\Http\Response
     */
    public function edit(Channel $channel)
    {
        return view('channels.edit', compact('channel'));
    }

    /**
     * 更新渠道
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Channel  $channel
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Channel $channel)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:channels,name,' . $channel->id,
            'description' => 'nullable|string',
        ]);
        
        $channel->update([
            'name' => $request->input('name'),
            'description' => $request->input('description'),
        ]);
        
        return redirect()->route('channels.index')->with('success', '渠道更新成功。');
    }

    /**
     * 删除渠道
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Channel  $channel
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, Channel $channel)
    {
        // 验证确认字符
        $request->validate([
            'confirm_text' => 'required|in:' . $channel->name,
        ], [
            'confirm_text.required' => '请输入渠道名称以确认删除操作',
            'confirm_text.in' => '输入的确认文本不匹配，必须输入完整的渠道名称: ' . $channel->name
        ]);
        
        try {
            // 开始事务
            DB::beginTransaction();
            
            // 删除相关的消耗记录
            Expense::where('channel_id', $channel->id)->delete();
            
            // 删除相关的ROI计算记录
            $channel->roiCalculations()->delete();
            
            // 删除相关的交易记录
            $channel->transactions()->delete();
            
            // 删除渠道
            $channel->delete();
            
            // 提交事务
            DB::commit();
            
            return redirect()->route('channels.index')->with('success', '渠道及其所有相关数据已成功删除。');
        } catch (\Exception $e) {
            // 回滚事务
            DB::rollBack();
            
            return redirect()->route('channels.index')->with('error', '删除渠道失败：' . $e->getMessage());
        }
    }
}
