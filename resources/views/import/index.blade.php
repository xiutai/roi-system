@extends('layouts.app')

@section('title', '数据导入')

@section('page-title', '数据导入')

@section('content')
<div class="alert alert-info">
    <i class="bi bi-info-circle"></i> 通过上传Excel或CSV文件导入交易数据。系统将自动创建不存在的渠道。
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">上传交易数据</h5>
            </div>
            <div class="card-body">
                <form action="{{ route('import.store') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label for="excel_file" class="form-label">选择Excel/CSV文件 <span class="text-danger">*</span></label>
                        <input type="file" class="form-control @error('excel_file') is-invalid @enderror" id="excel_file" name="excel_file" accept=".xlsx,.xls,.csv">
                        @error('excel_file')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload"></i> 导入数据
                    </button>
                    <a href="{{ asset('templates/transaction_template.csv') }}" class="btn btn-outline-secondary">
                        <i class="bi bi-download"></i> 下载CSV模板
                    </a>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">数据管理</h5>
            </div>
            <div class="card-body">
                <p>当前系统中共有 <strong>{{ $transactionCount }}</strong> 条交易记录。</p>
                
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#clearDataModal">
                        <i class="bi bi-trash"></i> 清空所有数据
                    </button>
                </div>
                
                <div class="mt-3 small text-muted">
                    <p><strong>注意：</strong> 清空数据操作将删除所有交易记录、ROI计算、渠道、汇率和消耗数据。此操作无法撤销！</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 导入任务列表 -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title">最近导入任务</h5>
    </div>
    <div class="card-body">
        @if($importJobs->count() > 0)
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>文件名</th>
                            <th>状态</th>
                            <th>进度</th>
                            <th>开始时间</th>
                            <th>完成时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($importJobs as $job)
                            <tr>
                                <td>{{ $job->id }}</td>
                                <td>{{ $job->original_filename }}</td>
                                <td>
                                    @if($job->status == 'pending')
                                        <span class="badge bg-secondary">等待中</span>
                                    @elseif($job->status == 'processing')
                                        <span class="badge bg-primary">处理中</span>
                                    @elseif($job->status == 'completed')
                                        <span class="badge bg-success">已完成</span>
                                    @elseif($job->status == 'failed')
                                        <span class="badge bg-danger">失败</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="progress">
                                        <div class="progress-bar {{ $job->status == 'failed' ? 'bg-danger' : '' }}" 
                                             role="progressbar" 
                                             style="width: {{ $job->progress_percentage }}%"
                                             aria-valuenow="{{ $job->progress_percentage }}" 
                                             aria-valuemin="0" 
                                             aria-valuemax="100">
                                            {{ $job->progress_percentage }}%
                                        </div>
                                    </div>
                                </td>
                                <td>{{ $job->started_at ? $job->started_at->format('Y-m-d H:i:s') : '未开始' }}</td>
                                <td>{{ $job->completed_at ? $job->completed_at->format('Y-m-d H:i:s') : '未完成' }}</td>
                                <td>
                                    <a href="{{ route('import.show', $job->id) }}" class="btn btn-sm btn-info">详情</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="text-muted">暂无导入任务记录</p>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="card-title">导入说明</h5>
    </div>
    <div class="card-body">
        <h6>CSV/Excel文件格式要求：</h6>
        <ul>
            <li>文件必须包含标题行</li>
            <li>支持的字段：币种、会员ID、会员账号、渠道ID（可选）、注册来源（必填，用作渠道名称）、注册时间、总充提差额</li>
            <li>注册时间格式：YYYY-MM-DD HH:MM:SS</li>
            <li>字段名称不区分大小写，可以使用中文或英文字段名</li>
            <li>如果数据量较大，导入可能需要几分钟时间</li>
        </ul>
        
        <h6>导入后的操作：</h6>
        <ul>
            <li>导入成功后，系统将自动计算最近日期的ROI</li>
            <li>对于大量数据，请在导入后手动触发ROI计算</li>
            <li>请确保已设置正确的汇率和消耗数据，否则ROI计算可能不准确</li>
        </ul>
        
        <h6>会员ID和数据更新：</h6>
        <ul>
            <li>如果导入的数据中会员ID与已有记录相同，系统将更新对应记录的总充提差额</li>
            <li>没有会员ID的记录将作为新记录插入</li>
            <li>导入进度可以在导入任务列表中查看</li>
        </ul>
    </div>
</div>

<!-- 清空数据确认弹窗 -->
<div class="modal fade" id="clearDataModal" tabindex="-1" aria-labelledby="clearDataModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="clearDataModalLabel">确认清空所有数据</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle-fill"></i> <strong>警告：</strong> 此操作将永久删除所有数据，包括：
                    <ul>
                        <li>交易记录</li>
                        <li>ROI计算结果</li>
                        <li>渠道信息</li>
                        <li>汇率设置</li>
                        <li>消耗数据</li>
                    </ul>
                    <p>删除后无法恢复！</p>
                </div>
                
                <form id="clearDataForm" action="{{ route('import.clear') }}" method="POST">
                    @csrf
                    <p>请输入"<strong>DELETE ALL DATA</strong>"以确认您了解此操作的风险：</p>
                    <input type="text" id="confirmText" class="form-control" placeholder="DELETE ALL DATA">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" id="confirmClearData" class="btn btn-danger" disabled>确认清空数据</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const confirmButton = document.getElementById('confirmClearData');
        const confirmText = document.getElementById('confirmText');
        const clearDataForm = document.getElementById('clearDataForm');
        
        confirmText.addEventListener('input', function() {
            confirmButton.disabled = this.value !== 'DELETE ALL DATA';
        });
        
        confirmButton.addEventListener('click', function() {
            clearDataForm.submit();
        });
        
        // 自动刷新导入任务进度
        const refreshProgress = function() {
            const progressBars = document.querySelectorAll('.progress-bar');
            if (progressBars.length > 0) {
                const pendingOrProcessing = document.querySelector('.badge.bg-secondary, .badge.bg-primary');
                if (pendingOrProcessing) {
                    setTimeout(function() {
                        window.location.reload();
                    }, 5000); // 5秒后刷新
                }
            }
        };
        
        refreshProgress();
    });
</script>
@endsection 