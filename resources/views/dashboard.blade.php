@extends('layouts.app')

@section('title', '仪表盘')

@section('page-title', '仪表盘')

@section('content')
<!-- 系统概况 -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-gradient-primary text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title text-white mb-0">总渠道数</h6>
                        <h2 class="mt-2 mb-0 fw-bold">{{ $stats['channels_count'] }}</h2>
                    </div>
                    <div class="rounded-circle bg-white bg-opacity-25 p-3">
                        <i class="fas fa-sitemap fa-2x text-white"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-gradient-success text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title text-white mb-0">总交易数</h6>
                        <h2 class="mt-2 mb-0 fw-bold">{{ $stats['transactions_count'] }}</h2>
                    </div>
                    <div class="rounded-circle bg-white bg-opacity-25 p-3">
                        <i class="fas fa-exchange-alt fa-2x text-white"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card bg-gradient-info text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title text-white mb-0">数据时间范围</h6>
                        <h4 class="mt-2 mb-0">{{ $stats['date_range']['start'] }} 至 {{ $stats['date_range']['end'] }}</h4>
                    </div>
                    <div class="rounded-circle bg-white bg-opacity-25 p-3">
                        <i class="fas fa-calendar-alt fa-2x text-white"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-12">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">
                    <i class="fas fa-tachometer-alt me-2"></i>快速导航
                </h5>
                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('import.index') }}" class="btn btn-primary">
                        <i class="fas fa-file-import me-2"></i>导入数据
                    </a>
                    <a href="{{ route('roi.index') }}" class="btn btn-success">
                        <i class="fas fa-chart-pie me-2"></i>查看ROI
                    </a>
                    <a href="{{ route('exchange_rates.index') }}" class="btn btn-info text-white">
                        <i class="fas fa-exchange-alt me-2"></i>管理汇率
                    </a>
                    <a href="{{ route('expenses.index') }}" class="btn btn-warning">
                        <i class="fas fa-dollar-sign me-2"></i>管理消耗
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ROI趋势图 -->
<div class="card mb-4">
    <div class="card-body">
        <h5 class="card-title">
            <i class="fas fa-chart-line me-2"></i>ROI趋势图
        </h5>
        <div style="height: 400px;">
            <canvas id="roiChart"></canvas>
        </div>
    </div>
</div>

<!-- 汇总ROI数据表 -->
<div class="card">
    <div class="card-body">
        <h5 class="card-title">
            <i class="fas fa-table me-2"></i>ROI数据（全部渠道汇总）
        </h5>
        
        <div class="alert alert-info bg-light-info border-0">
            <i class="fas fa-info-circle me-2 text-info"></i>
            查看单个渠道的详细数据，请前往 <a href="{{ route('roi.index') }}" class="alert-link text-info">ROI分析</a> 页面进行筛选。
        </div>
        
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr class="bg-light">
                        <th class="border-0">日期</th>
                        <th class="border-0">渠道名称</th>
                        <th class="border-0">消耗</th>
                        <th class="border-0">新增用户</th>
                        <th class="border-0">新增用户单价</th>
                        <th class="border-0">首充人数</th>
                        <th class="border-0">首充单价</th>
                        <th class="border-0">付费率</th>
                        <th class="border-0 bg-light-primary">当日</th>
                        <th class="border-0 bg-light-info">2日</th>
                        <th class="border-0 bg-light-info">3日</th>
                        <th class="border-0 bg-light-info">5日</th>
                        <th class="border-0 bg-light-info">7日</th>
                        <th class="border-0 bg-light-info">14日</th>
                        <th class="border-0 bg-light-info">30日</th>
                        <th class="border-0 bg-light-info">40日</th>
                        <th class="border-0 bg-light-warning">40日后</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- 首先显示汇总行 -->
                    <tr class="table-secondary fw-bold">
                        <td class="align-middle">{{ $summaryData['date'] }}</td>
                        <td class="align-middle">全部渠道</td>
                        <td class="align-middle">${{ number_format($summaryData['expense'], 2) }}</td>
                        <td class="align-middle">{{ $summaryData['registrations'] }}</td>
                        <td class="align-middle">${{ number_format($summaryData['cpa'], 2) }}</td>
                        <td class="align-middle">{{ $summaryData['paying_users'] }}</td>
                        <td class="align-middle">${{ number_format($summaryData['first_deposit_price'] ?? 0, 2) }}</td>
                        <td class="align-middle">{{ number_format($summaryData['conversion_rate'], 2) }}%</td>
                        
                        <!-- 当日ROI数据 -->
                        <td class="align-middle {{ $summaryData['daily_roi'] > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                            {{ number_format($summaryData['daily_roi'], 2) }}%
                        </td>
                        
                        <!-- 汇总行的多日ROI数据 -->
                        <td class="align-middle {{ ($summaryData['roi_trends'][2] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                            {{ number_format($summaryData['roi_trends'][2] ?? 0, 2) }}%
                        </td>
                        <td class="align-middle {{ ($summaryData['roi_trends'][3] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                            {{ number_format($summaryData['roi_trends'][3] ?? 0, 2) }}%
                        </td>
                        <td class="align-middle {{ ($summaryData['roi_trends'][5] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                            {{ number_format($summaryData['roi_trends'][5] ?? 0, 2) }}%
                        </td>
                        <td class="align-middle {{ ($summaryData['roi_trends'][7] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                            {{ number_format($summaryData['roi_trends'][7] ?? 0, 2) }}%
                        </td>
                        <td class="align-middle {{ ($summaryData['roi_trends'][14] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                            {{ number_format($summaryData['roi_trends'][14] ?? 0, 2) }}%
                        </td>
                        <td class="align-middle {{ ($summaryData['roi_trends'][30] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                            {{ number_format($summaryData['roi_trends'][30] ?? 0, 2) }}%
                        </td>
                        <td class="align-middle {{ ($summaryData['roi_trends'][40] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                            {{ number_format($summaryData['roi_trends'][40] ?? 0, 2) }}%
                        </td>
                        <td class="align-middle {{ ($summaryData['roi_after_40'] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                            {{ number_format($summaryData['roi_after_40'] ?? 0, 2) }}%
                        </td>
                    </tr>
                    
                    <!-- 然后显示每日数据行 -->
                    @forelse($displayDates as $dateStr)
                        @php
                            $row = $dailyStats[$dateStr] ?? null;
                        @endphp
                        @if($row)
                        <tr>
                            <td>{{ $dateStr }}</td>
                            <td>全部渠道</td>
                            <td>${{ number_format($row['expense'], 2) }}</td>
                            <td>{{ $row['registrations'] }}</td>
                            <td>${{ number_format($row['cpa'], 2) }}</td>
                            <td>{{ $row['paying_users'] }}</td>
                            <td>${{ number_format($row['first_deposit_price'] ?? 0, 2) }}</td>
                            <td>{{ number_format($row['conversion_rate'], 2) }}%</td>
                            
                            <!-- 当日ROI数据 -->
                            <td class="{{ $row['daily_roi'] > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                {{ number_format($row['daily_roi'], 2) }}%
                            </td>
                            
                            <!-- 多日ROI数据 -->
                            <td class="{{ ($row['roi_trends'][2] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                {{ number_format($row['roi_trends'][2] ?? 0, 2) }}%
                            </td>
                            <td class="{{ ($row['roi_trends'][3] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                {{ number_format($row['roi_trends'][3] ?? 0, 2) }}%
                            </td>
                            <td class="{{ ($row['roi_trends'][5] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                {{ number_format($row['roi_trends'][5] ?? 0, 2) }}%
                            </td>
                            <td class="{{ ($row['roi_trends'][7] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                {{ number_format($row['roi_trends'][7] ?? 0, 2) }}%
                            </td>
                            <td class="{{ ($row['roi_trends'][14] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                {{ number_format($row['roi_trends'][14] ?? 0, 2) }}%
                            </td>
                            <td class="{{ ($row['roi_trends'][30] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                {{ number_format($row['roi_trends'][30] ?? 0, 2) }}%
                            </td>
                            <td class="{{ ($row['roi_trends'][40] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                {{ number_format($row['roi_trends'][40] ?? 0, 2) }}%
                            </td>
                            <td class="{{ ($row['roi_after_40'] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                {{ number_format($row['roi_after_40'] ?? 0, 2) }}%
                            </td>
                        </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="17" class="text-center py-5">
                                <i class="fas fa-database fa-3x text-muted mb-3"></i>
                                <p class="text-muted">暂无数据</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@section('styles')
