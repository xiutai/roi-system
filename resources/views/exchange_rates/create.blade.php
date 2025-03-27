@extends('layouts.app')

@section('title', '添加汇率')

@section('page-title', '添加汇率')

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
                <form action="{{ route('exchange_rates.store') }}" method="POST">
                    @csrf
                    <div class="mb-3">
                        <label for="date" class="form-label">日期</label>
                        <input type="date" class="form-control @error('date') is-invalid @enderror" id="date" name="date" value="{{ old('date', date('Y-m-d')) }}" required>
                        @error('date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-3">
                        <label for="rate" class="form-label">汇率</label>
                        <input type="number" step="0.01" min="0" class="form-control @error('rate') is-invalid @enderror" id="rate" name="rate" value="{{ old('rate', $defaultRate ? $defaultRate->rate : '') }}" required>
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
                    <li>设置每日汇率将用于ROI计算</li>
                    <li>若某日期未设置汇率，将使用默认汇率</li>
                    <li>默认汇率: {{ $defaultRate ? $defaultRate->rate : '未设置' }}</li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection 