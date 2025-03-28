<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Channel;
use App\Models\RoiCalculation;
use App\Models\Transaction;
use App\Models\ExchangeRate;
use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RoiController extends Controller
{
    /**
     * 显示ROI列表
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        try {
            // 获取所有渠道
            $channels = Channel::all();
            
            // 确保存在渠道数据
            if ($channels->isEmpty()) {
                // 创建一个默认渠道
                $channel = Channel::create([
                    'name' => '推广注册',
                    'description' => '默认推广渠道'
                ]);
                
                // 重新获取渠道
                $channels = Channel::all();
                
                // 添加默认汇率
                if (!ExchangeRate::where('is_default', true)->exists()) {
                    ExchangeRate::create([
                        'date' => Carbon::today()->format('Y-m-d'),
                        'rate' => 6.5,
                        'is_default' => true
                    ]);
                }
            }
            
            // 获取筛选参数
            $startDate = $request->input('start_date', Carbon::today()->subDays(29)->format('Y-m-d'));
            $endDate = $request->input('end_date', Carbon::today()->format('Y-m-d'));
            $channelId = $request->input('channel_id');
            
            // 确保渠道ID是整数类型（如果存在）
            if (!empty($channelId)) {
                $channelId = (int) $channelId;
            } else {
                $channelId = null; // 确保为null而不是空字符串
            }
            
            // 初始化空集合
            $roiResults = collect();
            $dailyStats = [];
            $summaryData = null;
            $displayDates = [];
            $hasFilters = $request->has('hasFilters') || $request->has('refresh');
            
            // 只有在提交了筛选条件时才执行查询
            if ($hasFilters) {
                // 生成日期数组（降序排列，最新日期在前）
                $dates = [];
                $displayDates = []; 
                
                $end = Carbon::parse($endDate);
                $start = Carbon::parse($startDate);
                
                for ($date = clone $end; $date->gte($start); $date->subDay()) {
                    $dateStr = $date->format('Y-m-d');
                    $dates[] = $dateStr;
                }
                
                // 1. 使用数据库直接获取各日期的注册人数统计
                $registrationQuery = DB::table('transactions')
                    ->select(DB::raw('DATE(registration_time) as date'), DB::raw('COUNT(*) as count'))
                    ->whereBetween('registration_time', [$start->startOfDay(), $end->endOfDay()]);
                    
                // 如果指定了渠道，则添加渠道条件
                if (!is_null($channelId)) {
                    $registrationQuery->where('channel_id', $channelId);
                }
                
                // 执行查询并按日期分组
                $registrationStats = $registrationQuery->groupBy('date')
                    ->get()
                    ->keyBy('date');
                
                // 2. 获取充值用户数统计（balance_difference > 0 的用户）
                $depositUserQuery = DB::table('transactions')
                    ->select(DB::raw('DATE(registration_time) as date'), DB::raw('COUNT(*) as count'))
                    ->whereBetween('registration_time', [$start->startOfDay(), $end->endOfDay()])
                    ->where('balance_difference', '>', 0);
                    
                // 如果指定了渠道，则添加渠道条件
                if (!is_null($channelId)) {
                    $depositUserQuery->where('channel_id', $channelId);
                }
                
                // 执行查询并按日期分组
                $depositUserStats = $depositUserQuery->groupBy('date')
                    ->get()
                    ->keyBy('date');
                
                // 3. 获取每日充提差额总和
                $balanceQuery = DB::table('transactions')
                    ->select(DB::raw('DATE(registration_time) as date'), DB::raw('SUM(balance_difference) as sum'))
                    ->whereBetween('registration_time', [$start->startOfDay(), $end->endOfDay()]);
                    
                // 如果指定了渠道，则添加渠道条件
                if (!is_null($channelId)) {
                    $balanceQuery->where('channel_id', $channelId);
                }
                
                // 执行查询并按日期分组
                $balanceStats = $balanceQuery->groupBy('date')
                    ->get()
                    ->keyBy('date');
                
                // 4. 获取消耗数据
                $expenseQuery = Expense::whereBetween('date', [$startDate, $endDate]);
                
                // 如果指定了渠道，则添加渠道条件
                if (!is_null($channelId)) {
                    $expenseQuery->where('channel_id', $channelId);
                }
                
                $expenseDates = $expenseQuery->distinct('date')
                    ->pluck('date')
                    ->map(function($date) {
                        return $date->format('Y-m-d');
                    })
                    ->toArray();
                
                // 5. 获取每日消耗金额（按日期分组）
                $expenseByDateQuery = Expense::select('date', DB::raw('SUM(amount) as total_amount'))
                    ->whereBetween('date', [$startDate, $endDate]);
                    
                // 如果指定了渠道，则添加渠道条件
                if (!is_null($channelId)) {
                    $expenseByDateQuery->where('channel_id', $channelId);
                }
                
                $expensesByDate = $expenseByDateQuery->groupBy('date')
                    ->get()
                    ->keyBy(function($item) {
                        return $item->date->format('Y-m-d');
                    });
                
                // 获取默认消耗额度
                $defaultExpenseQuery = Expense::where('is_default', true);
                
                // 如果指定了渠道，则添加渠道条件
                if (!is_null($channelId)) {
                    $defaultExpenseQuery->where('channel_id', $channelId);
                }
                
                $defaultExpenses = $defaultExpenseQuery->get();
                $defaultExpenseTotal = $defaultExpenses->sum('amount');
                $hasDefaultExpense = $defaultExpenses->count() > 0;
                
                // 如果有默认消耗设置，我们也需要计算所有日期
                $calculateAllDates = $hasDefaultExpense;
                
                // 获取汇率数据
                $ratesByDate = ExchangeRate::whereBetween('date', [$startDate, $endDate])
                    ->get()
                    ->keyBy(function($item) {
                        return $item->date->format('Y-m-d');
                    });
                
                // 获取默认汇率
                $defaultRate = ExchangeRate::where('is_default', true)->first();
                $defaultRateValue = $defaultRate ? $defaultRate->rate : 0;
                
                // 留存率计算为：第二天充值人数 / 第一天充值人数 * 100
                $retentionRates = [];
                
                // 首先获取所有日期的用户数据，按日期分组
                $userDataQuery = DB::table('transactions')
                    ->select('member_id', DB::raw('DATE(registration_time) as date'))
                    ->whereBetween('registration_time', [$start->startOfDay(), $end->endOfDay()])
                    ->where('balance_difference', '>', 0);
                    
                // 如果指定了渠道，则添加渠道条件
                if (!is_null($channelId)) {
                    $userDataQuery->where('channel_id', $channelId);
                }
                
                $allUserData = $userDataQuery->get();
                
                // 构建每日用户ID映射，避免重复查询
                $userIdsByDate = [];
                foreach ($allUserData as $userData) {
                    $date = $userData->date;
                    if (!isset($userIdsByDate[$date])) {
                        $userIdsByDate[$date] = [];
                    }
                    $userIdsByDate[$date][] = $userData->member_id;
                }
                
                // 为所有日期计算次日留存率
                foreach ($dates as $dateStr) {
                    // 如果是最后一天或者没有当日数据，则无法计算次留
                    if ($dateStr == $endDate || !isset($userIdsByDate[$dateStr])) {
                        $retentionRates[$dateStr] = 0;
                        continue;
                    }
                    
                    // 获取当日充值用户数量
                    $todayUserIds = $userIdsByDate[$dateStr] ?? [];
                    $todayUserCount = count($todayUserIds);
                    
                    // 如果没有充值用户，留存率为0
                    if ($todayUserCount == 0) {
                        $retentionRates[$dateStr] = 0;
                        continue;
                    }
                    
                    // 获取次日日期及其充值用户
                    $nextDateObj = Carbon::parse($dateStr)->addDay();
                    $nextDateStr = $nextDateObj->format('Y-m-d');
                    
                    // 如果没有次日数据，留存率为0
                    if (!isset($userIdsByDate[$nextDateStr])) {
                        $retentionRates[$dateStr] = 0;
                        continue;
                    }
                    
                    $nextDayUserIds = $userIdsByDate[$nextDateStr] ?? [];
                    
                    // 计算交集，找出两天都有充值的用户
                    $retainedUsers = array_intersect($todayUserIds, $nextDayUserIds);
                    $retainedCount = count($retainedUsers);
                    
                    // 计算次日留存率（留存用户数 / 当日充值用户数 * 100）
                    $retentionRate = ($retainedCount / $todayUserCount) * 100;
                    $retentionRates[$dateStr] = $retentionRate;
                }
                
                // 预计算每个日期的交易统计
                $dailyStats = [];
                
                // 确保为每个日期都创建统计记录，即使没有数据
                foreach ($dates as $dateStr) {
                    // 获取当天的注册数、充值用户数和充提差额
                    $registrationCount = isset($registrationStats[$dateStr]) ? $registrationStats[$dateStr]->count : 0;
                    $payingUsers = isset($depositUserStats[$dateStr]) ? $depositUserStats[$dateStr]->count : 0;
                    $totalBalanceToday = isset($balanceStats[$dateStr]) ? $balanceStats[$dateStr]->sum : 0;
                    
                    // 计算付费率 = 充值人数/注册人数*100（无论有无消耗都计算）
                    $conversionRate = $registrationCount > 0 ? ($payingUsers / $registrationCount) * 100 : 0;
                    
                    // 检查是否有消耗数据
                    $totalExpenseToday = isset($expensesByDate[$dateStr]) ? $expensesByDate[$dateStr]->total_amount : $defaultExpenseTotal;
                    
                    // 获取当天的汇率
                    $rateValue = isset($ratesByDate[$dateStr]) ? $ratesByDate[$dateStr]->rate : $defaultRateValue;
                    
                    // 计算首充单价（消耗÷首充人数）
                    $firstDepositPrice = 0;
                    if ($payingUsers > 0 && $totalExpenseToday > 0) {
                        $firstDepositPrice = $totalExpenseToday / $payingUsers;
                    }
                    
                    // 计算衍生指标
                    $arpu = $registrationCount > 0 ? $totalBalanceToday / $registrationCount : 0;
                    $cpa = $registrationCount > 0 && $totalExpenseToday > 0 ? $totalExpenseToday / $registrationCount : 0;
                    
                    // 计算当天ROI
                    $roiToday = 0;
                    if ($totalExpenseToday > 0 && $rateValue > 0) {
                        $roiToday = (($totalBalanceToday / $rateValue) / $totalExpenseToday) * 100;
                    }
                    
                    // 保存每日基础统计
                    $dailyStats[$dateStr] = [
                        'date' => $dateStr,
                        'registrations' => $registrationCount,
                        'expense' => $totalExpenseToday,
                        'balance' => $totalBalanceToday,
                        'daily_roi' => $roiToday,
                        'paying_users' => $payingUsers,
                        'conversion_rate' => $conversionRate,
                        'retention_rate' => $retentionRates[$dateStr] ?? 0,
                        'arpu' => $arpu,
                        'cpa' => $cpa,
                        'first_deposit_price' => $firstDepositPrice,
                        'roi_trends' => array_fill_keys([1, 2, 3, 5, 7, 14, 30, 40], 0),
                        'roi_after_40' => 0
                    ];
                    
                    // 只有当有实际数据时，才将日期添加到显示列表中
                    // 至少满足以下条件之一：有注册用户、有充值用户、有消费金额
                    if ($registrationCount > 0 || $payingUsers > 0 || (isset($expensesByDate[$dateStr]) && $expensesByDate[$dateStr]->total_amount > 0)) {
                        $displayDates[] = $dateStr;
                    }
                }
                
                // 确保displayDates是按日期降序排列的（最新日期在前）
                usort($displayDates, function($a, $b) {
                    return strcmp($b, $a); // 降序排列
                });
                
                // 只为有消耗数据的日期计算多日ROI
                foreach ($expenseDates as $dateStr) {
                    // 跳过日期范围外的记录
                    if (!in_array($dateStr, $dates)) {
                        continue;
                    }
                    
                    // 获取该日期的汇率和消耗
                    $expenseValue = isset($expensesByDate[$dateStr]) ? $expensesByDate[$dateStr]->total_amount : $defaultExpenseTotal;
                    $rateValue = isset($ratesByDate[$dateStr]) ? $ratesByDate[$dateStr]->rate : $defaultRateValue;
                    
                    if ($expenseValue <= 0 || $rateValue <= 0) {
                        continue;
                    }
                    
                    // 计算多日ROI (1日、2日、3日、5日、7日、14日、30日、40日)
                    $dayRanges = [1, 2, 3, 5, 7, 14, 30, 40];
                    
                    foreach ($dayRanges as $days) {
                        // 这里的关键逻辑在于：日期+周期-1天 是否超过今天
                        // 例如：3月25日+5日ROI需要查询到3月29日的数据
                        $endDate = Carbon::parse($dateStr)->addDays($days - 1);
                        $todayDate = Carbon::today();
                        
                        // 如果理论结束日期超过今天，则无法计算完整的ROI
                        // 不将此ROI存入数据库和显示到dailyStats
                        if ($endDate > $todayDate) {
                            // 我们完全跳过这个周期的ROI计算，不存储0值
                            continue;
                        }
                        
                        // 只有当天数足够时才执行查询计算
                        $startDate = Carbon::parse($dateStr)->startOfDay();
                        $endDateForQuery = Carbon::parse($dateStr)->addDays($days - 1)->endOfDay();
                        
                        $query = Transaction::whereBetween('registration_time', [$startDate, $endDateForQuery]);
                        
                        // 如果指定了渠道，则添加渠道条件
                        if (!is_null($channelId)) {
                            $query->where('channel_id', $channelId);
                        }
                        
                        $cumulativeBalance = $query->sum('balance_difference');
                        
                        // 计算ROI百分比
                        $roiPercentage = 0;
                        if ($expenseValue > 0 && $rateValue > 0) {
                            $roiPercentage = (($cumulativeBalance / $rateValue) / $expenseValue) * 100;
                        }
                        
                        // 更新dailyStats中的ROI趋势数据
                        if (isset($dailyStats[$dateStr])) {
                            $dailyStats[$dateStr]['roi_trends'][$days] = $roiPercentage;
                        }
                        
                        // 创建或更新ROI计算记录（可选）
                        RoiCalculation::updateOrCreate(
                            [
                                'date' => $dateStr,
                                'channel_id' => $channelId ?? 0,
                                'day_count' => $days,
                            ],
                            [
                                'cumulative_balance' => $cumulativeBalance,
                                'exchange_rate' => $rateValue,
                                'expense' => $expenseValue,
                                'roi_percentage' => $roiPercentage,
                            ]
                        );
                    }
                }
                
                // 如果有默认消耗，也为其他日期计算多日ROI
                if ($calculateAllDates) {
                    foreach ($dates as $dateStr) {
                        // 跳过已经计算过的日期
                        if (in_array($dateStr, $expenseDates)) {
                            continue;
                        }
                        
                        // 获取该日期的汇率和消耗
                        $expenseValue = $defaultExpenseTotal;
                        $rateValue = isset($ratesByDate[$dateStr]) ? $ratesByDate[$dateStr]->rate : $defaultRateValue;
                        
                        if ($expenseValue <= 0 || $rateValue <= 0) {
                            continue;
                        }
                        
                        // 计算多日ROI (1日、2日、3日、5日、7日、14日、30日、40日)
                        $dayRanges = [1, 2, 3, 5, 7, 14, 30, 40];
                        
                        foreach ($dayRanges as $days) {
                            // 这里的关键逻辑在于：日期+周期-1天 是否超过今天
                            // 例如：3月25日+5日ROI需要查询到3月29日的数据
                            $endDate = Carbon::parse($dateStr)->addDays($days - 1);
                            $todayDate = Carbon::today();
                            
                            // 如果理论结束日期超过今天，则无法计算完整的ROI
                            // 不将此ROI存入数据库和显示到dailyStats
                            if ($endDate > $todayDate) {
                                // 我们完全跳过这个周期的ROI计算，不存储0值
                                continue;
                            }
                            
                            // 只有当天数足够时才执行查询计算
                            $startDate = Carbon::parse($dateStr)->startOfDay();
                            $endDateForQuery = Carbon::parse($dateStr)->addDays($days - 1)->endOfDay();
                            
                            $query = Transaction::whereBetween('registration_time', [$startDate, $endDateForQuery]);
                            
                            // 如果指定了渠道，则添加渠道条件
                            if (!is_null($channelId)) {
                                $query->where('channel_id', $channelId);
                            }
                            
                            $cumulativeBalance = $query->sum('balance_difference');
                            
                            // 计算ROI百分比
                            $roiPercentage = 0;
                            if ($expenseValue > 0 && $rateValue > 0) {
                                $roiPercentage = (($cumulativeBalance / $rateValue) / $expenseValue) * 100;
                            }
                            
                            // 更新dailyStats中的ROI趋势数据
                            if (isset($dailyStats[$dateStr])) {
                                $dailyStats[$dateStr]['roi_trends'][$days] = $roiPercentage;
                            }
                            
                            // 创建或更新ROI计算记录（可选）
                            RoiCalculation::updateOrCreate(
                                [
                                    'date' => $dateStr,
                                    'channel_id' => $channelId ?? 0,
                                    'day_count' => $days,
                                ],
                                [
                                    'cumulative_balance' => $cumulativeBalance,
                                    'exchange_rate' => $rateValue,
                                    'expense' => $expenseValue,
                                    'roi_percentage' => $roiPercentage,
                                ]
                            );
                        }
                    }
                }
                
                // 初始化汇总行数据
                $summaryData = [
                    'date' => '汇总',
                    'registrations' => 0,
                    'expense' => 0,
                    'balance' => 0,
                    'daily_roi' => 0,
                    'paying_users' => 0,
                    'conversion_rate' => 0,
                    'retention_rate' => 0,
                    'arpu' => 0,
                    'cpa' => 0,
                    'first_deposit_price' => 0,
                    'roi_trends' => array_fill_keys([1, 2, 3, 5, 7, 14, 30, 40], 0),
                    'roi_after_40' => 0
                ];
                
                // 用于汇总计算的变量
                $totalRegistrations = 0;
                $totalPayingUsers = 0;
                $totalExpense = 0;
                $totalBalance = 0;
                $validRowsCount = 0;
                $totalRetentionRate = 0;
                $retentionRateCount = 0;
                $totalDailyRoi = 0;
                $dailyRoiCount = 0;
                $totalRoiTrends = array_fill_keys([1, 2, 3, 5, 7, 14, 30, 40], ['sum' => 0, 'count' => 0]);
                
                foreach ($displayDates as $dateStr) {
                    if (isset($dailyStats[$dateStr])) {
                        $row = $dailyStats[$dateStr];
                        
                        // 只有当存在实际数据时才计算汇总数据
                        if ($row['registrations'] > 0 || $row['paying_users'] > 0 || $row['expense'] > 0) {
                            $validRowsCount++;
                            
                            // 累加基础数据
                            $summaryData['registrations'] += $row['registrations'];
                            $summaryData['expense'] += $row['expense'];
                            $summaryData['balance'] += $row['balance'];
                            $summaryData['paying_users'] += $row['paying_users'];
                            
                            // 记录汇总项（用于计算平均值）
                            $totalRegistrations += $row['registrations'];
                            $totalPayingUsers += $row['paying_users'];
                            $totalExpense += $row['expense'];
                            $totalBalance += $row['balance'];
                            
                            // 累加需要计算平均值的字段 - 只累加非零值
                            if ($row['retention_rate'] > 0) {
                                $totalRetentionRate += $row['retention_rate'];
                                $retentionRateCount++;
                            }
                            
                            if ($row['daily_roi'] != 0) {
                                $totalDailyRoi += $row['daily_roi'];
                                $dailyRoiCount++;
                            }
                            
                            // 累加多日ROI数据 - 只累加非零值
                            foreach ($row['roi_trends'] as $days => $roiValue) {
                                if ($roiValue != 0) {
                                    $totalRoiTrends[$days]['sum'] += $roiValue;
                                    $totalRoiTrends[$days]['count']++;
                                }
                            }
                        }
                    }
                }
                
                // 计算汇总行的衍生指标
                if ($totalRegistrations > 0) {
                    $summaryData['conversion_rate'] = ($totalPayingUsers / $totalRegistrations) * 100;
                    $summaryData['arpu'] = $totalBalance / $totalRegistrations;
                    $summaryData['cpa'] = $totalExpense / $totalRegistrations;
                }
                
                if ($totalPayingUsers > 0) {
                    $summaryData['first_deposit_price'] = $totalExpense / $totalPayingUsers;
                }
                
                // 计算平均次留率 - 只计算非零值的平均
                if ($retentionRateCount > 0) {
                    $summaryData['retention_rate'] = $totalRetentionRate / $retentionRateCount;
                }
                
                // 计算平均每日ROI - 只计算非零值的平均
                if ($dailyRoiCount > 0) {
                    $summaryData['daily_roi'] = $totalDailyRoi / $dailyRoiCount;
                }
                
                // 计算平均多日ROI - 只计算非零值的平均
                foreach ($totalRoiTrends as $days => $data) {
                    if ($data['count'] > 0) {
                        $summaryData['roi_trends'][$days] = $data['sum'] / $data['count'];
                    }
                }
            } else {
                // 即使没有选择筛选条件，也需要设置初始日期
                if (empty($startDate)) {
                    $startDate = Carbon::today()->subDays(29)->format('Y-m-d');
                }
                if (empty($endDate)) {
                    $endDate = Carbon::today()->format('Y-m-d');
                }
            }
            
            return view('roi.index', compact(
                'channels', 
                'roiResults', 
                'startDate', 
                'endDate', 
                'channelId', 
                'dailyStats',
                'summaryData',
                'displayDates',
                'hasFilters'
            ));
            
        } catch (\Exception $e) {
            // 记录错误并显示错误页面
            Log::error('ROI页面加载失败: ' . $e->getMessage());
            Log::error('错误位置: ' . $e->getFile() . ' (第 ' . $e->getLine() . ' 行)');
            Log::error('错误堆栈: ' . $e->getTraceAsString());
            
            return view('error', [
                'message' => '加载ROI页面时出错。请检查数据库是否正确配置，并确保所有表和列都已正确创建。',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 重新计算指定日期范围和渠道的ROI
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function recalculate(Request $request)
    {
        $startDate = $request->input('start_date', Carbon::today()->subDays(30)->format('Y-m-d'));
        $endDate = $request->input('end_date', Carbon::today()->format('Y-m-d'));
        $channelId = $request->input('channel_id');
        $maxDays = $request->input('max_days', 40);

        // 进行重定向，使用refresh参数触发index方法中的自动计算
        return redirect()->route('roi.index', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'channel_id' => $channelId,
            'refresh' => true
        ])->with('info', '系统将自动计算ROI数据，这可能需要一些时间。');
    }

    /**
     * 显示ROI详情
     *
     * @param  string  $date
     * @param  int  $channelId
     * @return \Illuminate\Http\Response
     */
    public function show($date, $channelId)
    {
        $channel = Channel::findOrFail($channelId);
        
        // 检查是否需要实时计算数据
        $needCalculation = request()->has('refresh') || 
                          !RoiCalculation::where('date', $date)
                                        ->where('channel_id', $channelId)
                                        ->exists();
        
        // 如果需要实时计算
        if ($needCalculation) {
            try {
                DB::beginTransaction();
                
                // 获取计算所需的基础数据
                $exchangeRate = ExchangeRate::where('date', $date)->first();
                $defaultRate = ExchangeRate::where('is_default', true)->first();
                $rateValue = $exchangeRate ? $exchangeRate->rate : ($defaultRate ? $defaultRate->rate : 0);
                
                $expense = Expense::where('date', $date)
                                ->where('channel_id', $channelId)
                                ->first();
                $defaultExpense = Expense::where('is_default', true)
                                       ->where('channel_id', $channelId)
                                       ->first();
                $expenseValue = $expense ? $expense->amount : ($defaultExpense ? $defaultExpense->amount : 0);
                
                // 批量计算1-40天的ROI
                $batchData = [];
                
                for ($i = 1; $i <= 40; $i++) {
                    // 这里的关键逻辑在于：日期+周期-1天 是否超过今天
                    // 例如：3月25日+5日ROI需要查询到3月29日的数据
                    $endDate = Carbon::parse($date)->addDays($i - 1);
                    $todayDate = Carbon::today();
                    
                    // 如果理论结束日期超过今天，则无法计算完整的ROI
                    // 不将此ROI存入数据库和显示到dailyStats
                    if ($endDate > $todayDate) {
                        // 我们完全跳过这个周期的ROI计算，不存储0值
                        continue;
                    }
                    
                    // 对于非1天的ROI，需要获取当天的汇率和消耗
                    if ($i > 1) {
                        $startDateRate = ExchangeRate::where('date', $date)->first();
                        $rateValue = $startDateRate ? $startDateRate->rate : ($defaultRate ? $defaultRate->rate : 0);
                        
                        $startExpense = Expense::where('date', $date)
                                             ->where('channel_id', $channelId)
                                             ->first();
                        $expenseValue = $startExpense ? $startExpense->amount : ($defaultExpense ? $defaultExpense->amount : 0);
                    }
                    
                    $roiResult = $this->calculateRoiBatch(
                        $date, 
                        $channelId, 
                        $i, 
                        $date,
                        $rateValue,
                        $expenseValue
                    );
                    
                    if ($roiResult) {
                        $batchData[] = $roiResult;
                    }
                }
                
                // 批量更新数据库
                if (!empty($batchData)) {
                    $this->batchUpsertRoiCalculations($batchData);
                }
                
                DB::commit();
                
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("ROI详情计算失败: " . $e->getMessage());
            }
        }
        
        // 获取该日期该渠道的所有天数ROI数据
        $roiData = RoiCalculation::where('date', $date)
                               ->where('channel_id', $channelId)
                               ->orderBy('day_count')
                               ->get();
                               
        // 获取该渠道该日期的消耗和汇率
        $expense = Expense::where('date', $date)
                         ->where('channel_id', $channelId)
                         ->first();
                         
        $exchangeRate = ExchangeRate::where('date', $date)->first();
        
        return view('roi.show', compact('date', 'channel', 'roiData', 'expense', 'exchangeRate'));
    }
    
    /**
     * 批量计算ROI
     * 
     * @param string $date 计算的日期
     * @param int $channelId 渠道ID
     * @param int $dayCount 天数
     * @param string $startDateForCalc 计算的起始日期
     * @param float $rateValue 汇率
     * @param float $expenseValue 消耗
     * @return array|null 计算结果数组或null
     */
    protected function calculateRoiBatch($date, $channelId, $dayCount, $startDateForCalc, $rateValue, $expenseValue)
    {
        try {
            // 计算需要查询的日期范围
            $startDate = Carbon::parse($date)->startOfDay();
            $endDate = Carbon::parse($date)->addDays($dayCount - 1)->endOfDay();
            
            // 构建查询
            $query = Transaction::whereBetween('registration_time', [$startDate, $endDate]);
            
            // 只有在指定了渠道ID时才添加渠道筛选
            if ($channelId) {
                $query->where('channel_id', $channelId);
            }
            
            $cumulativeBalance = $query->sum('balance_difference');
            
            // 计算ROI百分比
            $roiPercentage = 0;
            if ($expenseValue > 0 && $rateValue > 0) {
                $roiPercentage = (($cumulativeBalance / $rateValue) / $expenseValue) * 100;
            }
            
            // 返回计算结果数组
            return [
                'date' => $date,
                'channel_id' => $channelId,
                'day_count' => $dayCount,
                'cumulative_balance' => $cumulativeBalance,
                'exchange_rate' => $rateValue,
                'expense' => $expenseValue,
                'roi_percentage' => $roiPercentage,
            ];
        } catch (\Exception $e) {
            Log::error("ROI计算失败: {$date}, 渠道: {$channelId}, 天数: {$dayCount}, 错误: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 批量更新或创建ROI计算记录
     * 
     * @param array $batchData 批量数据
     * @return int 处理的记录数
     */
    protected function batchUpsertRoiCalculations($batchData)
    {
        if (empty($batchData)) {
            return 0;
        }
        
        // 使用Laravel的upsert方法批量插入或更新
        RoiCalculation::upsert(
            $batchData,
            ['date', 'channel_id', 'day_count'], // 唯一键
            ['cumulative_balance', 'exchange_rate', 'expense', 'roi_percentage'] // 要更新的列
        );
        
        return count($batchData);
    }
    
    /**
     * 单次计算ROI（保留用于单个计算）
     * 
     * @param string $date 计算的日期
     * @param int $channelId 渠道ID
     * @param int $dayCount 天数
     * @return void
     */
    protected function calculateRoiForDateChannelDay($date, $channelId, $dayCount)
    {
        // 获取起始日期和结束日期
        $dateObj = Carbon::parse($date);
        $startDateObj = $dateObj->copy()->startOfDay();
        $endDateObj = $dateObj->copy()->addDays($dayCount - 1)->endOfDay();
        
        // 获取今天日期
        $todayDate = Carbon::today()->endOfDay();
        
        // 如果结束日期超过了今天，说明无法完整统计
        if ($endDateObj > $todayDate) {
            // 我们完全跳过这个周期的ROI计算，不存储任何值
            return;
        }
        
        // 获取起始日期的汇率
        $exchangeRate = ExchangeRate::getRateForDate($date);
        
        // 获取起始日期的消耗数据
        $expense = Expense::getExpenseForDateAndChannel($date, $channelId);
        
        // 获取从起始日期到计算日期的累计充提差额
        $cumulativeBalance = Transaction::where('channel_id', $channelId)
            ->whereBetween('registration_time', [$startDateObj, $endDateObj])
            ->sum('balance_difference');
        
        // 计算ROI百分比
        $roiPercentage = 0;
        if ($expense > 0 && $exchangeRate > 0) {
            $roiPercentage = (($cumulativeBalance / $exchangeRate) / $expense) * 100;
        }
        
        // 创建或更新ROI计算记录
        RoiCalculation::updateOrCreate(
            [
                'date' => $date,
                'channel_id' => $channelId,
                'day_count' => $dayCount,
            ],
            [
                'cumulative_balance' => $cumulativeBalance,
                'exchange_rate' => $exchangeRate,
                'expense' => $expense,
                'roi_percentage' => $roiPercentage,
            ]
        );
    }

    /**
     * 计算多日ROI并更新dailyStats数组
     *
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @param int|null $channelId 渠道ID
     * @param array &$dailyStats 每日统计数据（引用传递）
     * @return void
     */
    protected function calculateMultiDayROI($startDate, $endDate, $channelId, &$dailyStats)
    {
        $daysToCalculate = [1, 2, 3, 5, 7, 14, 30, 40];
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        $todayDate = Carbon::today()->endOfDay();
        
        // 获取默认汇率
        $defaultRate = ExchangeRate::where('is_default', true)->first();
        $defaultRateValue = $defaultRate ? $defaultRate->rate : 0;
        
        // 获取所有日期范围内的汇率数据
        $exchangeRates = ExchangeRate::whereBetween('date', [$startDate, $endDate])
            ->get()
            ->keyBy(function($item) {
                return $item->date->format('Y-m-d');
            });
        
        // 批量处理每个日期的多日ROI计算
        foreach ($dailyStats as $dateStr => &$stats) {
            // 获取当天汇率
            $rateValue = isset($exchangeRates[$dateStr]) ? $exchangeRates[$dateStr]->rate : $defaultRateValue;
            
            // 为每个天数计算ROI
            foreach ($daysToCalculate as $dayCount) {
                // 获取起始日期（当前日期是第一天）
                $dateObj = Carbon::parse($dateStr);
                
                // 计算理论上应该查询的确切天数日期范围
                $startDateObj = $dateObj->copy()->startOfDay();
                $endDateObj = $dateObj->copy()->addDays($dayCount - 1)->endOfDay();
                
                // 如果结束日期超过了今天日期，说明无法完整统计
                if ($endDateObj > $todayDate) {
                    // 我们完全跳过这个周期的ROI计算，不存储0值
                    continue;
                }
                
                // 查询交易数据
                $query = Transaction::whereBetween('registration_time', [$startDateObj, $endDateObj]);
                
                // 只有在指定了渠道ID时才添加渠道筛选
                if ($channelId) {
                    $query->where('channel_id', $channelId);
                }
                
                $cumulativeBalance = $query->sum('balance_difference');
                
                // 获取消耗
                $expense = Expense::where('date', $dateStr)
                    ->when($channelId, function($q) use ($channelId) {
                        return $q->where('channel_id', $channelId);
                    })
                    ->first();
                    
                $defaultExpense = Expense::where('is_default', true)
                    ->when($channelId, function($q) use ($channelId) {
                        return $q->where('channel_id', $channelId);
                    })
                    ->first();
                    
                $expenseValue = $expense ? $expense->amount : ($defaultExpense ? $defaultExpense->amount : 0);
                
                // 计算ROI百分比
                $roiPercentage = 0;
                if ($expenseValue > 0 && $rateValue > 0) {
                    $roiPercentage = (($cumulativeBalance / $rateValue) / $expenseValue) * 100;
                }
                
                // 更新dailyStats中的ROI趋势数据
                $stats['roi_trends'][$dayCount] = $roiPercentage;
                
                // 创建或更新ROI计算记录（可选）
                RoiCalculation::updateOrCreate(
                    [
                        'date' => $dateStr,
                        'channel_id' => $channelId ?? 0,
                        'day_count' => $dayCount,
                    ],
                    [
                        'cumulative_balance' => $cumulativeBalance,
                        'exchange_rate' => $rateValue,
                        'expense' => $expenseValue,
                        'roi_percentage' => $roiPercentage,
                    ]
                );
            }
        }
    }

    /**
     * 从数据库加载已计算的ROI趋势数据
     *
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @param int|null $channelId 渠道ID
     * @param array &$dailyStats 每日统计数据（引用传递）
     * @return void
     */
    protected function loadROITrendsFromDatabase($startDate, $endDate, $channelId, &$dailyStats)
    {
        // 构建查询
        $query = RoiCalculation::whereBetween('date', [$startDate, $endDate]);
        
        // 如果指定了渠道，则只查询该渠道的数据
        if ($channelId) {
            $query->where('channel_id', $channelId);
        }
        
        // 获取ROI计算结果数据
        $results = $query->get();
        
        // 按日期分组
        $roiResults = $results->groupBy(function($item) {
            return $item->date->format('Y-m-d');
        });
        
        // 更新每日统计数据中的ROI趋势
        foreach ($roiResults as $dateStr => $roiItems) {
            if (!isset($dailyStats[$dateStr])) {
                continue;
            }
            
            // 按天数分组ROI数据
            foreach ($roiItems as $roi) {
                $dayCount = $roi->day_count;
                if (isset($dailyStats[$dateStr]['roi_trends'][$dayCount])) {
                    $dailyStats[$dateStr]['roi_trends'][$dayCount] = $roi->roi_percentage;
                }
            }
        }
    }
}
