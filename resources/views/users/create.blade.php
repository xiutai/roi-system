@extends('layouts.app')

@section('title', '添加用户')

@section('page-title', '添加用户')

@section('page-actions')
<a href="{{ route('users.index') }}" class="btn btn-sm btn-secondary">
    <i class="fas fa-arrow-left"></i> 返回列表
</a>
@endsection

@section('content')
<div class="card">
    <div class="card-body">
        @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>{{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        @endif

        @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        @endif

        <form action="{{ route('users.store') }}" method="POST" id="userForm">
            @csrf
            
            <div class="mb-3">
                <label for="name" class="form-label">姓名 <span class="text-danger">*</span></label>
                <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" required>
                @error('name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            
            <div class="mb-3">
                <label for="username" class="form-label">用户名 <span class="text-danger">*</span></label>
                <input type="text" class="form-control @error('username') is-invalid @enderror" id="username" name="username" value="{{ old('username') }}" required>
                @error('username')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text">用户名将用于登录系统</div>
            </div>
            
            <div class="mb-3">
                <label for="email" class="form-label">邮箱</label>
                <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email') }}">
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            
            <div class="mb-3">
                <label for="password" class="form-label">密码 <span class="text-danger">*</span></label>
                <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password" required>
                @error('password')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            
            <div class="mb-3">
                <label for="password_confirmation" class="form-label">确认密码 <span class="text-danger">*</span></label>
                <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required>
            </div>
            
            <div class="mb-3 form-check">
                <input type="hidden" name="is_admin" value="0">
                <input type="checkbox" class="form-check-input" id="is_admin" name="is_admin" value="1" {{ old('is_admin') ? 'checked' : '' }}>
                <label class="form-check-label" for="is_admin">设为管理员</label>
                <small class="form-text text-muted d-block">管理员可以管理所有用户。</small>
            </div>
            
            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                <button type="submit" class="btn btn-primary" id="submitBtn">保存</button>
                <a href="{{ route('users.index') }}" class="btn btn-secondary">取消</a>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // 表单提交事件监听
    document.getElementById('userForm').addEventListener('submit', function(e) {
        // 防止按钮重复点击
        document.getElementById('submitBtn').disabled = true;
        document.getElementById('submitBtn').innerText = '保存中...';
    });
});
</script>
@endpush
@endsection 