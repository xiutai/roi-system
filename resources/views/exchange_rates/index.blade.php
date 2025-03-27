@extends('layouts.app')

@section('title', '汇率管理')

@section('page-title', '汇率管理')

@section('page-actions')
<a href="{{ route('exchange_rates.create') }}" class="btn btn-sm btn-primary">
    <i class="bi bi-plus"></i> 添加汇率
</a>
<button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#batchModal">
    <i class="bi bi-list-check"></i> 批量设置
</button>
<button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#defaultModal">
    <i class="bi bi-gear"></i> 设置默认汇率
</button>
<button type="button" id="batchDeleteBtn" class="btn btn-sm btn-danger d-none">
    <i class="bi bi-trash"></i> 批量删除
</button>
@endsection

@section('content')
<!-- 筛选表单 -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="{{ route('exchange_rates.index') }}" class="row g-3">
            <div class="col-md-4">
                <label for="daterange" class="form-label">日期范围</label>
                <input type="text" class="form-control" id="daterange" name="daterange" value="{{ $startDate }} - {{ $endDate }}">
                <input type="hidden" name="start_date" id="start_date" value="{{ $startDate }}">
                <input type="hidden" name="end_date" id="end_date" value="{{ $endDate }}">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary">筛选</button>
                <a href="{{ route('exchange_rates.index') }}" class="btn btn-secondary ms-2">重置</a>
            </div>
        </form>
    </div>
</div>

<!-- 默认汇率信息 -->
<div class="alert alert-info">
    <strong>默认汇率：</strong> {{ $defaultRate ? $defaultRate->rate : '未设置' }}
    <button type="button" class="btn btn-sm btn-outline-info float-end" data-bs-toggle="modal" data-bs-target="#defaultModal">
        修改默认汇率
    </button>
</div>

<!-- 汇率列表 -->
<div class="card">
    <div class="card-body">
        <form id="batchDeleteForm" action="{{ route('exchange_rates.batch_destroy') }}" method="POST">
            @csrf
            @method('DELETE')
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th width="40">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="selectAll">
                                </div>
                            </th>
                            <th>日期</th>
                            <th>汇率</th>
                            <th>创建时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if($exchangeRates->count() > 0)
                            @foreach($exchangeRates as $rate)
                                <tr>
                                    <td>
                                        @if(!$rate->is_default)
                                        <div class="form-check">
                                            <input class="form-check-input select-item" type="checkbox" name="ids[]" value="{{ $rate->id }}">
                                        </div>
                                        @endif
                                    </td>
                                    <td>{{ $rate->date->format('Y-m-d') }}</td>
                                    <td>{{ $rate->rate }}</td>
                                    <td>{{ $rate->created_at->format('Y-m-d H:i') }}</td>
                                    <td>
                                        <a href="{{ route('exchange_rates.edit', $rate) }}" class="btn btn-sm btn-primary">编辑</a>
                                        @if(!$rate->is_default)
                                        <a href="{{ route('test.exchange_rate.delete', $rate->id) }}" class="btn btn-sm btn-danger" onclick="return confirm('确定要删除这条汇率记录吗？')">删除</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @else
                            <tr>
                                <td colspan="5" class="text-center">暂无数据</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </form>
    </div>
</div>

<!-- 批量设置汇率弹窗 -->
<div class="modal fade" id="batchModal" tabindex="-1" aria-labelledby="batchModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="{{ route('exchange_rates.batch') }}" method="POST">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="batchModalLabel">批量设置汇率</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="batch_daterange" class="form-label">日期范围</label>
                        <input type="text" class="form-control" id="batch_daterange" name="batch_daterange" value="{{ $startDate }} - {{ $endDate }}">
                        <input type="hidden" name="start_date" id="batch_start_date" value="{{ $startDate }}">
                        <input type="hidden" name="end_date" id="batch_end_date" value="{{ $endDate }}">
                    </div>
                    <div class="mb-3">
                        <label for="rate" class="form-label">汇率</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="rate" name="rate" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">确定批量设置</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 设置默认汇率弹窗 -->
<div class="modal fade" id="defaultModal" tabindex="-1" aria-labelledby="defaultModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="{{ route('exchange_rates.update_default') }}" method="POST">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="defaultModalLabel">设置默认汇率</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="default_rate" class="form-label">默认汇率</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="default_rate" name="default_rate" value="{{ $defaultRate ? $defaultRate->rate : '' }}" required>
                        <small class="form-text text-muted">当某日期没有设置特定汇率时，将使用此默认值。</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">保存默认汇率</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 批量删除确认弹窗 -->
<div class="modal fade" id="batchDeleteModal" tabindex="-1" aria-labelledby="batchDeleteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="batchDeleteModalLabel">确认批量删除</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>确定要删除所选的汇率记录吗？此操作不可恢复。</p>
                <p class="text-danger" id="deleteCount"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="button" class="btn btn-danger" id="confirmBatchDelete">确定删除</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    $(function() {
        // 设置日期范围选择器
        $('#daterange').daterangepicker({
            locale: {
                format: 'YYYY-MM-DD',
                applyLabel: '确定',
                cancelLabel: '取消',
                fromLabel: '从',
                toLabel: '到',
                customRangeLabel: '自定义',
                weekLabel: '周',
                daysOfWeek: ['日', '一', '二', '三', '四', '五', '六'],
                monthNames: ['一月', '二月', '三月', '四月', '五月', '六月', '七月', '八月', '九月', '十月', '十一月', '十二月'],
                firstDay: 1
            },
            startDate: '{{ $startDate }}',
            endDate: '{{ $endDate }}'
        }, function(start, end, label) {
            $('#start_date').val(start.format('YYYY-MM-DD'));
            $('#end_date').val(end.format('YYYY-MM-DD'));
        });
        
        // 批量设置的日期选择器
        $('#batch_daterange').daterangepicker({
            locale: {
                format: 'YYYY-MM-DD',
                applyLabel: '确定',
                cancelLabel: '取消',
                fromLabel: '从',
                toLabel: '到',
                customRangeLabel: '自定义',
                weekLabel: '周',
                daysOfWeek: ['日', '一', '二', '三', '四', '五', '六'],
                monthNames: ['一月', '二月', '三月', '四月', '五月', '六月', '七月', '八月', '九月', '十月', '十一月', '十二月'],
                firstDay: 1
            },
            startDate: '{{ $startDate }}',
            endDate: '{{ $endDate }}'
        }, function(start, end, label) {
            $('#batch_start_date').val(start.format('YYYY-MM-DD'));
            $('#batch_end_date').val(end.format('YYYY-MM-DD'));
        });

        // 全选/全不选
        $("#selectAll").change(function() {
            $(".select-item").prop('checked', $(this).prop('checked'));
            updateBatchDeleteButton();
        });

        // 单个选择框变化时更新全选框状态
        $(document).on('change', '.select-item', function() {
            updateBatchDeleteButton();
            
            // 如果所有复选框都选中，则全选框也选中
            $("#selectAll").prop('checked', $(".select-item:checked").length === $(".select-item").length);
        });

        // 更新批量删除按钮显示状态
        function updateBatchDeleteButton() {
            const selectedCount = $(".select-item:checked").length;
            if (selectedCount > 0) {
                $("#batchDeleteBtn").removeClass('d-none').text(`批量删除 (${selectedCount})`);
            } else {
                $("#batchDeleteBtn").addClass('d-none');
            }
        }

        // 显示批量删除确认框
        $("#batchDeleteBtn").click(function() {
            const selectedCount = $(".select-item:checked").length;
            $("#deleteCount").text(`将删除 ${selectedCount} 条记录`);
            $("#batchDeleteModal").modal('show');
        });

        // 确认批量删除
        $("#confirmBatchDelete").click(function() {
            $("#batchDeleteForm").submit();
        });
    });
</script>
@endsection 