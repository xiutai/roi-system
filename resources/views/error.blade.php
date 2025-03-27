@extends('layouts.app')

@section('title', '系统错误')

@section('page-title', '系统错误')

@section('content')
<div class="card">
    <div class="card-header bg-danger text-white">
        <h5 class="mb-0"><i class="bi bi-exclamation-triangle"></i> 系统错误</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-danger">
            {{ $message ?? '系统发生错误，请联系管理员。' }}
        </div>

        @if(config('app.debug') && isset($error))
        <div class="card mt-3">
            <div class="card-header">
                <h6 class="mb-0">错误详情（仅管理员可见）</h6>
            </div>
            <div class="card-body">
                <pre class="bg-light p-3">{{ $error }}</pre>
            </div>
        </div>
        @endif

        <div class="mt-4">
            <a href="{{ route('dashboard') }}" class="btn btn-primary">
                <i class="bi bi-arrow-left"></i> 返回仪表盘
            </a>
            <a href="javascript:window.location.reload()" class="btn btn-secondary">
                <i class="bi bi-arrow-clockwise"></i> 刷新页面
            </a>
        </div>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header">
        <h5 class="mb-0">可能的解决方案</h5>
    </div>
    <div class="card-body">
        <ol>
            <li>确保您的数据库已正确设置并运行</li>
            <li>确保所有数据库迁移已运行: <code>php artisan migrate</code></li>
            <li>检查数据库连接设置是否正确</li>
            <li>清除应用缓存: <code>php artisan cache:clear</code></li>
            <li>清除配置缓存: <code>php artisan config:clear</code></li>
            <li>清除路由缓存: <code>php artisan route:clear</code></li>
            <li>清除视图缓存: <code>php artisan view:clear</code></li>
        </ol>
    </div>
</div>
@endsection 