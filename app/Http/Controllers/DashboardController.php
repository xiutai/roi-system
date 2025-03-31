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

class DashboardController extends Controller
{
    /**
     * 显示仪表盘
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        try {
            // 获取渠道数量
            $channelsCount = Channel::count();
            
            // 确定日期范围（最近30天，扩展到40天以便计算40日ROI）
            $endDate = Carbon::today();
            $startDate = (clone $endDate)->subDays(39); 
            
            // 日期范围字符串
            $startDateStr = $startDate->format('Y-m-d');
            $endDateStr = $endDate->format('Y-m-d');
            
            // 尝试从缓存获取数据 - 缓存键包含时间范围
            $cacheKey = "dashboard_data_{$startDateStr}_{$endDateStr}";
            if (config('app.debug') === false && cache()->has($cacheKey)) {
                $cachedData = cache()->get($cacheKey);
                return view('dashboard', $cachedData);
            }
            
            // 获取交易总数
            $transactionsCount = Transaction::count();
            
            // 生成日期数组（降序排列，最新日期在前）
            $dates = [];
            $displayDates = []; // 显示用的日期（最近30天）
            $displayStartDate = (clone $endDate)->subDays(29); // 30天显示范围
            
            for ($date = clone $endDate; $date->gte($startDate); $date->subDay()) {
                $dateStr = $date->format('Y-m-d');
                $dates[] = $dateStr;
                
                // 只显示最近30天的数据
                if ($date->gte($displayStartDate)) {
                    $displayDates[] = $dateStr;
                }
            }
            
            // 1. 按日期和渠道获取注册人数、充值人数、充提差额总和
            $statsQuery = DB::table('transactions')
                ->select(
                    DB::raw('DATE(registration_time) as date'),
                    'channel_id',
                    DB::raw('COUNT(DISTINCT member_id) as total_registrations'),
                    DB::raw('COUNT(DISTINCT CASE WHEN balance_difference > 0 THEN member_id END) as deposit_users'),
                    DB::raw('SUM(balance_difference) as total_balance')
                )
                ->whereBetween('registration_time', [$startDate->startOfDay(), $endDate->endOfDay()])
                ->groupBy('date', 'channel_id');
            
            // 如果不是查询特定渠道，则获取所有渠道的汇总数据
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
                        'balance' => 0,
                        'channels' => []
                    ];
                }
                
                // 汇总所有渠道数据
                $statsByDate[$dateStr]['registrations'] += $stat->total_registrations;
                $statsByDate[$dateStr]['deposit_users'] += $stat->deposit_users;
                $statsByDate[$dateStr]['balance'] += $stat->total_balance;
                
                // 记录渠道数据（用于后续分析）
                if (!isset($statsByDate[$dateStr]['channels'][$stat->channel_id])) {
                    $statsByDate[$dateStr]['channels'][$stat->channel_id] = [
                        'registrations' => $stat->total_registrations,
                        'deposit_users' => $stat->deposit_users,
                        'balance' => $stat->total_balance
                    ];
                }
                
                // 收集渠道ID
                if (!in_array($stat->channel_id, $channelIDsData)) {
                    $channelIDsData[] = $stat->channel_id;
                }
            }
            
            // 获取消耗数据
            $expensesByDate = Expense::select('date', 'channel_id', 'amount')
                ->whereBetween('date', [$startDateStr, $endDateStr])
                ->get();
            
            // 组织消耗数据
            $expensesData = [];
            foreach ($expensesByDate as $expense) {
                $dateStr = $expense->date->format('Y-m-d');
                if (!isset($expensesData[$dateStr])) {
                    $expensesData[$dateStr] = 0;
                }
                $expensesData[$dateStr] += $expense->amount;
            }
            
            // 获取默认消耗数据
            $defaultExpenses = Expense::where('is_default', true)->get();
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
            
            foreach ($dates as $dateStr) {
                // 获取当天的基础统计数据
                $stats = $statsByDate[$dateStr] ?? null;
                
                // 获取或计算当天的消耗
                $totalExpenseToday = $expensesData[$dateStr] ?? $totalDefaultExpense;
                
                if (!$stats) {
                    $dailyStats[$dateStr] = [
                        'date' => $dateStr,
                        'registrations' => 0,
                        'expense' => $totalExpenseToday,
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
                    continue;
                }
                
                // 基础统计
                $registrationCount = $stats['registrations'];
                $payingUsers = $stats['deposit_users'];
                $totalBalanceToday = $stats['balance'];
                
                // 获取当天的汇率
                $rateValue = isset($ratesByDate[$dateStr]) ? $ratesByDate[$dateStr]->rate : $defaultRateValue;
                
                // 只有当有消耗和汇率时才计算ROI
                $calculateRoi = $totalExpenseToday > 0 && $rateValue > 0;
                
                // 计算付费率
                $conversionRate = $registrationCount > 0 ? ($payingUsers / $registrationCount) * 100 : 0;
                
                // 计算衍生指标
                $arpu = $registrationCount > 0 ? $totalBalanceToday / $registrationCount : 0;
                $cpa = $registrationCount > 0 ? $totalExpenseToday / $registrationCount : 0;
                $firstDepositPrice = $payingUsers > 0 ? $totalExpenseToday / $payingUsers : 0;
                
                // 计算当天的ROI
                $roiToday = 0;
                if ($calculateRoi) {
                    $roiToday = ($totalBalanceToday / $rateValue) / $totalExpenseToday * 100;
                }
                
                // 更新汇总数据
                $summaryData['registrations'] += $registrationCount;
                $summaryData['expense'] += $totalExpenseToday;
                $summaryData['balance'] += $totalBalanceToday;
                $summaryData['paying_users'] += $payingUsers;
                
                // 初始化每日统计
                $dailyStats[$dateStr] = [
                    'date' => $dateStr,
                    'registrations' => $registrationCount,
                    'expense' => $totalExpenseToday,
                    'balance' => $totalBalanceToday,
                    'daily_roi' => round($roiToday, 2),
                    'paying_users' => $payingUsers,
                    'conversion_rate' => round($conversionRate, 2),
                    'arpu' => round($arpu, 2),
                    'cpa' => round($cpa, 2),
                    'first_deposit_price' => round($firstDepositPrice, 2),
                    'roi_trends' => array_fill_keys([2, 3, 5, 7, 14, 30, 40], 0),
                    'roi_after_40' => 0,
                    'rate_value' => $rateValue
                ];
            }
            
            // 多日ROI计算
            $dayRanges = [2, 3, 5, 7, 14, 30, 40]; // 移除1日ROI
            
            // 初始化各日期的ROI趋势数据
            foreach ($dates as $dateStr) {
                if (isset($dailyStats[$dateStr])) {
                    // 确保ROI趋势数组已初始化
                    if (!isset($dailyStats[$dateStr]['roi_trends']) || !is_array($dailyStats[$dateStr]['roi_trends'])) {
                        $dailyStats[$dateStr]['roi_trends'] = array_fill_keys($dayRanges, 0);
                    }
                    
                    // 确保40日后ROI已初始化
                    if (!isset($dailyStats[$dateStr]['roi_after_40'])) {
                        $dailyStats[$dateStr]['roi_after_40'] = 0;
                    }
                }
            }
            
            // 对每个日期计算各个天数范围的ROI (从2日ROI开始)
            foreach ($dates as $dateStr) {
                $currentDateStats = $dailyStats[$dateStr] ?? null;
                
                // 如果没有当日统计数据或没有消耗，则跳过
                if (!$currentDateStats || !isset($currentDateStats['expense']) || $currentDateStats['expense'] <= 0) {
                    continue;
                }
                
                $startDateObj = Carbon::parse($dateStr);
                
                // 获取当日（起始日期）的汇率和消耗 - 这些值在计算多日ROI时始终保持不变
                $expense = $currentDateStats['expense'];
                $rateValue = $currentDateStats['rate_value'] ?? 1;
                
                if ($expense <= 0 || $rateValue <= 0) {
                    continue;
                }
                
                // 为每个天数范围计算ROI
                foreach ($dayRanges as $dayCount) {
                    // 计算结束日期
                    $endDateForRange = $startDateObj->copy()->addDays($dayCount - 1);
                    $endDateStrForRange = $endDateForRange->format('Y-m-d');
                    
                    // 如果结束日期超过了数据范围，则跳过
                    if ($endDateForRange->gt($endDate)) {
                        continue;
                    }
                    
                    // 计算该范围内的累计充提差额
                    $cumulativeBalance = 0;
                    for ($d = 0; $d < $dayCount; $d++) {
                        $calcDate = $startDateObj->copy()->addDays($d)->format('Y-m-d');
                        if (isset($dailyStats[$calcDate]) && isset($dailyStats[$calcDate]['balance'])) {
                            $cumulativeBalance += $dailyStats[$calcDate]['balance'];
                        }
                    }
                    
                    // 计算ROI：(累计充提差额/汇率)/消耗*100%
                    $roi = 0;
                    if ($expense > 0 && $rateValue > 0) {
                        $roi = ($cumulativeBalance / $rateValue) / $expense * 100;
                    }
                    
                    // 保存ROI趋势数据
                    $dailyStats[$dateStr]['roi_trends'][$dayCount] = round($roi, 2);
                }
                
                // 计算40日后的ROI (如果有足够的数据)
                $after40DateObj = $startDateObj->copy()->addDays(40);
                if ($after40DateObj <= $endDate) {
                    // 计算40天后至结束日期的累计充提差额
                    $after40Balance = 0;
                    
                    for ($d = 40; $d <= 60; $d++) { // 从第41天开始，最多计算到第60天
                        $calcDate = $startDateObj->copy()->addDays($d)->format('Y-m-d');
                        if ($calcDate > $endDateStr) {
                            break; // 如果超出数据范围，则停止
                        }
                        
                        if (isset($dailyStats[$calcDate]) && isset($dailyStats[$calcDate]['balance'])) {
                            $after40Balance += $dailyStats[$calcDate]['balance'];
                        }
                    }
                    
                    // 计算40天后的ROI（使用起始日期的汇率和消耗）
                    $after40Roi = 0;
                    if ($expense > 0 && $rateValue > 0) {
                        $after40Roi = ($after40Balance / $rateValue) / $expense * 100;
                    }
                    
                    // 保存40天后ROI数据
                    $dailyStats[$dateStr]['roi_after_40'] = round($after40Roi, 2);
                }
            }
            
            // 准备图表数据
            $chartSeries = [];
            $chartDayRanges = [1, 2, 3, 5, 7, 14, 30, 40]; // 修改：添加1日ROI
            
            foreach ($chartDayRanges as $days) {
                $seriesData = [];
                
                // 只显示最近30天的数据
                foreach ($displayDates as $dateStr) {
                    if ($days == 1) {
                        // 1日ROI等于当日ROI
                        $value = $dailyStats[$dateStr]['daily_roi'] ?? 0;
                    } else {
                        $value = $dailyStats[$dateStr]['roi_trends'][$days] ?? 0;
                    }
                    $seriesData[] = $value;
                }
                
                $chartSeries[] = [
                    'name' => $days == 1 ? "当日ROI" : "{$days}日ROI",
                    'data' => $seriesData
                ];
            }
            
            // 系统统计信息
            $stats = [
                'transactions_count' => $transactionsCount,
                'channels_count' => $channelsCount,
                'latest_date' => $displayDates[0] ?? Carbon::today()->format('Y-m-d'),
                'date_range' => [
                    'start' => $displayStartDate->format('Y-m-d'),
                    'end' => $endDateStr
                ]
            ];
            
            // 计算汇总数据行
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
            
            // 用于计算平均值的统计数组
            $validRowsCount = 0;
            $totalDailyRoi = 0;
            $dailyRoiCount = 0;
            $totalRoiTrends = array_fill_keys([2, 3, 5, 7, 14, 30, 40], ['sum' => 0, 'count' => 0]);
            $totalRoiAfter40 = ['sum' => 0, 'count' => 0];
            
            // 初始化汇总变量
            $totalRegistrations = 0;
            $totalPayingUsers = 0;
            $totalExpense = 0;
            $totalBalance = 0;
            
            foreach ($displayDates as $dateStr) {
                if (isset($dailyStats[$dateStr])) {
                    $row = $dailyStats[$dateStr];
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
                    
                    // 累加40日后ROI - 只累加非零值
                    if ($row['roi_after_40'] != 0) {
                        $totalRoiAfter40['sum'] += $row['roi_after_40'];
                        $totalRoiAfter40['count']++;
                    }
                }
            }
            
            // 计算汇总行的衍生指标
            if ($totalRegistrations > 0) {
                $summaryData['conversion_rate'] = round(($totalPayingUsers / $totalRegistrations) * 100, 2);
                $summaryData['arpu'] = round($totalBalance / $totalRegistrations, 2);
                $summaryData['cpa'] = round($totalExpense / $totalRegistrations, 2);
            }
            
            if ($totalPayingUsers > 0) {
                $summaryData['first_deposit_price'] = round($totalExpense / $totalPayingUsers, 2);
            }
            
            // 计算总体ROI
            if ($totalExpense > 0 && $defaultRateValue > 0) {
                $summaryData['daily_roi'] = round(($totalBalance / $defaultRateValue) / $totalExpense * 100, 2);
            }
            
            // 计算平均每日ROI - 只计算非零值的平均
            if ($dailyRoiCount > 0) {
                $summaryData['daily_roi'] = round($totalDailyRoi / $dailyRoiCount, 2);
            }
            
            // 计算平均多日ROI - 只计算非零值的平均
            foreach ($totalRoiTrends as $days => $data) {
                if ($data['count'] > 0) {
                    $summaryData['roi_trends'][$days] = round($data['sum'] / $data['count'], 2);
                }
            }
            
            // 计算平均40日后ROI - 只计算非零值的平均
            if ($totalRoiAfter40['count'] > 0) {
                $summaryData['roi_after_40'] = round($totalRoiAfter40['sum'] / $totalRoiAfter40['count'], 2);
            }
            
            // 缓存数据（如果不在调试模式）
            $viewData = compact(
                'stats',
                'dailyStats',
                'chartSeries',
                'displayDates',
                'summaryData'
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
}

