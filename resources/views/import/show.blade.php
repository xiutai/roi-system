@extends('layouts.app')

@section('title', '导入任务详情')

@section('page-title')
导入任务详情 #{{ $importJob->id }}
@endsection

@section('page-actions')
<a href="{{ route('import.index') }}" class="btn btn-sm btn-secondary">
    <i class="fas fa-arrow-left me-1"></i> 返回导入列表
</a>
@if($importJob->isFailed() || $importJob->isCompleted())
<button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">
    <i class="fas fa-trash me-1"></i> 删除导入任务
</button>
@endif
@endsection

@section('content')
<div class="row">
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title">任务信息</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>文件名:</strong> {{ $importJob->original_filename }}</p>
                        <p><strong>上传时间:</strong> {{ $importJob->created_at->format('Y-m-d H:i:s') }}</p>
                        <p><strong>开始处理时间:</strong> 
                            {{ $importJob->started_at ? $importJob->started_at->format('Y-m-d H:i:s') : '未开始' }}
                        </p>
                        <p><strong>完成时间:</strong> 
                            {{ $importJob->completed_at ? $importJob->completed_at->format('Y-m-d H:i:s') : '未完成' }}
                        </p>
                    </div>
                    <div class="col-md-6">
                        <p>
                            <strong>状态:</strong>
                            @if($importJob->status == 'pending')
                                <span class="badge bg-secondary">等待中</span>
                            @elseif($importJob->status == 'processing')
                                <span class="badge bg-primary">处理中</span>
                            @elseif($importJob->status == 'completed')
                                <span class="badge bg-success">已完成</span>
                            @elseif($importJob->status == 'failed')
                                <span class="badge bg-danger">失败</span>
                            @endif
                        </p>
                        <p><strong>总行数:</strong> {{ $importJob->total_rows ?: '未知' }}</p>
                        <p><strong>已处理行数:</strong> {{ $importJob->processed_rows }}</p>
                        <p><strong>已用时间:</strong> 
                            @if($importJob->started_at)
                                @if($importJob->completed_at)
                                    {{ $importJob->started_at->diffInMinutes($importJob->completed_at) }} 分钟
                                @else
                                    {{ $importJob->started_at->diffInMinutes(now()) }} 分钟 (进行中)
                                @endif
                            @else
                                尚未开始
                            @endif
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title">数据导入详情</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <h6>总体进度：</h6>
                            <div class="progress" style="height: 25px;">
                                <div class="progress-bar {{ $importJob->status == 'failed' ? 'bg-danger' : '' }} progress-bar-striped {{ $importJob->isProcessing() ? 'progress-bar-animated' : '' }}"
                                    role="progressbar"
                                    style="width: {{ $importJob->progress_percentage }}%;"
                                    aria-valuenow="{{ $importJob->progress_percentage }}"
                                    aria-valuemin="0"
                                    aria-valuemax="100">
                                    {{ $importJob->progress_percentage }}%
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex mb-3">
                            <div id="import-stats" class="w-100">
                                <p><strong>已处理行数:</strong> <span id="processed-rows">{{ $importJob->processed_rows }}</span> / <span id="total-rows">{{ $importJob->total_rows ?: '未知' }}</span></p>
                                <p><strong>新增记录数:</strong> <span id="inserted-rows">{{ $importJob->inserted_rows }}</span></p>
                                <p><strong>更新记录数:</strong> <span id="updated-rows">{{ $importJob->updated_rows }}</span></p>
                                <p><strong>错误记录数:</strong> <span id="error-rows">{{ $importJob->error_rows }}</span></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                @if($importJob->error_message)
                <div class="alert alert-danger">
                    <h6>错误信息：</h6>
                    <pre>{{ $importJob->error_message }}</pre>
                </div>
                @endif
                
                @if($importJob->error_details && count($importJob->error_details_array) > 0)
                <div class="card mb-3 border-danger">
                    <div class="card-header bg-danger text-white">
                        <h6 class="mb-0">错误详情（{{ count($importJob->error_details_array) }}条）</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th width="10%">序号</th>
                                        <th width="90%">错误描述</th>
                                    </tr>
                                </thead>
                                <tbody style="max-height: 300px; overflow-y: auto;">
                                    @foreach($importJob->error_details_array as $index => $error)
                                    <tr>
                                        <td>{{ $index + 1 }}</td>
                                        <td>{{ $error }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                @endif
                
                @if($importJob->isCompleted())
                <div class="alert alert-success">
                    <h6>导入已完成！</h6>
                    <p>成功导入 {{ $importJob->inserted_rows }} 条新记录，更新 {{ $importJob->updated_rows }} 条记录。</p>
                    <p>您可以前往 <a href="{{ route('roi.index') }}" class="alert-link">ROI分析</a> 页面查看导入数据的效果。</p>
                </div>
                @endif
                
                @if($importJob->isFailed())
                <div class="alert alert-danger">
                    <h6>导入失败！</h6>
                    <p>请检查导入文件格式是否正确，或联系系统管理员。</p>
                    <button type="button" class="btn btn-danger mt-2" data-bs-toggle="modal" data-bs-target="#deleteModal">
                        <i class="fas fa-trash me-1"></i> 删除此导入任务
                    </button>
                </div>
                @endif
                
                @if($importJob->isPending() || $importJob->isProcessing())
                <div class="alert alert-info">
                    <h6>导入正在进行中...</h6>
                    <p>请耐心等待，系统正在处理您的数据。导入大量数据可能需要几分钟时间。</p>
                    <p>此页面将自动刷新进度信息，无需手动刷新。</p>
                </div>
                @endif
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title">导入说明</h5>
            </div>
            <div class="card-body">
                <h6>会员ID和数据更新：</h6>
                <ul>
                    <li>如果导入的数据中会员ID与已有记录相同，系统将更新对应记录的总充提差额</li>
                    <li>没有会员ID的记录将作为新记录插入</li>
                </ul>
                
                <h6>导入后操作：</h6>
                <ul>
                    <li>导入完成后，您可以在ROI分析页面查看数据</li>
                    <li>对于大量数据，您可能需要手动触发ROI计算</li>
                </ul>
                
                <h6>处理过程：</h6>
                <p>导入过程包括以下步骤：</p>
                <ol>
                    <li>读取上传的文件</li>
                    <li>解析文件内容和字段</li>
                    <li>检查会员ID是否存在，决定更新或新增</li>
                    <li>批量处理数据写入数据库</li>
                    <li>完成后更新导入任务状态</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<!-- 删除确认弹窗 -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteModalLabel">确认删除</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>确定要删除此导入任务吗？此操作不可恢复。</p>
                <p><strong>文件名:</strong> {{ $importJob->original_filename }}</p>
                <p><strong>上传时间:</strong> {{ $importJob->created_at->format('Y-m-d H:i:s') }}</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <form action="{{ route('import.destroy', $importJob->id) }}" method="POST">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">确认删除</button>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // 只有在任务正在处理或等待中时才自动刷新
        @if($importJob->isPending() || $importJob->isProcessing())
        function updateProgress() {
            fetch('{{ route('import.progress', $importJob->id) }}', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('网络错误，状态码: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                // 更新进度条
                const progressBar = document.querySelector('.progress-bar');
                progressBar.style.width = data.progress_percentage + '%';
                progressBar.setAttribute('aria-valuenow', data.progress_percentage);
                progressBar.textContent = data.progress_percentage + '%';
                
                // 更新统计数据
                document.getElementById('processed-rows').textContent = data.processed_rows;
                document.getElementById('total-rows').textContent = data.total_rows || '未知';
                document.getElementById('inserted-rows').textContent = data.inserted_rows;
                document.getElementById('updated-rows').textContent = data.updated_rows;
                document.getElementById('error-rows').textContent = data.error_rows;
                
                // 如果任务完成或失败，刷新整个页面
                if (data.status === 'completed' || data.status === 'failed') {
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                } else {
                    // 否则继续轮询
                    setTimeout(updateProgress, 2000);
                }
            })
            .catch(error => {
                console.error('更新进度失败:', error);
                // 错误后依然尝试继续更新，降低频率
                setTimeout(updateProgress, 5000);
            });
        }
        
        // 开始轮询
        updateProgress();
        @endif
    });
</script>
@endsection 