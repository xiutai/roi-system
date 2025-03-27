@extends('layouts.app')

@section('title', '添加消耗')

@section('page-title', '添加消耗')

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
                <form action="{{ route('expenses.store') }}" method="POST">
                    @csrf
                    <div class="mb-3">
                        <label for="date" class="form-label">日期</label>
                        <input type="date" class="form-control @error('date') is-invalid @enderror" id="date" name="date" value="{{ old('date', date('Y-m-d')) }}" required>
                        @error('date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-3">
                        <label for="channel_id" class="form-label">渠道</label>
                        <select class="form-select @error('channel_id') is-invalid @enderror" id="channel_id" name="channel_id" required>
                            <option value="">请选择渠道</option>
                            @foreach($channels as $channel)
                                <option value="{{ $channel->id }}" {{ old('channel_id') == $channel->id ? 'selected' : '' }}>{{ $channel->name }}</option>
                            @endforeach
                        </select>
                        @error('channel_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-3">
                        <label for="amount" class="form-label">消耗金额</label>
                        <input type="number" step="0.01" min="0" class="form-control @error('amount') is-invalid @enderror" id="amount" name="amount" value="{{ old('amount', 0) }}" required>
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
                    <li>设置每日消耗将用于ROI计算</li>
                    <li>若某日期未设置消耗，将使用默认消耗</li>
                    <li>提示：您也可以使用批量设置功能，一次设置多天消耗</li>
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection 