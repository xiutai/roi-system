@extends('layouts.app')

@section('title', '渠道管理')

@section('page-title', '渠道管理')

@section('page-actions')
<a href="{{ route('channels.create') }}" class="btn btn-sm btn-primary">
    <i class="bi bi-plus"></i> 手动添加渠道
</a>
@endsection

@section('content')
<div class="alert alert-info">
    <i class="bi bi-info-circle"></i> 渠道会从导入的数据中自动创建，通常不需要手动添加。您可以编辑渠道描述或为空渠道添加消耗数据。
</div>

<!-- 渠道列表 -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>渠道名称</th>
                        <th>渠道描述</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    @if($channels->count() > 0)
                        @foreach($channels as $channel)
                            <tr>
                                <td>{{ $channel->id }}</td>
                                <td>{{ $channel->name }}</td>
                                <td>{{ $channel->description ?: '无' }}</td>
                                <td>{{ $channel->created_at->format('Y-m-d H:i') }}</td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <a href="{{ route('channels.edit', $channel) }}" class="btn btn-sm btn-primary">编辑</a>
                                        <button type="button" class="btn btn-sm btn-danger" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#deleteChannelModal" 
                                            data-channel-id="{{ $channel->id }}"
                                            data-channel-name="{{ $channel->name }}">
                                            删除
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    @else
                        <tr>
                            <td colspan="5" class="text-center">暂无数据</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 删除确认弹窗 -->
<div class="modal fade" id="deleteChannelModal" tabindex="-1" aria-labelledby="deleteChannelModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteChannelModalLabel">确认删除渠道</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="deleteChannelForm" action="" method="POST">
                @csrf
                @method('DELETE')
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle-fill"></i> <strong>警告:</strong> 删除渠道将同时删除该渠道的所有交易记录、消耗记录和ROI数据。此操作不可恢复！
                    </div>
                    <p>要删除渠道 <strong id="channel-name-display"></strong>，请输入完整的渠道名称以确认：</p>
                    <div class="mb-3">
                        <input type="text" class="form-control" name="confirm_text" required placeholder="请输入完整的渠道名称">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-danger">确认删除</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const deleteModal = document.getElementById('deleteChannelModal');
        if (deleteModal) {
            deleteModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const channelId = button.getAttribute('data-channel-id');
                const channelName = button.getAttribute('data-channel-name');
                
                const nameDisplay = document.getElementById('channel-name-display');
                nameDisplay.textContent = channelName;
                
                const form = document.getElementById('deleteChannelForm');
                form.action = `{{ url('channels') }}/${channelId}`;
                
                // 清空输入框
                form.querySelector('input[name="confirm_text"]').value = '';
            });
        }
    });
</script>
@endsection 