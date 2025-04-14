<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Channel;
use App\Models\RoiCalculation;
use App\Models\Transaction;
use App\Models\Expense;
use App\Models\ExchangeRate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    /**
     * 显示仪表盘
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        try {
            // 获取渠道数量
            $channelsCount = Channel::count();
            $channels = Channel::all();
            
            // 获取筛选参数
            $channelId = $request->input('channel_id');
            $daterange = $request->input('daterange');
            
            // 处理日期范围
            if ($daterange) {
                $dates = explode(' - ', $daterange);
                $startDate = Carbon::parse($dates[0]);
                $endDate = Carbon::parse($dates[1]);
            } else {
                // 默认日期范围（最近30天，扩展到40天以便计算40日ROI）
                $endDate = Carbon::today();
                $startDate = (clone $endDate)->subDays(29); 
            }
            
            // 筛选是否激活
            $hasFilters = $request->has('hasFilters') || $channelId || $daterange;
            
            // 日期范围字符串
            $startDateStr = $startDate->format('Y-m-d');
            $endDateStr = $endDate->format('Y-m-d');
            
            // 缓存键包含筛选条件
            $cacheKey = "dashboard_data_{$startDateStr}_{$endDateStr}_" . ($channelId ?: 'all');
            
            // 尝试从缓存获取数据
            if (config('app.debug') === false && !$request->has('refresh') && cache()->has($cacheKey)) {
                $cachedData = cache()->get($cacheKey);
                return view('dashboard', $cachedData);
            }
            
            // 获取交易总数
            $transactionsCount = Transaction::count();
            
            // 生成日期数组（降序排列，最新日期在前）
            $dates = [];
            $displayDates = []; // 显示用的日期
            
            // 设置要展示的日期范围
            $current = (clone $endDate);
            while ($current->gte($startDate)) {
                $dateStr = $current->format('Y-m-d');
                $dates[] = $dateStr;
                $displayDates[] = $dateStr;
                $current->subDay();
            }
            
            // 1. 按日期和渠道获取注册人数、充值人数
            $statsQuery = DB::table('transactions')
                ->select(
                    DB::raw('DATE(registration_time) as date'),
                    'channel_id',
                    DB::raw('COUNT(DISTINCT member_id) as total_registrations'),
                    DB::raw('COUNT(DISTINCT CASE WHEN balance_difference > 0 THEN member_id END) as deposit_users')
                )
                ->whereBetween('registration_time', [$startDate->startOfDay(), $endDate->endOfDay()]);
                
            // 如果指定了渠道ID，添加渠道筛选条件
            if ($channelId) {
                $statsQuery->where('channel_id', $channelId);
            }
            
            $statsQuery->groupBy('date', 'channel_id');
            
            // 获取所有渠道或指定渠道的汇总数据
            $transactionStats = $statsQuery->get();
            
            // 按日期分组统计数据，合并所有渠道
            $statsByDate = [];
            $channelIDsData = [];
            
            foreach ($transactionStats as $stat) {
                $dateStr = $stat->date;
                
                // 初始化日期数据
                if (!isset($statsByDate[$dateStr])) {
                    $statsByDate[$dateStr] = [
                        'registrations' => 0,
                        'deposit_users' => 0,
                        'channels' => []
                    ];
                }
                
                // 汇总所有渠道数据
                $statsByDate[$dateStr]['registrations'] += $stat->total_registrations;
                $statsByDate[$dateStr]['deposit_users'] += $stat->deposit_users;
                
                // 记录渠道数据（用于后续分析）
                if (!isset($statsByDate[$dateStr]['channels'][$stat->channel_id])) {
                    $statsByDate[$dateStr]['channels'][$stat->channel_id] = [
                        'registrations' => $stat->total_registrations,
                        'deposit_users' => $stat->deposit_users
                    ];
                }
                
                // 收集渠道ID
                if (!in_array($stat->channel_id, $channelIDsData)) {
                    $channelIDsData[] = $stat->channel_id;
                }
            }
            
            // 获取消耗数据
            $expensesQuery = Expense::select('date', 'channel_id', 'amount')
                ->whereBetween('date', [$startDateStr, $endDateStr]);
                
            // 如果指定了渠道ID，添加渠道筛选条件    
            if ($channelId) {
                $expensesQuery->where(function($query) use ($channelId) {
                    $query->where('channel_id', $channelId)
                        ->orWhere('is_default', true);
                });
            }
            
            $expensesByDate = $expensesQuery->get();
            
            // 组织消耗数据
            $expensesData = [];
            foreach ($expensesByDate as $expense) {
                $dateStr = $expense->date->format('Y-m-d');
                if (!isset($expensesData[$dateStr])) {
                    $expensesData[$dateStr] = 0;
                }
                
                // 只有在筛选所有渠道或匹配当前渠道时才累加
                if (!$channelId || $expense->channel_id == $channelId) {
                    $expensesData[$dateStr] += $expense->amount;
                }
            }
            
            // 获取默认消耗数据
            $defaultExpensesQuery = Expense::where('is_default', true);
            if ($channelId) {
                $defaultExpensesQuery->where('channel_id', $channelId);
            }
            $defaultExpenses = $defaultExpensesQuery->get();
            $totalDefaultExpense = $defaultExpenses->sum('amount');
            
            // 获取汇率数据
            $ratesByDate = ExchangeRate::whereBetween('date', [$startDateStr, $endDateStr])
                ->get()
                ->keyBy(function($item) {
                    return $item->date->format('Y-m-d');
                });
            
            // 获取默认汇率
            $defaultRate = ExchangeRate::where('is_default', true)->first();
            $defaultRateValue = $defaultRate ? $defaultRate->rate : 0;
            
            // 计算每日统计数据和ROI
            $dailyStats = [];
            $summaryData = [
                'date' => '汇总',
                'registrations' => 0,
                'expense' => 0,
                'balance' => 0,
                'paying_users' => 0,
                'conversion_rate' => 0,
                'arpu' => 0,
                'cpa' => 0,
                'daily_roi' => 0,
                'first_deposit_price' => 0,
                'roi_trends' => array_fill_keys([2, 3, 5, 7, 14, 30, 40], 0),
                'roi_after_40' => 0
            ];
            
            // 直接从roi_calculations表获取ROI数据
            $roiQuery = RoiCalculation::whereBetween('date', [$startDateStr, $endDateStr]);
            
            // 如果指定了渠道ID，添加渠道筛选条件
            if ($channelId) {
                $roiQuery->where('channel_id', $channelId);
            }
            
            $roiData = $roiQuery->get()
                ->groupBy(function($item) {
                    // 确保日期格式一致，统一使用Y-m-d格式
                    return $item->date->format('Y-m-d');
                })
                ->map(function($group) {
                    return $group->groupBy('day_count')
                        ->map(function($items) {
                            // 合并所有渠道的ROI数据
                            $totalExpense = $items->sum('expense');
                            $totalBalance = $items->sum('cumulative_balance');
                            $avgExchangeRate = $items->avg('exchange_rate'); // 使用平均汇率
                            
                            // 计算综合ROI - 直接使用数据库中存储的roi_percentage值
                            // 不要在这里重新计算，避免出现不一致
                            $roiPercentage = $items->avg('roi_percentage');
                            
                            return [
                                'roi_percentage' => $roiPercentage,
                                'cumulative_balance' => $totalBalance,
                                'expense' => $totalExpense
                            ];
                        });
                });
            
            foreach ($dates as $dateStr) {
                // 获取当天的基础统计数据
                $stats = $statsByDate[$dateStr] ?? null;
                
                // 获取或计算当天的消耗
                $totalExpenseToday = $expensesData[$dateStr] ?? $totalDefaultExpense;
                
                // 获取ROI数据
                $roiDataForDate = $roiData[$dateStr] ?? collect();
                
                // 只有当有注册数据或消耗数据或roi数据时才添加此日期
                $hasData = false;
                
                // 检查是否有注册数据
                if ($stats && ($stats['registrations'] > 0 || $stats['deposit_users'] > 0)) {
                    $hasData = true;
                }
                
                // 检查是否有消耗数据（不考虑默认消耗）
                if (isset($expensesData[$dateStr]) && $expensesData[$dateStr] > 0) {
                    $hasData = true;
                }
                
                // 检查是否有ROI数据
                if (count($roiDataForDate) > 0) {
                    $hasData = true;
                }
                
                // 如果没有数据则跳过此日期
                if (!$hasData) {
                    continue;
                }
                
                // 没有统计数据时跳过
                if (!$stats) {
                    continue;
                }
                
                // 基础统计
                $registrationCount = $stats['registrations'];
                $payingUsers = $stats['deposit_users'];
                
                // 获取当天的汇率
                $rateValue = isset($ratesByDate[$dateStr]) ? $ratesByDate[$dateStr]->rate : $defaultRateValue;
                
                // 获取当日ROI (day_count = 1) - 直接使用数据库中的ROI值
                $dayRoi = isset($roiDataForDate[1]) ? $roiDataForDate[1]['roi_percentage'] : 0;
                $dailyBalance = isset($roiDataForDate[1]) ? $roiDataForDate[1]['cumulative_balance'] : 0;
                
                // 计算付费率
                $conversionRate = $registrationCount > 0 ? ($payingUsers / $registrationCount) * 100 : 0;
                
                // 计算衍生指标
                $arpu = $registrationCount > 0 ? $dailyBalance / $registrationCount : 0;
                $cpa = $registrationCount > 0 ? $totalExpenseToday / $registrationCount : 0;
                $firstDepositPrice = $payingUsers > 0 ? $totalExpenseToday / $payingUsers : 0;
                
                // 更新汇总数据
                $summaryData['registrations'] += $registrationCount;
                $summaryData['expense'] += $totalExpenseToday;
                $summaryData['balance'] += $dailyBalance;
                $summaryData['paying_users'] += $payingUsers;
                
                // 初始化ROI趋势数据数组
                $roiTrends = array_fill_keys([2, 3, 5, 7, 14, 30, 40], 0);
                $roiAfter40 = 0;
                
                // 计算从当前日期到最新日期的天数差
                $currentDate = Carbon::parse($dateStr);
                $today = Carbon::today();
                $daysDifference = $currentDate->diffInDays($today);
                
                // 填充多日ROI数据 - 直接使用数据库中的值
                $dayRanges = [2, 3, 5, 7, 14, 30, 40];
                foreach ($dayRanges as $days) {
                    // 如果是40日ROI且天数差小于40天，则显示为0
                    if ($days == 40 && $daysDifference < 40) {
                        $roiTrends[$days] = 0;
                    } else {
                    // 直接使用存储的百分比值，不进行额外处理
                    $roiTrends[$days] = isset($roiDataForDate[$days]) ? $roiDataForDate[$days]['roi_percentage'] : 0;
                    }
                }
                
                // 40日后ROI，只有满足40天才显示实际值
                if ($daysDifference < 40) {
                    $roiAfter40 = 0;
                } else {
                $roiAfter40 = isset($roiDataForDate[40]) ? $roiDataForDate[40]['roi_percentage'] : 0;
                }
                
                // 初始化每日统计 - 确保ROI值直接使用
                $dailyStats[$dateStr] = [
                    'date' => $dateStr,
                    'registrations' => $registrationCount,
                    'expense' => $totalExpenseToday,
                    'balance' => $dailyBalance,
                    'daily_roi' => $dayRoi,  // 直接使用数据库中的值，不进行四舍五入
                    'paying_users' => $payingUsers,
                    'conversion_rate' => round($conversionRate, 2),
                    'arpu' => round($arpu, 2),
                    'cpa' => round($cpa, 2),
                    'first_deposit_price' => round($firstDepositPrice, 2),
                    'roi_trends' => $roiTrends,  // 直接使用数据库中的值，不进行四舍五入
                    'roi_after_40' => $roiAfter40,
                    'rate_value' => $rateValue
                ];
            }
            
            // 准备图表数据
            $chartSeries = [];
            $chartDayRanges = [1, 2, 3, 5, 7, 14, 30, 40]; // 包括1日ROI
            $chartColors = [
                'rgba(24, 144, 255, 1)',    // 当日ROI - 蓝色
                'rgba(47, 194, 91, 1)',     // 2日ROI - 绿色
                'rgba(250, 84, 28, 1)',     // 3日ROI - 橙红色
                'rgba(250, 173, 20, 1)',    // 5日ROI - 橙黄色
                'rgba(114, 46, 209, 1)',    // 7日ROI - 紫色
                'rgba(245, 34, 45, 1)',     // 14日ROI - 红色
                'rgba(19, 194, 194, 1)',    // 30日ROI - 青色
                'rgba(82, 196, 26, 1)',     // 40日ROI - 浅绿色
            ];
            
            // 获取实际显示的日期
            $actualDisplayDates = array_keys($dailyStats);
            sort($actualDisplayDates); // 按日期升序排序
            
            foreach ($chartDayRanges as $index => $days) {
                $seriesData = [];
                
                // 按时间顺序排序，最早日期在左边（不需要反转）
                foreach ($actualDisplayDates as $dateStr) {
                    if ($days == 1) {
                        // 1日ROI等于当日ROI
                        $value = $dailyStats[$dateStr]['daily_roi'] ?? 0;
                    } else {
                        $value = $dailyStats[$dateStr]['roi_trends'][$days] ?? 0;
                    }
                    $seriesData[] = $value;
                }
                
                $chartSeries[] = [
                    'label' => $days == 1 ? "当日ROI" : "{$days}日ROI",
                    'data' => $seriesData,
                    'borderColor' => $chartColors[$index % count($chartColors)],
                    'backgroundColor' => str_replace('1)', '0.1)', $chartColors[$index % count($chartColors)]),
                    'borderWidth' => 2,
                    'pointRadius' => 3,
                    'tension' => 0.2
                ];
            }
            
            // 系统统计信息
            $stats = [
                'transactions_count' => $transactionsCount,
                'channels_count' => $channelsCount,
                'latest_date' => $actualDisplayDates ? end($actualDisplayDates) : Carbon::today()->format('Y-m-d'),
                'date_range' => [
                    'start' => $startDateStr,
                    'end' => $endDateStr
                ]
            ];
            
            // ======= 修改汇总数据计算方式 =======
            // 获取最新插入日期（用于计算不受日期筛选影响的汇总数据）
            $latestInsertDateQuery = DB::table('transactions');
            if ($channelId) {
                $latestInsertDateQuery->where('channel_id', $channelId);
            }
            $latestInsertDate = $latestInsertDateQuery->max('insert_date');
            
            if ($latestInsertDate) {
                // 单次查询获取所有汇总数据（替代多次查询）
                $summaryStats = DB::table('transactions')
                    ->where('insert_date', $latestInsertDate)
                    ->when($channelId, function ($query) use ($channelId) {
                        return $query->where('channel_id', $channelId);
                    })
                    ->selectRaw('
                        COUNT(DISTINCT member_id) as total_registrations,
                        COUNT(DISTINCT CASE WHEN balance_difference > 0 THEN member_id END) as deposit_users,
                        SUM(balance_difference) as total_balance
                    ')->first();
                
                // 获取所有消耗（对应最新插入日期的数据）
                $totalExpense = DB::table('expenses')
                    ->when($channelId, function ($query) use ($channelId) {
                        return $query->where(function($q) use ($channelId) {
                            $q->where('channel_id', $channelId)
                                ->orWhere('is_default', true);
                        });
                    })
                    ->sum('amount');
                
                // 更新汇总数据
                $summaryData['registrations'] = $summaryStats->total_registrations ?? 0;
                $summaryData['paying_users'] = $summaryStats->deposit_users ?? 0;
                $summaryData['balance'] = $summaryStats->total_balance ?? 0;
                $summaryData['expense'] = $totalExpense;
                
                // 重新计算衍生指标
            if ($summaryData['registrations'] > 0) {
                $summaryData['conversion_rate'] = round(($summaryData['paying_users'] / $summaryData['registrations']) * 100, 2);
                $summaryData['arpu'] = round($summaryData['balance'] / $summaryData['registrations'], 2);
                $summaryData['cpa'] = round($summaryData['expense'] / $summaryData['registrations'], 2);
                    
                    // 添加计算公式详情
                    $summaryData['formula_details']['cpa'] = "消耗 / 新增用户数 = {$summaryData['expense']} / {$summaryData['registrations']} = {$summaryData['cpa']}";
                    $summaryData['formula_details']['conversion_rate'] = "首充人数 / 新增用户数 × 100% = {$summaryData['paying_users']} / {$summaryData['registrations']} × 100% = {$summaryData['conversion_rate']}%";
            }
            
            if ($summaryData['paying_users'] > 0) {
                $summaryData['first_deposit_price'] = round($summaryData['expense'] / $summaryData['paying_users'], 2);
                    
                    // 添加计算公式详情
                    $summaryData['formula_details']['first_deposit_price'] = "消耗 / 首充人数 = {$summaryData['expense']} / {$summaryData['paying_users']} = {$summaryData['first_deposit_price']}";
                }
            }
            
            // 初始化ROI趋势数据数组
            $summaryData['roi_trends'] = array_fill_keys([2, 3, 5, 7, 14, 30, 40], 0);
            $summaryData['roi_after_40'] = 0;
            
            // 添加计算过程详情数组
            $summaryData['roi_calculations'] = [
                'daily' => '',
                'trends' => array_fill_keys([2, 3, 5, 7, 14, 30, 40], ''),
                'after_40' => ''
            ];
            
            // 获取最早一次数据插入的时间
            $earliestInsertDate = $latestInsertDate ? DB::table('transactions')
                ->when($channelId, function ($query) use ($channelId) {
                    return $query->where('channel_id', $channelId);
                })
                ->min('insert_date') : null;
            
            // 如果没有找到插入日期，使用今天的日期作为默认值
            if (empty($earliestInsertDate)) {
                $earliestInsertDate = Carbon::today()->format('Y-m-d');
            }
            
            // 使用默认汇率
            $rateValue = $defaultRateValue;
            
            // 确保总消耗不为0
            if ($summaryData['expense'] > 0 && $rateValue > 0) {
                // 预先查询所有相关的交易数据，避免多次查询
                $allTransactions = DB::table('transactions')
                    ->select('insert_date', DB::raw('SUM(balance_difference) as total_balance'))
                    ->when($channelId, function ($query) use ($channelId) {
                        return $query->where('channel_id', $channelId);
                    })
                    ->groupBy('insert_date')
                    ->get()
                    ->keyBy('insert_date');
                
                // 获取该插入日期的总充提差额
                $totalBalanceDifference = $allTransactions->get($earliestInsertDate)->total_balance ?? 0;
                
                // 计算当日ROI
                $summaryData['daily_roi'] = ($totalBalanceDifference / $rateValue) / $summaryData['expense'] * 100;
                $summaryData['roi_calculations']['daily'] = "({$totalBalanceDifference} / {$rateValue}) / {$summaryData['expense']} * 100 = " . number_format($summaryData['daily_roi'], 2) . "%";
                
                // 检查后续日期是否有数据
                $dayRanges = [2, 3, 5, 7, 14, 30, 40];
                
                // 提前计算所有目标日期
                $targetDates = [];
                $earliestDateObj = Carbon::parse($earliestInsertDate);
                foreach ($dayRanges as $day) {
                    $targetDate = $earliestDateObj->copy()->addDays($day - 1)->format('Y-m-d');
                    $targetDates[$day] = $targetDate;
                }
                
                foreach ($dayRanges as $day) {
                    $targetDate = $targetDates[$day];
                    
                    // 直接从预先查询的数据中获取
                    if ($allTransactions->has($targetDate)) {
                        $targetDayBalance = $allTransactions->get($targetDate)->total_balance;
                        
                        // 计算该天的ROI
                        $summaryData['roi_trends'][$day] = ($targetDayBalance / $rateValue) / $summaryData['expense'] * 100;
                        $summaryData['roi_calculations']['trends'][$day] = "({$targetDayBalance} / {$rateValue}) / {$summaryData['expense']} * 100 = " . number_format($summaryData['roi_trends'][$day], 2) . "%";
                    } else {
                        // 没有数据，ROI保持为0
                        $summaryData['roi_trends'][$day] = 0;
                        $summaryData['roi_calculations']['trends'][$day] = "无{$targetDate}数据，ROI为0";
                    }
                }
                
                // 40日后ROI - 检查是否有40天后的数据
                $afterFortyDate = $targetDates[40];
                
                // 计算40天后的所有交易数据
                $afterFortyBalance = DB::table('transactions')
                    ->where('insert_date', '>=', $afterFortyDate)
                    ->when($channelId, function ($query) use ($channelId) {
                        return $query->where('channel_id', $channelId);
                    })
                    ->sum('balance_difference');
                
                if ($afterFortyBalance > 0) {
                    // 计算40天后的ROI
                    $summaryData['roi_after_40'] = ($afterFortyBalance / $rateValue) / $summaryData['expense'] * 100;
                    $summaryData['roi_calculations']['after_40'] = "({$afterFortyBalance} / {$rateValue}) / {$summaryData['expense']} * 100 = " . number_format($summaryData['roi_after_40'], 2) . "%";
                } else {
                    // 没有40天后的数据，ROI为0
                    $summaryData['roi_after_40'] = 0;
                    $summaryData['roi_calculations']['after_40'] = "无{$afterFortyDate}后的数据，ROI为0";
                }
            }
            
            // 对每日数据添加计算过程详情
            foreach ($dailyStats as $dateStr => &$daily) {
                // 初始化计算过程详情数组
                $daily['roi_calculations'] = [
                    'daily' => '',
                    'trends' => array_fill_keys([2, 3, 5, 7, 14, 30, 40], ''),
                    'after_40' => ''
                ];
                
                // 添加CPA、首充单价和付费率的计算公式
                $daily['formula_details'] = [
                    'cpa' => '',
                    'first_deposit_price' => '',
                    'conversion_rate' => ''
                ];
                
                if ($daily['registrations'] > 0) {
                    $daily['formula_details']['cpa'] = "消耗 / 新增用户数 = {$daily['expense']} / {$daily['registrations']} = {$daily['cpa']}";
                    $daily['formula_details']['conversion_rate'] = "首充人数 / 新增用户数 × 100% = {$daily['paying_users']} / {$daily['registrations']} × 100% = {$daily['conversion_rate']}%";
                }
                
                if ($daily['paying_users'] > 0) {
                    $daily['formula_details']['first_deposit_price'] = "消耗 / 首充人数 = {$daily['expense']} / {$daily['paying_users']} = {$daily['first_deposit_price']}";
                }
                
                // 只对有消耗的日期计算ROI
                if ($daily['expense'] > 0 && ($daily['rate_value'] ?? 0) > 0) {
                    $currentDate = Carbon::parse($dateStr);
                    $rateValue = $daily['rate_value'] ?? $defaultRateValue;
                    
                    // 计算当日ROI - 基于当日插入的当日充提差额
                    $sameDayInsertQuery = DB::table('transactions')
                        ->where('insert_date', $dateStr)
                        ->where(DB::raw('DATE(registration_time)'), $dateStr);
                    
                    // 如果有渠道筛选
                    if ($channelId) {
                        $sameDayInsertQuery->where('channel_id', $channelId);
                    }
                    
                    $sameDayBalance = $sameDayInsertQuery->sum('balance_difference');
                    
                    if ($sameDayBalance != 0) {
                        $daily['daily_roi'] = ($sameDayBalance / $rateValue) / $daily['expense'] * 100;
                        $daily['roi_calculations']['daily'] = "({$sameDayBalance} / {$rateValue}) / {$daily['expense']} * 100 = " . number_format($daily['daily_roi'], 2) . "%";
                    } else {
                        $daily['daily_roi'] = 0;
                        $daily['roi_calculations']['daily'] = "当日({$dateStr})无插入数据，ROI为0";
                    }
                    
                    // 计算多日ROI
                    $dayRanges = [2, 3, 5, 7, 14, 30, 40];
                    foreach ($dayRanges as $day) {
                        // 计算目标日期
                        $targetDate = $currentDate->copy()->addDays($day - 1)->format('Y-m-d');
                        
                        // 检查目标日期是否有插入数据
                        $hasTargetDateData = DB::table('transactions')
                            ->where('insert_date', $targetDate)
                            ->where(DB::raw('DATE(registration_time)'), $dateStr)
                            ->exists();
                        
                        if ($hasTargetDateData) {
                            // 查询目标日期插入的与当日关联的充提差额
                            $targetDateQuery = DB::table('transactions')
                                ->where('insert_date', $targetDate)
                                ->where(DB::raw('DATE(registration_time)'), $dateStr);
                            
                            // 如果有渠道筛选
                            if ($channelId) {
                                $targetDateQuery->where('channel_id', $channelId);
                            }
                            
                            $targetDateBalance = $targetDateQuery->sum('balance_difference');
                            
                            // 计算ROI
                            $daily['roi_trends'][$day] = ($targetDateBalance / $rateValue) / $daily['expense'] * 100;
                            $daily['roi_calculations']['trends'][$day] = "({$targetDateBalance} / {$rateValue}) / {$daily['expense']} * 100 = " . number_format($daily['roi_trends'][$day], 2) . "%";
                        } else {
                            $daily['roi_trends'][$day] = 0;
                            $daily['roi_calculations']['trends'][$day] = "无{$targetDate}插入关联{$dateStr}的数据，ROI为0";
                        }
                    }
                    
                    // 40日后ROI
                    $afterFortyDate = $currentDate->copy()->addDays(40)->format('Y-m-d');
                    $hasAfterFortyData = DB::table('transactions')
                        ->where('insert_date', '>=', $afterFortyDate)
                        ->where(DB::raw('DATE(registration_time)'), $dateStr)
                        ->exists();
                    
                    if ($hasAfterFortyData) {
                        // 查询40天后插入的与当日关联的充提差额
                        $afterFortyQuery = DB::table('transactions')
                            ->where('insert_date', '>=', $afterFortyDate)
                            ->where(DB::raw('DATE(registration_time)'), $dateStr);
                        
                        // 如果有渠道筛选
                        if ($channelId) {
                            $afterFortyQuery->where('channel_id', $channelId);
                        }
                        
                        $afterFortyBalance = $afterFortyQuery->sum('balance_difference');
                        
                        // 计算ROI
                        $daily['roi_after_40'] = ($afterFortyBalance / $rateValue) / $daily['expense'] * 100;
                        $daily['roi_calculations']['after_40'] = "({$afterFortyBalance} / {$rateValue}) / {$daily['expense']} * 100 = " . number_format($daily['roi_after_40'], 2) . "%";
                    } else {
                        $daily['roi_after_40'] = 0;
                        $daily['roi_calculations']['after_40'] = "无{$afterFortyDate}后插入关联{$dateStr}的数据，ROI为0";
                    }
                } else {
                    // 无消耗或无汇率，所有ROI为0
                    $daily['daily_roi'] = 0;
                    $daily['roi_calculations']['daily'] = "无消耗或无汇率，ROI为0";
                    
                    foreach ([2, 3, 5, 7, 14, 30, 40] as $day) {
                        $daily['roi_trends'][$day] = 0;
                        $daily['roi_calculations']['trends'][$day] = "无消耗或无汇率，ROI为0";
                    }
                    
                    $daily['roi_after_40'] = 0;
                    $daily['roi_calculations']['after_40'] = "无消耗或无汇率，ROI为0";
                }
            }
            
            // 渠道名称
            $selectedChannelName = $channelId 
                ? ($channels->where('id', $channelId)->first()->name ?? '未知渠道') 
                : '全部渠道';
            
            // 缓存数据（如果不在调试模式）
            $viewData = compact(
                'stats',
                'dailyStats',
                'chartSeries',
                'actualDisplayDates',
                'summaryData',
                'channels',
                'channelId',
                'startDateStr',
                'endDateStr',
                'hasFilters',
                'selectedChannelName'
            );
            
            if (config('app.debug') === false) {
                cache()->put($cacheKey, $viewData, now()->addHours(1));
            }
            
            return view('dashboard', $viewData);
            
        } catch (\Exception $e) {
            // 记录错误并显示错误页面
            Log::error('仪表盘加载失败: ' . $e->getMessage());
            Log::error('错误位置: ' . $e->getFile() . ' (第 ' . $e->getLine() . ' 行)');
            Log::error('错误堆栈: ' . $e->getTraceAsString());
            
            return view('error', [
                'message' => '加载仪表盘时出错。请检查数据库是否正确配置，并确保所有表和列都已正确创建。',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 刷新仪表盘数据
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function refresh(Request $request)
    {
        try {
            // 获取筛选参数
            $channelId = $request->input('channel_id');
            $daterange = $request->input('daterange');
            
            // 处理日期范围
            if ($daterange) {
                $dates = explode(' - ', $daterange);
                $startDate = Carbon::parse($dates[0]);
                $endDate = Carbon::parse($dates[1]);
            } else {
                // 默认日期范围（最近30天，扩展到40天以便计算40日ROI）
                $endDate = Carbon::today();
                $startDate = (clone $endDate)->subDays(39);
            }
            
            // 转换为字符串格式
            $startDateStr = $startDate->format('Y-m-d');
            $endDateStr = $endDate->format('Y-m-d');
            
            // 清除缓存
            $cacheKey = "dashboard_data_{$startDateStr}_{$endDateStr}_" . ($channelId ?: 'all');
            Cache::forget($cacheKey);
            
            // 获取要重新计算的渠道IDs
            $channelIds = [];
            if ($channelId) {
                $channelIds = [$channelId];
            } else {
                $channelIds = Channel::pluck('id')->toArray();
            }
            
            // 准备日期数组
            $dates = [];
            $currentDate = Carbon::parse($startDateStr);
            $lastDate = Carbon::parse($endDateStr);
            
            while ($currentDate->lte($lastDate)) {
                $dates[] = $currentDate->format('Y-m-d');
                $currentDate->addDay();
            }
            
            // 如果指定了渠道，清除该渠道在日期范围内的ROI记录
            $roiQuery = RoiCalculation::whereBetween('date', [$startDateStr, $endDateStr]);
            if ($channelId) {
                $roiQuery->where('channel_id', $channelId);
            }
            $roiQuery->delete();
            
            // 批量计算ROI
            $processedCount = RoiCalculation::batchCalculateRois($dates, $channelIds, 40);
            
            // 构建重定向URL，保留筛选参数
            $redirectUrl = route('dashboard', [
                'refresh' => true,
                'channel_id' => $channelId,
                'daterange' => $daterange,
                'hasFilters' => $channelId || $daterange ? true : null
            ]);
            
            return redirect($redirectUrl)
                ->with('success', "仪表盘数据已重新计算，处理了{$processedCount}条记录。");
                
        } catch (\Exception $e) {
            Log::error("仪表盘数据刷新失败: " . $e->getMessage());
            return redirect()->route('dashboard')
                ->with('error', "数据刷新失败: " . $e->getMessage());
        }
    }
}