<style>
    .bg-gradient-primary {
        background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
    }
    .bg-gradient-success {
        background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%);
    }
    .bg-gradient-info {
        background: linear-gradient(135deg, #36b9cc 0%, #258391 100%);
    }
    .bg-light-primary {
        background-color: rgba(78, 115, 223, 0.1);
    }
    .bg-light-info {
        background-color: rgba(54, 185, 204, 0.1);
    }
    .bg-light-warning {
        background-color: rgba(246, 194, 62, 0.1);
    }
    .bg-light-info {
        background-color: rgba(54, 185, 204, 0.05);
    }
    .table td {
        padding: 12px;
        vertical-align: middle;
    }
</style>
@endsection

@section('scripts')
<script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function() {
        // 获取日期数据
        const dates = JSON.parse('{!! json_encode($displayDates) !!}');
        
        // 格式化日期为更短的形式 (MM-DD)
        const formattedDates = dates.map(date => {
            const parts = date.split('-');
            return `${parts[1]}-${parts[2]}`;
        }).reverse(); // 反转使日期左侧为旧日期，右侧为新日期
        
        // 获取图表数据并反转顺序，使其与日期对应
        const chartSeries = JSON.parse('{!! json_encode($chartSeries) !!}');
        chartSeries.forEach(series => {
            series.data = series.data.slice().reverse();
        });
        
        // 设置颜色 - 参考图1的配色方案
        const colors = [
            'rgba(24, 144, 255, 1)',    // 首日ROI - 蓝色
            'rgba(47, 194, 91, 1)',     // 2日ROI - 绿色
            'rgba(250, 84, 28, 1)',     // 3日ROI - 橙红色
            'rgba(250, 173, 20, 1)',    // 5日ROI - 橙黄色
            'rgba(114, 46, 209, 1)',    // 7日ROI - 紫色
            'rgba(245, 34, 45, 1)',     // 14日ROI - 红色
            'rgba(19, 194, 194, 1)',    // 30日ROI - 青色
            'rgba(82, 196, 26, 1)',     // 40日ROI - 浅绿色
            'rgba(245, 102, 0, 1)'      // 40日后ROI - 橙色
        ];
        
        // 准备数据集
        const datasets = chartSeries.map((series, index) => {
            return {
                label: series.name,
                data: series.data,
                borderColor: colors[index % colors.length],
                backgroundColor: 'transparent',
                borderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 5,
                tension: 0.1,
                fill: false
            };
        });
        
        // 创建图表
        const ctx = document.getElementById('roiChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: formattedDates,
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'center',
                        labels: {
                            boxWidth: 12,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: 'rgba(0, 0, 0, 0.7)',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        titleFont: {
                            size: 14,
                            weight: 'bold'
                        },
                        bodyFont: {
                            size: 13
                        },
                        padding: 10,
                        cornerRadius: 5,
                        displayColors: true,
                        usePointStyle: true,
                        borderColor: 'rgba(255, 255, 255, 0.2)',
                        borderWidth: 1,
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.raw.toFixed(2) + '%';
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: '#6c757d'
                        }
                    },
                    y: {
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)',
                            drawBorder: false
                        },
                        ticks: {
                            color: '#6c757d',
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    }
                },
                interaction: {
                    mode: 'nearest',
                    axis: 'x',
                    intersect: false
                },
                hover: {
                    mode: 'nearest',
                    intersect: false
                }
            }
        });
    });
</script>
@endsection 