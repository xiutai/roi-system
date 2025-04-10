@extends('layouts.app')

@section('title', '数据导入')

@section('page-title', '数据导入')

@section('content')
<div class="alert alert-info">
    <i class="bi bi-info-circle"></i> 通过上传CSV文件导入交易数据。系统将自动创建不存在的渠道。
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title">上传交易数据</h5>
            </div>
            <div class="card-body">
                <form id="import-form" action="{{ route('import.store') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <label for="excel_file" class="form-label">选择CSV文件 <span class="text-danger">*</span></label>
                        <input type="file" class="form-control @error('excel_file') is-invalid @enderror" id="excel_file" name="excel_file" accept=".csv">
                        @error('excel_file')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-3">
                        <label for="insert_date" class="form-label">选择插入日期 <small class="text-muted">(默认为今天)</small></label>
                        <input type="date" class="form-control @error('insert_date') is-invalid @enderror" id="insert_date" name="insert_date" value="{{ date('Y-m-d') }}">
                        <div class="form-text">如果选择的日期已有数据，新导入的数据将会替换相同日期的旧数据。</div>
                        @error('insert_date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    
                    <div id="upload-progress-container" class="mb-3 d-none">
                        <label class="form-label">上传进度</label>
                        <div class="progress">
                            <div id="upload-progress-bar" class="progress-bar" 
                                 role="progressbar" 
                                 style="width: 0%"
                                 aria-valuenow="0" 
                                 aria-valuemin="0" 
                                 aria-valuemax="100">0%</div>
                        </div>
                        <div id="upload-status" class="form-text mt-1"></div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary" id="import-btn">
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
                            <th>插入日期</th>
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
                                <td>{{ $job->insert_date ? $job->insert_date->format('Y-m-d') : '未设置' }}</td>
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
                                        @php
                                        $progressBarClass = $job->status == 'failed' ? 'progress-bar bg-danger' : 'progress-bar';
                                        $progressWidth = $job->progress_percentage;
                                        $styleAttr = "width: " . $progressWidth . "%;";
                                        @endphp
                                        <div class="{{ $progressBarClass }}" 
                                             role="progressbar" 
                                             style="{{ $styleAttr }}"
                                             aria-valuenow="{{ $progressWidth }}" 
                                             aria-valuemin="0" 
                                             aria-valuemax="100">
                                            {{ $progressWidth }}%
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
        <h6>CSV文件格式要求：</h6>
        <ul>
            <li>只支持CSV格式文件，不支持Excel格式</li>
            <li>文件必须包含标题行</li>
            <li>必填字段：注册来源(registration_source)、注册时间(registration_time)</li>
            <li>其他支持的字段：币种(currency)、会员ID(member_id)、会员账号(member_account)、总充提差额(balance_difference)</li>
            <li>注册时间格式：YYYY-MM-DD 或 YYYY-MM-DD HH:MM:SS</li>
            <li>字段名称不区分大小写，可以使用中文或英文字段名</li>
            <li>如果数据量较大，导入可能需要几分钟时间</li>
        </ul>
        
        <h6>支持的字段名称示例：</h6>
        <ul>
            <li>注册来源：zhu_ce_lai_yuan, 注册来源, registration_source, source, channel等</li>
            <li>注册时间：zhu_ce_shi_jian, 注册时间, registration_time, time, date等</li>
            <li>币种：bi_zhong, 币种, currency, cur等</li>
            <li>会员ID：hui_yuan_id, 会员id, member_id, mid等</li>
            <li>会员账号：hui_yuan_zhang_hao, 会员账号, member_account, account等</li>
            <li>总充提差额：zong_chong_ti_cha_e, 总充提差额, balance_difference, balance等</li>
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
        
        // 异步上传功能
        const importForm = document.getElementById('import-form');
        const importBtn = document.getElementById('import-btn');
        const fileInput = document.getElementById('excel_file');
        const dateInput = document.getElementById('insert_date');
        const progressContainer = document.getElementById('upload-progress-container');
        const progressBar = document.getElementById('upload-progress-bar');
        const statusText = document.getElementById('upload-status');
        
        if (importForm) {
            importForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // 验证文件已选择
                if (!fileInput.files.length) {
                    alert('请选择要上传的文件');
                    return;
                }
                
                // 准备FormData对象
                const formData = new FormData();
                formData.append('excel_file', fileInput.files[0]);
                if (dateInput.value) {
                    formData.append('insert_date', dateInput.value);
                }
                formData.append('_token', '{{ csrf_token() }}');
                
                // 设置上传按钮为禁用状态
                importBtn.disabled = true;
                importBtn.innerHTML = '<i class="bi bi-hourglass"></i> 上传中...';
                
                // 显示进度条
                progressContainer.classList.remove('d-none');
                progressBar.style.width = '0%';
                progressBar.textContent = '0%';
                statusText.textContent = '准备上传...';
                
                // 创建XHR请求
                const xhr = new XMLHttpRequest();
                
                // 上传进度事件
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        const percentComplete = Math.round((e.loaded / e.total) * 100);
                        progressBar.style.width = percentComplete + '%';
                        progressBar.textContent = percentComplete + '%';
                        statusText.textContent = '正在上传文件...';
                    }
                });
                
                // 上传完成事件
                xhr.addEventListener('load', function() {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                progressBar.style.width = '100%';
                                progressBar.textContent = '100%';
                                statusText.textContent = '上传成功！文件已在后台处理中，页面将在3秒后刷新...';
                                statusText.classList.add('text-success');
                                
                                // 3秒后刷新页面
                                setTimeout(function() {
                                    window.location.reload();
                                }, 3000);
                            } else {
                                handleError(response.error || '上传失败，请重试');
                            }
                        } catch (e) {
                            handleError('解析响应失败');
                        }
                    } else {
                        handleError('服务器错误：' + xhr.status);
                    }
                });
                
                // 上传错误事件
                xhr.addEventListener('error', function() {
                    handleError('网络错误，上传失败');
                });
                
                // 上传中止事件
                xhr.addEventListener('abort', function() {
                    handleError('上传已取消');
                });
                
                // 发送请求
                xhr.open('POST', '/import/async', true);
                xhr.send(formData);
                
                // 处理错误函数
                function handleError(message) {
                    progressBar.classList.remove('bg-primary');
                    progressBar.classList.add('bg-danger');
                    statusText.textContent = '错误: ' + message;
                    statusText.classList.add('text-danger');
                    
                    // 重置按钮状态
                    setTimeout(function() {
                        importBtn.disabled = false;
                        importBtn.innerHTML = '<i class="bi bi-upload"></i> 导入数据';
                    }, 2000);
                }
            });
        }
    });
</script>
@endsection 