@extends('layouts.app')

@section('title', '编辑汇率')

@section('page-title', '编辑汇率')

@section('page-actions')
<a href="{{ route('exchange_rates.index') }}" class="btn btn-sm btn-secondary">
    <i class="bi bi-arrow-left"></i> 返回列表
</a>
@endsection

@section('content')
<div class="row">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <form action="{{ route('exchange_rates.update', $exchangeRate) }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="mb-3">
                        <label for="date" class="form-label">日期</label>
                        <input type="date" class="form-control" id="date" value="{{ $exchangeRate->date->format('Y-m-d') }}" disabled>
                        <small class="form-text text-muted">日期无法修改，如需更改日期请删除后重新添加。</small>
                    </div>
                    <div class="mb-3">
                        <label for="rate" class="form-label">汇率</label>
                        <input type="number" step="0.01" min="0" class="form-control @error('rate') is-invalid @enderror" id="rate" name="rate" value="{{ old('rate', $exchangeRate->rate) }}" required>
                        @error('rate')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <button type="submit" class="btn btn-primary">保存</button>
                    <a href="{{ route('exchange_rates.index') }}" class="btn btn-secondary">取消</a>
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
                <h5 class="card-title">汇率设置说明</h5>
                <ul>
                    <li>修改汇率将会影响ROI计算结果</li>
                    <li>建议修改后重新计算相关日期的ROI</li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection 