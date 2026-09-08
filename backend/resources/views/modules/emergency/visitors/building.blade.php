@extends('layouts.app')

@section('title', 'زوار ' . $building->name)

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="{{ route('emergency.visitors.dashboard') }}">إدارة الزوار</a></li>
                    <li class="breadcrumb-item active">{{ $building->name }}</li>
                </ol>
            </nav>
            <h1 class="h3 mb-0">
                <i class="bi bi-building me-2"></i>{{ $building->name }}
            </h1>
        </div>
        <div class="btn-group">
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#checkInModal">
                <i class="bi bi-person-plus me-1"></i>تسجيل زائر
            </button>
            <a href="{{ route('emergency.visitors.kiosk') }}" class="btn btn-outline-primary" target="_blank">
                <i class="bi bi-display me-1"></i>كشك
            </a>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0">داخل المبنى الآن</h6>
                            <h2 class="mb-0">{{ $currentVisitors->count() }}</h2>
                        </div>
                        <i class="bi bi-people fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0">زوار اليوم</h6>
                            <h2 class="mb-0">{{ $todayVisitors->count() }}</h2>
                        </div>
                        <i class="bi bi-calendar-check fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-warning text-dark">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-0">يحتاجون مساعدة</h6>
                            <h2 class="mb-0">{{ $currentVisitors->where('needs_assistance', true)->count() }}</h2>
                        </div>
                        <i class="bi bi-person-wheelchair fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Current Visitors -->
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-people-fill me-2"></i>الزوار الحاليين
                        <span class="badge bg-white text-primary ms-2">{{ $currentVisitors->count() }}</span>
                    </h5>
                </div>
                <div class="card-body p-0">
                    @if($currentVisitors->isEmpty())
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-person-x display-4 d-block mb-3"></i>
                            لا يوجد زوار حالياً في المبنى
                        </div>
                    @else
                        <div class="list-group list-group-flush">
                            @foreach($currentVisitors as $visitor)
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="d-flex">
                                            <div class="avatar-sm bg-light rounded-circle me-3 d-flex align-items-center justify-content-center flex-shrink-0">
                                                @if($visitor->needs_assistance)
                                                    <i class="bi bi-person-wheelchair text-warning"></i>
                                                @else
                                                    <i class="bi bi-person text-secondary"></i>
                                                @endif
                                            </div>
                                            <div>
                                                <h6 class="mb-1">{{ $visitor->name }}</h6>
                                                <small class="text-muted d-block">
                                                    @if($visitor->company) {{ $visitor->company }} • @endif
                                                    {{ $visitor->phone ?? 'لا يوجد هاتف' }}
                                                </small>
                                                @if($visitor->purpose)
                                                    <small class="text-muted">
                                                        <i class="bi bi-chat-text me-1"></i>{{ $visitor->purpose }}
                                                    </small>
                                                @endif
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <small class="text-muted d-block">
                                                <i class="bi bi-clock me-1"></i>{{ $visitor->checked_in_at->format('H:i') }}
                                            </small>
                                            <small class="text-muted">{{ $visitor->getDurationFormatted() }}</small>
                                            <div class="mt-2">
                                                <button class="btn btn-sm btn-outline-danger" onclick="checkOutVisitor({{ $visitor->id }})">
                                                    <i class="bi bi-box-arrow-right"></i> خروج
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    @if($visitor->needs_assistance)
                                        <div class="mt-2">
                                            <span class="badge bg-warning text-dark">
                                                <i class="bi bi-exclamation-triangle me-1"></i>
                                                {{ $visitor->getAssistanceTypeLabel() }}
                                            </span>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Today's Visitors -->
        <div class="col-lg-6 mb-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-calendar-check me-2"></i>سجل زوار اليوم
                    </h5>
                </div>
                <div class="card-body p-0" style="max-height: 500px; overflow-y: auto;">
                    @if($todayVisitors->isEmpty())
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-calendar-x display-4 d-block mb-3"></i>
                            لا يوجد زوار اليوم
                        </div>
                    @else
                        <table class="table table-hover mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>الزائر</th>
                                    <th>الدخول</th>
                                    <th>الخروج</th>
                                    <th>المدة</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($todayVisitors as $visitor)
                                    <tr>
                                        <td>
                                            <strong>{{ $visitor->name }}</strong>
                                            @if($visitor->company)
                                                <small class="text-muted d-block">{{ $visitor->company }}</small>
                                            @endif
                                        </td>
                                        <td>{{ $visitor->checked_in_at->format('H:i') }}</td>
                                        <td>
                                            @if($visitor->checked_out_at)
                                                {{ $visitor->checked_out_at->format('H:i') }}
                                            @else
                                                <span class="badge bg-success">داخل</span>
                                            @endif
                                        </td>
                                        <td>{{ $visitor->getDurationFormatted() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Check-in Modal -->
<div class="modal fade" id="checkInModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="checkInForm">
                <div class="modal-header">
                    <h5 class="modal-title">تسجيل زائر جديد</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="building_id" value="{{ $building->id }}">

                    <div class="mb-3">
                        <label class="form-label">الاسم <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الهاتف</label>
                            <input type="tel" name="phone" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الشركة</label>
                            <input type="text" name="company" class="form-control">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">سبب الزيارة</label>
                        <input type="text" name="purpose" class="form-control">
                    </div>

                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="modalNeedsAssistance" name="needs_assistance">
                        <label class="form-check-label" for="modalNeedsAssistance">
                            يحتاج مساعدة خاصة
                        </label>
                    </div>

                    <div id="modalAssistanceType" class="mb-3" style="display: none;">
                        <label class="form-label">نوع المساعدة</label>
                        <select name="assistance_type" class="form-select">
                            <option value="">اختر</option>
                            <option value="wheelchair">كرسي متحرك</option>
                            <option value="visual">ضعف بصري</option>
                            <option value="hearing">ضعف سمعي</option>
                            <option value="mobility">صعوبة حركة</option>
                            <option value="medical">حالة طبية</option>
                            <option value="other">أخرى</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle me-1"></i>تسجيل الدخول
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.getElementById('modalNeedsAssistance').addEventListener('change', function() {
    document.getElementById('modalAssistanceType').style.display = this.checked ? 'block' : 'none';
});

document.getElementById('checkInForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const data = Object.fromEntries(formData.entries());
    data.needs_assistance = document.getElementById('modalNeedsAssistance').checked;

    try {
        const response = await fetch('/api/emergency/visitors/check-in', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.success) {
            bootstrap.Modal.getInstance(document.getElementById('checkInModal')).hide();
            location.reload();
        } else {
            alert(result.message || 'حدث خطأ');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('حدث خطأ في الاتصال');
    }
});

async function checkOutVisitor(visitorId) {
    if (!confirm('هل تريد تسجيل خروج هذا الزائر؟')) return;

    try {
        const response = await fetch(`/api/emergency/visitors/${visitorId}/check-out`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        });

        const data = await response.json();

        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'حدث خطأ');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('حدث خطأ في الاتصال');
    }
}
</script>
@endpush
@endsection
