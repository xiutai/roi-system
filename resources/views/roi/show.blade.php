@extends('layouts.app')

@section('title', '渠道ROI详情')

@section('page-title', "渠道ROI详情：{$channel->name} - " . \Carbon\Carbon::parse($date)->format('Y-m-d'))

@section('page-actions')
<a href="{{ route('roi.show', [$date, $channel->id, 'refresh' => true]) }}" class="btn btn-sm btn-success">
    <i class="bi bi-arrow-repeat"></i> 刷新数据
</a>
<a href="{{ route('roi.index') }}" class="btn btn-sm btn-secondary">
    <i class="bi bi-arrow-left"></i> 返回列表
</a>
@endsection

@section('content')
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">渠道信息</h5>
                <dl class="row">
                    <dt class="col-sm-3">渠道ID</dt>
                    <dd class="col-sm-9">{{ $channel->id }}</dd>
                    
                    <dt class="col-sm-3">渠道名称</dt>
                    <dd class="col-sm-9">{{ $channel->name }}</dd>
                    
                    <dt class="col-sm-3">渠道描述</dt>
                    <dd class="col-sm-9">{{ $channel->description ?: '无' }}</dd>
                </dl>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <h5 class="card-title">当日数据</h5>
                <dl class="row">
                    <dt class="col-sm-3">汇率</dt>
                    <dd class="col-sm-9">{{ $exchangeRate ? $exchangeRate->rate : '未设置' }}</dd>
                    
                    <dt class="col-sm-3">消耗</dt>
                    <dd class="col-sm-9">{{ $expense ? $expense->amount : '0.00' }}</dd>
                    
                    @foreach($roiData as $roi)
                        @if($roi->day_count == 1)
                            <dt class="col-sm-3">当日充提差额</dt>
                            <dd class="col-sm-9">{{ number_format($roi->cumulative_balance, 2) }}</dd>
                            
                            <dt class="col-sm-3">当日ROI</dt>
                            <dd class="col-sm-9 {{ $roi->roi_percentage > 0 ? 'text-success' : 'text-danger' }}">
                                {{ number_format($roi->roi_percentage, 2) }}%
                            </dd>
                        @endif
                    @endforeach
                </dl>
            </div>
        </div>
    </div>
</div>

<!-- ROI数据图表 -->
<div class="card mb-4">
    <div class="card-body">
        <h5 class="card-title">ROI趋势</h5>
        <canvas id="roiChart" width="400" height="150"></canvas>
    </div>
</div>

<!-- ROI数据表格 -->
<div class="card">
    <div class="card-body">
        <h5 class="card-title">ROI详细数据</h5>
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>天数</th>
                        <th>累计充提差额</th>
                        <th>汇率</th>
                        <th>消耗</th>
                        <th>ROI</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($roiData as $roi)
                        <tr>
                            <td>{{ $roi->day_count }}日</td>
                            <td>{{ number_format($roi->cumulative_balance, 2) }}</td>
                            <td>{{ number_format($roi->exchange_rate, 2) }}</td>
                            <td>{{ number_format($roi->expense, 2) }}</td>
                            <td class="{{ $roi->roi_percentage > 0 ? 'text-success' : 'text-danger' }}">
                                {{ number_format($roi->roi_percentage, 2) }}%
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // 准备数据
        const roiData = {!! json_encode($roiData) !!};
        
        const days = roiData.map(item => item.day_count + '日');
        const rois = roiData.map(item => item.roi_percentage);
        
        // 创建图表
        const ctx = document.getElementById('roiChart').getContext('2d');
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: days,
                datasets: [{
                    label: 'ROI百分比',
                    data: rois,
                    backgroundColor: 'rgba(54, 162, 235, 0.2)',
                    borderColor: 'rgba(54, 162, 235, 1)',
                    borderWidth: 2,
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'ROI (%)'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: '天数'
                        }
                    }
                }
            }
        });
    });
</script>
@endsection 