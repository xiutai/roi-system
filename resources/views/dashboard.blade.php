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
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="card-title">
                <i class="fas fa-chart-line me-2"></i>ROI趋势图
            </h5>
        </div>
        <div style="height: 350px;">
            <canvas id="roiChart"></canvas>
        </div>
    </div>
</div>

<!-- 汇总ROI数据表 -->
<div class="card">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="card-title">
                <i class="fas fa-table me-2"></i>ROI数据表格
            </h5>
            <div>
                <a href="{{ route('dashboard') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-filter-circle-xmark me-1"></i>清除筛选
                </a>
            </div>
        </div>
        
        <form id="filterForm" action="{{ route('dashboard') }}" method="GET" class="row g-3 mb-4">
            <div class="col-md-4">
                <label for="channel_id" class="form-label">渠道筛选</label>
                <select class="form-select" id="channel_id" name="channel_id">
                    <option value="">全部渠道</option>
                    @foreach($channels ?? [] as $channel)
                        <option value="{{ $channel->id }}" {{ (isset($channelId) && $channelId == $channel->id) ? 'selected' : '' }}>
                            {{ $channel->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-6">
                <label for="daterange" class="form-label">日期范围</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-calendar"></i></span>
                    <input type="text" class="form-control" id="daterangepicker" name="daterange" 
                           value="{{ isset($startDateStr) && isset($endDateStr) ? $startDateStr . ' - ' . $endDateStr : '' }}"
                           placeholder="选择日期范围">
                </div>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <input type="hidden" name="hasFilters" value="1">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-filter me-1"></i> 应用筛选
                </button>
            </div>
        </form>
        
        @if(!isset($channelId))
        <div class="alert alert-info bg-light-info border-0 mb-4">
            <i class="fas fa-info-circle me-2 text-info"></i>
            可以使用上方筛选功能查看特定渠道的详细数据。
        </div>
        @endif
        
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
                        <td class="align-middle">{{ $selectedChannelName ?? '全部渠道' }}</td>
                        <td class="align-middle">${{ number_format($summaryData['expense'], 2) }}</td>
                        <td class="align-middle">{{ $summaryData['registrations'] }}</td>
                        <td class="align-middle">${{ number_format($summaryData['cpa'], 2) }}</td>
                        <td class="align-middle">{{ $summaryData['paying_users'] }}</td>
                        <td class="align-middle">${{ number_format($summaryData['first_deposit_price'] ?? 0, 2) }}</td>
                        <td class="align-middle">{{ number_format($summaryData['conversion_rate'], 2) }}%</td>
                        
                        <!-- 当日ROI数据 -->
                        <td class="align-middle {{ ($summaryData['daily_roi'] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                            <span data-bs-toggle="tooltip" data-bs-html="true" title="{{ $summaryData['roi_calculations']['daily'] }}">
                                {{ number_format($summaryData['daily_roi'] ?? 0, 2) }}%
                            </span>
                        </td>
                        
                        <!-- 汇总行的多日ROI数据 -->
                        <td class="align-middle {{ ($summaryData['roi_trends'][2] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                            <span data-bs-toggle="tooltip" data-bs-html="true" title="{{ $summaryData['roi_calculations']['trends'][2] }}">
                                {{ number_format($summaryData['roi_trends'][2] ?? 0, 2) }}%
                            </span>
                        </td>
                        <td class="align-middle {{ ($summaryData['roi_trends'][3] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                            <span data-bs-toggle="tooltip" data-bs-html="true" title="{{ $summaryData['roi_calculations']['trends'][3] }}">
                                {{ number_format($summaryData['roi_trends'][3] ?? 0, 2) }}%
                            </span>
                        </td>
                        <td class="align-middle {{ ($summaryData['roi_trends'][5] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                            <span data-bs-toggle="tooltip" data-bs-html="true" title="{{ $summaryData['roi_calculations']['trends'][5] }}">
                                {{ number_format($summaryData['roi_trends'][5] ?? 0, 2) }}%
                            </span>
                        </td>
                        <td class="align-middle {{ ($summaryData['roi_trends'][7] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                            <span data-bs-toggle="tooltip" data-bs-html="true" title="{{ $summaryData['roi_calculations']['trends'][7] }}">
                                {{ number_format($summaryData['roi_trends'][7] ?? 0, 2) }}%
                            </span>
                        </td>
                        <td class="align-middle {{ ($summaryData['roi_trends'][14] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                            <span data-bs-toggle="tooltip" data-bs-html="true" title="{{ $summaryData['roi_calculations']['trends'][14] }}">
                                {{ number_format($summaryData['roi_trends'][14] ?? 0, 2) }}%
                            </span>
                        </td>
                        <td class="align-middle {{ ($summaryData['roi_trends'][30] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                            <span data-bs-toggle="tooltip" data-bs-html="true" title="{{ $summaryData['roi_calculations']['trends'][30] }}">
                                {{ number_format($summaryData['roi_trends'][30] ?? 0, 2) }}%
                            </span>
                        </td>
                        <td class="align-middle {{ ($summaryData['roi_trends'][40] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                            <span data-bs-toggle="tooltip" data-bs-html="true" title="{{ $summaryData['roi_calculations']['trends'][40] }}">
                                {{ number_format($summaryData['roi_trends'][40] ?? 0, 2) }}%
                            </span>
                        </td>
                        <td class="align-middle {{ ($summaryData['roi_after_40'] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                            <span data-bs-toggle="tooltip" data-bs-html="true" title="{{ $summaryData['roi_calculations']['after_40'] }}">
                                {{ number_format($summaryData['roi_after_40'] ?? 0, 2) }}%
                            </span>
                        </td>
                    </tr>
                    
                    <!-- 然后显示每日数据行 -->
                    @forelse($dailyStats as $dateStr => $row)
                        <tr>
                            <td>{{ $dateStr }}</td>
                            <td>{{ $selectedChannelName ?? '全部渠道' }}</td>
                            <td>${{ number_format($row['expense'], 2) }}</td>
                            <td>{{ $row['registrations'] }}</td>
                            <td>${{ number_format($row['cpa'], 2) }}</td>
                            <td>{{ $row['paying_users'] }}</td>
                            <td>${{ number_format($row['first_deposit_price'] ?? 0, 2) }}</td>
                            <td>{{ number_format($row['conversion_rate'], 2) }}%</td>
                            
                            <!-- 当日ROI数据 -->
                            <td class="align-middle {{ ($row['daily_roi'] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                <span data-bs-toggle="tooltip" data-bs-html="true" title="{{ $row['roi_calculations']['daily'] }}">
                                    {{ number_format($row['daily_roi'] ?? 0, 2) }}%
                                </span>
                            </td>
                            
                            <!-- 多日ROI数据 -->
                            <td class="align-middle {{ ($row['roi_trends'][2] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                <span data-bs-toggle="tooltip" data-bs-html="true" title="{{ $row['roi_calculations']['trends'][2] }}">
                                    {{ number_format($row['roi_trends'][2] ?? 0, 2) }}%
                                </span>
                            </td>
                            <td class="align-middle {{ ($row['roi_trends'][3] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                <span data-bs-toggle="tooltip" data-bs-html="true" title="{{ $row['roi_calculations']['trends'][3] }}">
                                    {{ number_format($row['roi_trends'][3] ?? 0, 2) }}%
                                </span>
                            </td>
                            <td class="align-middle {{ ($row['roi_trends'][5] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                <span data-bs-toggle="tooltip" data-bs-html="true" title="{{ $row['roi_calculations']['trends'][5] }}">
                                    {{ number_format($row['roi_trends'][5] ?? 0, 2) }}%
                                </span>
                            </td>
                            <td class="align-middle {{ ($row['roi_trends'][7] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                <span data-bs-toggle="tooltip" data-bs-html="true" title="{{ $row['roi_calculations']['trends'][7] }}">
                                    {{ number_format($row['roi_trends'][7] ?? 0, 2) }}%
                                </span>
                            </td>
                            <td class="align-middle {{ ($row['roi_trends'][14] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                <span data-bs-toggle="tooltip" data-bs-html="true" title="{{ $row['roi_calculations']['trends'][14] }}">
                                    {{ number_format($row['roi_trends'][14] ?? 0, 2) }}%
                                </span>
                            </td>
                            <td class="align-middle {{ ($row['roi_trends'][30] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                <span data-bs-toggle="tooltip" data-bs-html="true" title="{{ $row['roi_calculations']['trends'][30] }}">
                                    {{ number_format($row['roi_trends'][30] ?? 0, 2) }}%
                                </span>
                            </td>
                            <td class="align-middle {{ ($row['roi_trends'][40] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                <span data-bs-toggle="tooltip" data-bs-html="true" title="{{ $row['roi_calculations']['trends'][40] }}">
                                    {{ number_format($row['roi_trends'][40] ?? 0, 2) }}%
                                </span>
                            </td>
                            <td class="align-middle {{ ($row['roi_after_40'] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                <span data-bs-toggle="tooltip" data-bs-html="true" title="{{ $row['roi_calculations']['after_40'] }}">
                                    {{ number_format($row['roi_after_40'] ?? 0, 2) }}%
                                </span>
                            </td>
                        </tr>
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
<link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css" />
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
    .chart-container {
        position: relative;
        height: 400px;
        width: 100%;
    }
</style>
@endsection

@section('scripts')
<script src="https://cdn.jsdelivr.net/npm/moment/moment.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>
<script>
    $(document).ready(function() {
        // 初始化日期选择器
        $('#daterangepicker').daterangepicker({
            locale: {
                format: 'YYYY-MM-DD',
                separator: ' - ',
                applyLabel: '确定',
                cancelLabel: '取消',
                fromLabel: '从',
                toLabel: '到',
                customRangeLabel: '自定义',
                weekLabel: 'W',
                daysOfWeek: ['日', '一', '二', '三', '四', '五', '六'],
                monthNames: ['一月', '二月', '三月', '四月', '五月', '六月', '七月', '八月', '九月', '十月', '十一月', '十二月'],
                firstDay: 1
            },
            autoUpdateInput: false,
            ranges: {
                '最近7天': [moment().subtract(6, 'days'), moment()],
                '最近30天': [moment().subtract(29, 'days'), moment()],
                '本月': [moment().startOf('month'), moment().endOf('month')],
                '上月': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
            }
        });
        
        $('#daterangepicker').on('apply.daterangepicker', function(ev, picker) {
            $(this).val(picker.startDate.format('YYYY-MM-DD') + ' - ' + picker.endDate.format('YYYY-MM-DD'));
        });
        
        $('#daterangepicker').on('cancel.daterangepicker', function(ev, picker) {
            $(this).val('');
        });
        
        // 初始化ROI趋势图
        const roiChart = document.getElementById('roiChart');
        if (roiChart) {
            const labels = {!! json_encode(array_reverse($actualDisplayDates ?? [])) !!};
            const datasets = {!! json_encode($chartSeries ?? []) !!};
            
            new Chart(roiChart, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                usePointStyle: true,
                                boxWidth: 10
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed.y !== null) {
                                        label += new Intl.NumberFormat('zh-CN', { 
                                            style: 'percent', 
                                            minimumFractionDigits: 2,
                                            maximumFractionDigits: 2 
                                        }).format(context.parsed.y / 100);
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: true,
                                drawBorder: true,
                                drawOnChartArea: true
                            }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            },
                            grid: {
                                drawBorder: true,
                                drawOnChartArea: true
                            }
                        }
                    }
                }
            });
        }
        
        // 初始化工具提示
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl, {
                html: true,
                placement: 'top',
                trigger: 'hover'
            });
        });
    });
</script>
@endsection 