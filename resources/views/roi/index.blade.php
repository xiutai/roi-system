@extends('layouts.app')

@section('title', 'ROI分析')

@section('page-title', 'ROI分析')

@section('page-actions')
@if(isset($hasFilters) && $hasFilters)
<form action="{{ route('roi.recalculate') }}" method="POST" style="display: inline;">
    @csrf
    <input type="hidden" name="start_date" value="{{ $startDate }}">
    <input type="hidden" name="end_date" value="{{ $endDate }}">
    <input type="hidden" name="channel_id" value="{{ $channelId }}">
    <button type="submit" class="btn btn-sm btn-primary">
        <i class="fas fa-sync-alt me-1"></i> 刷新数据
    </button>
</form>
@endif
@endsection

@section('content')
<!-- 筛选表单 -->
<div class="card mb-4">
    <div class="card-body">
        <h5 class="card-title">
            <i class="fas fa-filter me-2"></i>数据筛选
        </h5>
        <form method="GET" action="{{ route('roi.index') }}" class="row g-3">
            <div class="col-md-4">
                <label for="daterange" class="form-label fw-medium">日期范围</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="fas fa-calendar-alt"></i></span>
                    <input type="text" class="form-control" id="daterange" name="daterange" value="{{ $startDate }} - {{ $endDate }}">
                </div>
                <input type="hidden" name="start_date" id="start_date" value="{{ $startDate }}">
                <input type="hidden" name="end_date" id="end_date" value="{{ $endDate }}">
            </div>
            <div class="col-md-4">
                <label for="channel_id" class="form-label fw-medium">渠道</label>
                <div class="input-group">
                    <span class="input-group-text bg-light"><i class="fas fa-sitemap"></i></span>
                    <select class="form-select" id="channel_id" name="channel_id">
                        <option value="">所有渠道</option>
                        @foreach($channels as $channel)
                            <option value="{{ $channel->id }}" {{ isset($channelId) && $channelId == $channel->id ? 'selected' : '' }}>{{ $channel->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search me-1"></i> 筛选
                </button>
                <a href="{{ route('roi.index') }}" class="btn btn-secondary ms-2">
                    <i class="fas fa-redo me-1"></i> 重置
                </a>
                <input type="hidden" name="hasFilters" value="1">
            </div>
        </form>
    </div>
</div>

@if(isset($hasFilters) && $hasFilters)
    <!-- 仪表盘样式的ROI数据表格 -->
    <div class="card">
        <div class="card-body">
            <h5 class="card-title">
                <i class="fas fa-chart-line me-2"></i>ROI数据（{{ isset($channelId) && $channelId ? $channels->where('id', $channelId)->first()->name : '全部渠道' }}）
            </h5>
            
            <div class="alert alert-info bg-light-info border-0">
                <i class="fas fa-info-circle me-2 text-info"></i>
                ROI数据已实现自动计算。每次筛选后，系统会按需计算最新数据。
                点击"刷新数据"按钮可强制更新所有显示的数据。
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
                        @if(isset($summaryData))
                        <tr class="table-secondary fw-bold">
                            <td class="align-middle">{{ $summaryData['date'] }}</td>
                            <td class="align-middle">{{ isset($channelId) && $channelId ? ($channels->where('id', $channelId)->first()->name ?? '未知渠道') : '全部渠道' }}</td>
                            <td class="align-middle">${{ number_format($summaryData['expense'], 2) }}</td>
                            <td class="align-middle">{{ $summaryData['registrations'] }}</td>
                            <td class="align-middle">${{ number_format($summaryData['cpa'], 2) }}</td>
                            <td class="align-middle">{{ $summaryData['paying_users'] }}</td>
                            <td class="align-middle">${{ number_format($summaryData['first_deposit_price'] ?? 0, 2) }}</td>
                            <td class="align-middle">{{ number_format($summaryData['conversion_rate'], 2) }}%</td>
                            <td class="align-middle {{ $summaryData['daily_roi'] > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                {{ $summaryData['daily_roi'] }}%
                            </td>
                            
                            <!-- 汇总行的多日ROI数据 -->
                            <td class="align-middle {{ ($summaryData['roi_trends'][2] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                {{ $summaryData['roi_trends'][2] ?? 0 }}%
                            </td>
                            <td class="align-middle {{ ($summaryData['roi_trends'][3] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                {{ $summaryData['roi_trends'][3] ?? 0 }}%
                            </td>
                            <td class="align-middle {{ ($summaryData['roi_trends'][5] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                {{ $summaryData['roi_trends'][5] ?? 0 }}%
                            </td>
                            <td class="align-middle {{ ($summaryData['roi_trends'][7] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                {{ $summaryData['roi_trends'][7] ?? 0 }}%
                            </td>
                            <td class="align-middle {{ ($summaryData['roi_trends'][14] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                {{ $summaryData['roi_trends'][14] ?? 0 }}%
                            </td>
                            <td class="align-middle {{ ($summaryData['roi_trends'][30] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                {{ $summaryData['roi_trends'][30] ?? 0 }}%
                            </td>
                            <td class="align-middle {{ ($summaryData['roi_trends'][40] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                {{ $summaryData['roi_trends'][40] ?? 0 }}%
                            </td>
                            <td class="align-middle {{ ($summaryData['roi_after_40'] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                {{ $summaryData['roi_after_40'] ?? 0 }}%
                            </td>
                        </tr>
                        @endif
                        
                        <!-- 然后显示每日数据行 -->
                        @if(isset($displayDates) && count($displayDates) > 0)
                            @foreach($displayDates as $dateStr)
                                @php
                                    $row = $dailyStats[$dateStr] ?? null;
                                @endphp
                                @if($row)
                                <tr>
                                    <td>{{ $dateStr }}</td>
                                    <td>{{ isset($channelId) && $channelId ? ($channels->where('id', $channelId)->first()->name ?? '未知渠道') : '全部渠道' }}</td>
                                    <td>${{ number_format($row['expense'], 2) }}</td>
                                    <td>{{ $row['registrations'] }}</td>
                                    <td>${{ number_format($row['cpa'], 2) }}</td>
                                    <td>{{ $row['paying_users'] }}</td>
                                    <td>${{ number_format($row['first_deposit_price'] ?? 0, 2) }}</td>
                                    <td>{{ number_format($row['conversion_rate'], 2) }}%</td>
                                    <td class="{{ $row['daily_roi'] > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                        {{ $row['daily_roi'] }}%
                                    </td>
                                    
                                    <!-- 多日ROI数据 -->
                                    <td class="{{ ($row['roi_trends'][2] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                        {{ $row['roi_trends'][2] ?? 0 }}%
                                    </td>
                                    <td class="{{ ($row['roi_trends'][3] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                        {{ $row['roi_trends'][3] ?? 0 }}%
                                    </td>
                                    <td class="{{ ($row['roi_trends'][5] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                        {{ $row['roi_trends'][5] ?? 0 }}%
                                    </td>
                                    <td class="{{ ($row['roi_trends'][7] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                        {{ $row['roi_trends'][7] ?? 0 }}%
                                    </td>
                                    <td class="{{ ($row['roi_trends'][14] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                        {{ $row['roi_trends'][14] ?? 0 }}%
                                    </td>
                                    <td class="{{ ($row['roi_trends'][30] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                        {{ $row['roi_trends'][30] ?? 0 }}%
                                    </td>
                                    <td class="{{ ($row['roi_trends'][40] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                        {{ $row['roi_trends'][40] ?? 0 }}%
                                    </td>
                                    <td class="{{ ($row['roi_after_40'] ?? 0) > 0 ? 'text-success fw-bold' : 'text-danger' }}">
                                        {{ $row['roi_after_40'] ?? 0 }}%
                                    </td>
                                </tr>
                                @endif
                            @endforeach
                        @else
                            <tr>
                                <td colspan="17" class="text-center py-5">
                                    <i class="fas fa-database fa-3x text-muted mb-3"></i>
                                    <p class="text-muted">暂无数据</p>
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@else
    <!-- 筛选提示信息 -->
    <div class="card">
        <div class="card-body text-center p-5">
            <div class="mb-4">
                <i class="fas fa-chart-bar fa-5x text-primary opacity-50"></i>
            </div>
            <h4 class="mb-3">请选择渠道和日期范围以查看ROI数据</h4>
            <p class="text-muted">
                使用上方筛选表单选择要分析的渠道和日期范围，系统将自动计算并显示相应的ROI数据。
            </p>
        </div>
    </div>
@endif
@endsection

@section('styles')
<style>
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
    .form-label {
        color: #2c3e50;
    }
    .input-group-text {
        border-right: none;
    }
    .input-group .form-control {
        border-left: none;
    }
</style>
@endsection

@section('scripts')
<script>
    $(function() {
        // 设置日期范围选择器
        $('#daterange').daterangepicker({
            locale: {
                format: 'YYYY-MM-DD',
                applyLabel: '确定',
                cancelLabel: '取消',
                fromLabel: '从',
                toLabel: '到',
                customRangeLabel: '自定义',
                weekLabel: '周',
                daysOfWeek: ['日', '一', '二', '三', '四', '五', '六'],
                monthNames: ['一月', '二月', '三月', '四月', '五月', '六月', '七月', '八月', '九月', '十月', '十一月', '十二月'],
                firstDay: 1
            },
            startDate: '{{ $startDate }}',
            endDate: '{{ $endDate }}',
            ranges: {
                '今天': [moment(), moment()],
                '昨天': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                '最近3天': [moment().subtract(2, 'days'), moment()],
                '最近7天': [moment().subtract(6, 'days'), moment()],
                '最近14天': [moment().subtract(13, 'days'), moment()],
                '最近30天': [moment().subtract(29, 'days'), moment()],
                '最近60天': [moment().subtract(59, 'days'), moment()],
                '最近90天': [moment().subtract(89, 'days'), moment()],
                '本月': [moment().startOf('month'), moment().endOf('month')],
                '上月': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')],
                '前3个月': [moment().subtract(3, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')],
                '本季度': [moment().startOf('quarter'), moment().endOf('quarter')],
                '上季度': [moment().subtract(1, 'quarter').startOf('quarter'), moment().subtract(1, 'quarter').endOf('quarter')],
                '本年': [moment().startOf('year'), moment().endOf('year')],
                '上一年': [moment().subtract(1, 'year').startOf('year'), moment().subtract(1, 'year').endOf('year')]
            }
        }, function(start, end, label) {
            $('#start_date').val(start.format('YYYY-MM-DD'));
            $('#end_date').val(end.format('YYYY-MM-DD'));
        });
    });
</script>
@endsection 