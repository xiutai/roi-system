@extends('layouts.app')

@section('title', '编辑渠道')

@section('page-title', '编辑渠道')

@section('page-actions')
<a href="{{ route('channels.index') }}" class="btn btn-sm btn-secondary">
    <i class="bi bi-arrow-left"></i> 返回列表
</a>
@endsection

@section('content')
<div class="card">
    <div class="card-body">
        <form action="{{ route('channels.update', $channel) }}" method="POST">
            @csrf
            @method('PUT')
            
            <div class="mb-3">
                <label for="name" class="form-label">渠道名称 <span class="text-danger">*</span></label>
                <input type="text" class="form-control @error('name') is-invalid @enderror" id="name" name="name" value="{{ old('name', $channel->name) }}" required>
                @error('name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text">渠道名称必须唯一，将用于导入数据时识别渠道</div>
            </div>
            
            <div class="mb-3">
                <label for="description" class="form-label">渠道描述</label>
                <textarea class="form-control @error('description') is-invalid @enderror" id="description" name="description" rows="3">{{ old('description', $channel->description) }}</textarea>
                @error('description')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text">可选，对渠道的详细描述</div>
            </div>
            
            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                <button type="submit" class="btn btn-primary">更新</button>
            </div>
        </form>
    </div>
</div>

@if($channel->transactions_count > 0)
<div class="card mt-4">
    <div class="card-header bg-warning text-dark">
        <h5>警告</h5>
    </div>
    <div class="card-body">
        <p>此渠道已关联 <strong>{{ $channel->transactions_count }}</strong> 条交易记录。</p>
        <p>修改渠道名称可能会影响到已有的数据分析和报表。请谨慎操作。</p>
    </div>
</div>
@endif

<div class="card mt-4">
    <div class="card-header">
        <h5>使用说明</h5>
    </div>
    <div class="card-body">
        <ul>
            <li>渠道是导入交易数据时的重要分类依据</li>
            <li>渠道名称在系统中必须唯一，请勿与其他渠道重名</li>
            <li>若此渠道已有交易数据，建议只修改描述信息，不要修改渠道名称</li>
        </ul>
    </div>
</div>
@endsection 