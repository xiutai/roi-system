@extends('layouts.app')

@section('title', '用户管理')

@section('page-title', '用户管理')

@section('page-actions')
<a href="{{ route('users.create') }}" class="btn btn-sm btn-primary">
    <i class="fas fa-plus"></i> 添加用户
</a>
@endsection

@section('content')
<!-- 用户列表 -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>姓名</th>
                        <th>邮箱</th>
                        <th>角色</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    @if($users->count() > 0)
                        @foreach($users as $user)
                            <tr>
                                <td>{{ $user->id }}</td>
                                <td>{{ $user->name }}</td>
                                <td>{{ $user->email }}</td>
                                <td>
                                    @if($user->is_admin)
                                        <span class="badge bg-primary">管理员</span>
                                    @else
                                        <span class="badge bg-secondary">普通用户</span>
                                    @endif
                                </td>
                                <td>{{ $user->created_at->format('Y-m-d H:i') }}</td>
                                <td>
                                    <div class="btn-group" role="group">
                                        <a href="{{ route('users.edit', $user) }}" class="btn btn-sm btn-primary">编辑</a>
                                        
                                        @if($user->id !== auth()->id())
                                            <form action="{{ route('users.destroy', $user) }}" method="POST" onsubmit="return confirm('确定要删除此用户吗？')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-danger">删除</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    @else
                        <tr>
                            <td colspan="6" class="text-center">暂无数据</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection 