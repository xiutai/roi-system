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
            
            // 调试输出
            Log::info("ROI查询条件: 日期范围={$startDateStr}至{$endDateStr}, 渠道ID=" . ($channelId ?: '全部'));
            
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
            
            // 调试输出ROI数据结构
            Log::info("ROI数据计算结果: " . json_encode($roiData));
            
            foreach ($dates as $dateStr) {
                // 获取当天的基础统计数据
                $stats = $statsByDate[$dateStr] ?? null;
                
                // 获取或计算当天的消耗
                $totalExpenseToday = $expensesData[$dateStr] ?? $totalDefaultExpense;
                
                // 获取ROI数据
                $roiDataForDate = $roiData[$dateStr] ?? collect();
                
                // 调试输出当前日期的ROI数据
                Log::info("日期: {$dateStr} 的ROI数据: " . json_encode($roiDataForDate));
                
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
                
                // 调试输出当日ROI
                Log::info("日期: {$dateStr} 的当日ROI值: {$dayRoi}");
                
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
            
            // 获取实际显示的日期
            $actualDisplayDates = array_keys($dailyStats);
            sort($actualDisplayDates); // 按日期升序排序
            
            foreach ($chartDayRanges as $days) {
                $seriesData = [];
                
                // 按时间顺序排序，最新日期在右边
                foreach (array_reverse($actualDisplayDates) as $dateStr) {
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
                'latest_date' => $actualDisplayDates ? end($actualDisplayDates) : Carbon::today()->format('Y-m-d'),
                'date_range' => [
                    'start' => $startDateStr,
                    'end' => $endDateStr
                ]
            ];
            
            // 计算汇总数据行的平均值
            if ($summaryData['registrations'] > 0) {
                $summaryData['conversion_rate'] = round(($summaryData['paying_users'] / $summaryData['registrations']) * 100, 2);
                $summaryData['arpu'] = round($summaryData['balance'] / $summaryData['registrations'], 2);
                $summaryData['cpa'] = round($summaryData['expense'] / $summaryData['registrations'], 2);
            }
            
            if ($summaryData['paying_users'] > 0) {
                $summaryData['first_deposit_price'] = round($summaryData['expense'] / $summaryData['paying_users'], 2);
            }
            
            // 计算总体ROI
            if ($summaryData['expense'] > 0 && $defaultRateValue > 0) {
                // 不要对ROI进行四舍五入处理，直接使用计算值
                $summaryData['daily_roi'] = ($summaryData['balance'] / $defaultRateValue) / $summaryData['expense'] * 100;
            }
            
            // 为汇总数据添加ROI趋势数据
            $summaryData['roi_trends'] = array_fill_keys([2, 3, 5, 7, 14, 30, 40], 0);
            $summaryData['roi_after_40'] = 0;
            
            // 使用最新日期的数据作为汇总趋势数据的基准
            $latestDate = end($actualDisplayDates);
            if ($latestDate && isset($dailyStats[$latestDate])) {
                // 获取2,3,5,7,14,30日ROI
                foreach ([2, 3, 5, 7, 14, 30] as $day) {
                    $summaryData['roi_trends'][$day] = $dailyStats[$latestDate]['roi_trends'][$day];
                }
                
                // 判断最新日期是否满足40天条件
                $latestDateObj = Carbon::parse($latestDate);
                $today = Carbon::today();
                $daysSinceLatest = $latestDateObj->diffInDays($today);
                
                // 只有当天数差大于等于40天时，才使用40日和40日后ROI
                if ($daysSinceLatest >= 40) {
                    $summaryData['roi_trends'][40] = $dailyStats[$latestDate]['roi_trends'][40];
                    $summaryData['roi_after_40'] = $dailyStats[$latestDate]['roi_after_40'];
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
            
            // 记录日志
            Log::info("仪表盘ROI重新计算: 处理了{$processedCount}条记录。日期范围: {$startDateStr}至{$endDateStr}, 渠道: " . ($channelId ?: '全部'));
            
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

