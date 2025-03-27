@extends('layouts.app')

@section('title', '编辑消耗')

@section('page-title', '编辑消耗')

@section('page-actions')
<a href="{{ route('expenses.index') }}" class="btn btn-sm btn-secondary">
    <i class="bi bi-arrow-left"></i> 返回列表
</a>
@endsection

@section('content')
<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <form action="{{ route('expenses.update', $expense) }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="mb-3">
                        <label for="date" class="form-label">日期</label>
                        <input type="date" class="form-control" id="date" value="{{ $expense->date->format('Y-m-d') }}" disabled>
                        <small class="form-text text-muted">日期无法修改，如需更改日期请删除后重新添加。</small>
                    </div>
                    <div class="mb-3">
                        <label for="channel" class="form-label">渠道</label>
                        <input type="text" class="form-control" id="channel" value="{{ $expense->channel->name }}" disabled>
                        <small class="form-text text-muted">渠道无法修改，如需更改渠道请删除后重新添加。</small>
                    </div>
                    <div class="mb-3">
                        <label for="amount" class="form-label">消耗金额</label>
                        <input type="number" step="0.01" min="0" class="form-control @error('amount') is-invalid @enderror" id="amount" name="amount" value="{{ old('amount', $expense->amount) }}" required>
                        @error('amount')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <button type="submit" class="btn btn-primary">保存</button>
                    <a href="{{ route('expenses.index') }}" class="btn btn-secondary">取消</a>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                说明
            </div>
            <div class="card-body">
                <h5 class="card-title">消耗设置说明</h5>
                <ul>
                    <li>修改消耗金额将会影响ROI计算结果</li>
                    <li>建议修改后重新计算相关日期的ROI</li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection 