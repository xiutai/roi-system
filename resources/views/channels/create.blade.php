@extends('layouts.app')

@section('title', '添加渠道')

@section('page-title', '添加渠道')

@section('page-actions')
<a href="{{ route('channels.index') }}" class="btn btn-sm btn-secondary">
    <i class="bi bi-arrow-left"></i> 返回列表
</a>
@endsection

@section('content')
<div class="card">
    <div class="card-body">
        <form action="{{ route('channels.store') }}" method="POST">
            @csrf
            
            <div class="mb-3">
                <label for="name" class="form-label">渠道名称 <span class="text-danger">*</span></label>
                <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name') }}" required>
                @error('name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text">渠道名称必须唯一，将用于导入数据时识别渠道</div>
            </div>
            
            <div class="mb-3">
                <label for="description" class="form-label">渠道描述</label>
                <textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="3">{{ old('description') }}</textarea>
                @error('description')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text">可选，对渠道的详细描述</div>
            </div>
            
            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                <button type="submit" class="btn btn-primary">保存</button>
            </div>
        </form>
    </div>
</div>

<div class="card mt-4">
    <div class="card-header">
        <h5>使用说明</h5>
    </div>
    <div class="card-body">
        <ul>
            <li>渠道是导入交易数据时的重要分类依据</li>
            <li>渠道名称在系统中必须唯一，请勿重复创建相同渠道</li>
            <li>渠道创建后，可在导入Excel文件时选择对应渠道</li>
            <li>每个渠道的ROI统计数据可在仪表盘中单独查看</li>
        </ul>
    </div>
</div>
@endsection 