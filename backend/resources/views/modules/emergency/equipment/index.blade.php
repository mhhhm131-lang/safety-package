@extends('layouts.app')

@section('page_title', 'معدات الطوارئ')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0" style="color: var(--text-main);">
            <i class="bi bi-tools me-2"></i>معدات الطوارئ
        </h4>
        <div>
            <a href="{{ route('emergency.dashboard') }}" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-right me-1"></i>الرجوع
            </a>
            <a href="{{ route('emergency.equipment.create') }}" class="btn btn-accent">
                <i class="bi bi-plus-lg me-1"></i>إضافة معدة
            </a>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            @if($equipment->isEmpty())
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-tools fs-1 d-block mb-2"></i>
                    <p>لا توجد معدات مسجلة</p>
                    <a href="{{ route('emergency.equipment.create') }}" class="btn btn-accent">
                        <i class="bi bi-plus-lg me-1"></i>إضافة أول معدة
                    </a>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>الرمز</th>
                                <th>النوع</th>
                                <th>المكان / الموقع</th>
                                <th>الفحص القادم</th>
                                <th>الحالة</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($equipment as $item)
                            <tr>
                                <td>
                                    <strong>{{ $item->code ?? '-' }}</strong>
                                </td>
                                <td>
                                    <i class="bi bi-{{ $item->getTypeIcon() }} me-1"></i>
                                    {{ $item->getTypeLabel() }}
                                </td>
                                <td>
                                    {{ $item->place?->name ?? $item->building->name }}
                                    @if($item->floor)
                                        <br><small class="text-muted">{{ $item->floor->getDisplayName() }}</small>
                                    @endif
                                    @if($item->location_description)
                                        <br><small class="text-muted">{{ $item->location_description }}</small>
                                    @endif
                                </td>
                                <td>
                                    @if($item->next_inspection_date)
                                        @if($item->next_inspection_date->isPast())
                                            <span class="text-danger">
                                                <i class="bi bi-exclamation-triangle me-1"></i>
                                                {{ $item->next_inspection_date->format('Y-m-d') }}
                                            </span>
                                            <br><small class="text-danger">متأخر</small>
                                        @elseif($item->next_inspection_date->diffInDays(now()) <= 7)
                                            <span class="text-warning">
                                                {{ $item->next_inspection_date->format('Y-m-d') }}
                                            </span>
                                            <br><small class="text-warning">قريب</small>
                                        @else
                                            {{ $item->next_inspection_date->format('Y-m-d') }}
                                        @endif
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge bg-{{ ['operational' => 'success', 'needs_service' => 'warning text-dark', 'out_of_service' => 'danger', 'expired' => 'danger', 'missing' => 'dark'][$item->status] ?? 'secondary' }}">{{ $item->getStatusLabel() }}</span>
                                </td>
                                <td class="text-nowrap">
                                    @if(\App\Core\Permissions\PermissionRegistry::hasPermission(auth()->user()->role(), 'emergency.equipment'))
                                    <form method="post" action="{{ route('emergency.equipment.inspect', $item) }}" class="d-inline">@csrf
                                        <select name="result" class="form-select form-select-sm d-inline w-auto py-0"><option value="pass">سليم</option><option value="needs_attention">يحتاج انتباهاً</option><option value="fail">غير صالح</option></select>
                                        <button class="btn btn-sm btn-outline-primary py-0" title="تسجيل فحص الآن"><i class="bi bi-clipboard-check"></i></button>
                                    </form>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="p-3">
                    {{ $equipment->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
