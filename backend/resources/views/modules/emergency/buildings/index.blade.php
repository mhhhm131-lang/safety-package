@extends('layouts.app')

@section('page_title', 'المباني')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0" style="color: var(--text-main);">
            <i class="bi bi-building me-2"></i>المباني
        </h4>
        <div>
            <a href="{{ route('emergency.dashboard') }}" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-right me-1"></i>الرجوع
            </a>
            <a href="{{ route('emergency.buildings.create') }}" class="btn btn-accent">
                <i class="bi bi-plus-lg me-1"></i>إضافة مبنى
            </a>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            @if($buildings->isEmpty())
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-building fs-1 d-block mb-2"></i>
                    <p>لا توجد مباني مسجلة</p>
                    <a href="{{ route('emergency.buildings.create') }}" class="btn btn-accent">
                        <i class="bi bi-plus-lg me-1"></i>إضافة أول مبنى
                    </a>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>المبنى</th>
                                <th>النوع</th>
                                <th>الأدوار</th>
                                <th>نقاط التجمع</th>
                                <th>الفرق</th>
                                <th>المعدات</th>
                                <th>الحالة</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($buildings as $building)
                            <tr>
                                <td>
                                    <a href="{{ route('emergency.buildings.show', $building) }}" class="text-decoration-none fw-bold">
                                        {{ $building->name }}
                                    </a>
                                    @if($building->code)
                                        <br><small class="text-muted">{{ $building->code }}</small>
                                    @endif
                                </td>
                                <td>{{ $building->getTypeLabel() }}</td>
                                <td>
                                    <span class="badge bg-secondary">{{ $building->floors_count }}</span>
                                </td>
                                <td>
                                    <span class="badge bg-info">{{ $building->assembly_points_count }}</span>
                                </td>
                                <td>
                                    <span class="badge bg-warning text-dark">{{ $building->teams_count }}</span>
                                </td>
                                <td>
                                    <span class="badge bg-secondary">{{ $building->equipment_count }}</span>
                                </td>
                                <td>
                                    @if($building->isInEmergency())
                                        <span class="badge bg-danger">{{ $building->getEmergencyStatusLabel() }}</span>
                                    @elseif($building->status === 'active')
                                        <span class="badge bg-success">نشط</span>
                                    @elseif($building->status === 'inactive')
                                        <span class="badge bg-secondary">غير نشط</span>
                                    @else
                                        <span class="badge bg-warning text-dark">تحت الصيانة</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="btn-group">
                                        <a href="{{ route('emergency.buildings.show', $building) }}" class="btn btn-sm btn-outline-primary" title="عرض">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="{{ route('emergency.buildings.control', $building) }}" class="btn btn-sm {{ $building->isInEmergency() ? 'btn-danger' : 'btn-outline-secondary' }}" title="لوحة التحكم">
                                            <i class="bi bi-joystick"></i>
                                        </a>
                                        <a href="{{ route('emergency.buildings.edit', $building) }}" class="btn btn-sm btn-outline-secondary" title="تعديل">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="p-3">
                    {{ $buildings->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
