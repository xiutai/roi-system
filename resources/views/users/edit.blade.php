@extends('layouts.app')

@section('title', '编辑用户')

@section('page-title', '编辑用户')

@section('page-actions')
<a href="{{ route('users.index') }}" class="btn btn-sm btn-secondary">
    <i class="fas fa-arrow-left"></i> 返回列表
</a>
@endsection

@section('content')
<div class="card">
    <div class="card-body">
        <form action="{{ route('users.update', $user) }}" method="POST">
            @csrf
            @method('PUT')
            
            <div class="mb-3">
                <label for="name" class="form-label">姓名 <span class="text-danger">*</span></label>
                <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $user->name) }}" required>
                @error('name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            
            <div class="mb-3">
                <label for="username" class="form-label">用户名 <span class="text-danger">*</span></label>
                <input type="text" class="form-control @error('username') is-invalid @enderror" id="username" name="username" value="{{ old('username', $user->username) }}" required>
                @error('username')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text">用户名将用于登录系统</div>
            </div>
            
            <div class="mb-3">
                <label for="email" class="form-label">邮箱</label>
                <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email', $user->email) }}">
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            
            <div class="mb-3">
                <label for="password" class="form-label">密码</label>
                <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password">
                <small class="form-text text-muted">留空表示不修改密码</small>
                @error('password')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            
            <div class="mb-3">
                <label for="password_confirmation" class="form-label">确认密码</label>
                <input type="password" class="form-control" id="password_confirmation" name="password_confirmation">
            </div>
            
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="is_admin" name="is_admin" {{ old('is_admin', $user->is_admin) ? 'checked' : '' }}>
                <label class="form-check-label" for="is_admin">设为管理员</label>
                <small class="form-text text-muted d-block">管理员可以管理所有用户。</small>
            </div>
            
            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                <button type="submit" class="btn btn-primary">更新</button>
                <a href="{{ route('users.index') }}" class="btn btn-secondary">取消</a>
            </div>
        </form>
    </div>
</div>

@if($user->id === auth()->id())
<div class="card mt-4">
    <div class="card-header bg-warning text-dark">
        <h5>警告</h5>
    </div>
    <div class="card-body">
        <p>您正在编辑自己的账户。</p>
        <p>如果您取消自己的管理员权限，您将无法再访问用户管理功能。</p>
    </div>
</div>
@endif
@endsection 