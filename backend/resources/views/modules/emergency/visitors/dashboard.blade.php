@extends('layouts.app')

@section('title', 'إدارة الزوار')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="bi bi-person-badge me-2"></i>إدارة الزوار
        </h1>
        <div>
            <a href="{{ route('emergency.visitors.kiosk') }}" class="btn btn-primary" target="_blank">
                <i class="bi bi-display me-1"></i>كشك التسجيل
            </a>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-0">داخل المباني الآن</h6>
                            <h2 class="mb-0">{{ $stats['currently_in'] ?? 0 }}</h2>
                        </div>
                        <i class="bi bi-people-fill fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-0">زوار اليوم</h6>
                            <h2 class="mb-0">{{ $stats['total'] ?? 0 }}</h2>
                        </div>
                        <i class="bi bi-calendar-check fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-secondary text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-0">غادروا</h6>
                            <h2 class="mb-0">{{ $stats['checked_out'] ?? 0 }}</h2>
                        </div>
                        <i class="bi bi-box-arrow-right fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-dark h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-0">يحتاجون مساعدة</h6>
                            <h2 class="mb-0">{{ $stats['needs_assistance'] ?? 0 }}</h2>
                        </div>
                        <i class="bi bi-person-wheelchair fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Buildings Overview -->
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-building me-2"></i>الزوار حسب المبنى</h5>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        @forelse($buildings as $building)
                            <a href="{{ route('emergency.visitors.building', $building) }}"
                               class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>{{ $building->name }}</strong>
                                    @if($building->code)
                                        <small class="text-muted d-block">{{ $building->code }}</small>
                                    @endif
                                </div>
                                <span class="badge bg-primary rounded-pill">
                                    {{ $building->visitors->count() }}
                                </span>
                            </a>
                        @empty
                            <div class="list-group-item text-muted text-center">
                                لا توجد مباني مسجلة
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Visitors -->
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>آخر الزوار</h5>
                    <div class="input-group" style="max-width: 250px;">
                        <input type="text" class="form-control form-control-sm" id="visitorSearch"
                               placeholder="بحث عن زائر...">
                        <button class="btn btn-outline-secondary btn-sm" type="button">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>الزائر</th>
                                    <th>الشركة</th>
                                    <th>المبنى</th>
                                    <th>الدخول</th>
                                    <th>الحالة</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($recentVisitors as $visitor)
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm bg-light rounded-circle me-2 d-flex align-items-center justify-content-center">
                                                    <i class="bi bi-person text-secondary"></i>
                                                </div>
                                                <div>
                                                    <strong>{{ $visitor->name }}</strong>
                                                    @if($visitor->phone)
                                                        <small class="text-muted d-block">{{ $visitor->phone }}</small>
                                                    @endif
                                                </div>
                                            </div>
                                        </td>
                                        <td>{{ $visitor->company ?? '-' }}</td>
                                        <td>
                                            <span class="badge bg-light text-dark">
                                                {{ $visitor->building->name ?? '-' }}
                                            </span>
                                        </td>
                                        <td>
                                            <small>{{ $visitor->checked_in_at->format('H:i') }}</small>
                                            <small class="text-muted d-block">{{ $visitor->checked_in_at->format('Y/m/d') }}</small>
                                        </td>
                                        <td>
                                            <span class="badge bg-{{ $visitor->getStatusColor() }}">
                                                {{ $visitor->getStatusLabel() }}
                                            </span>
                                            @if($visitor->needs_assistance)
                                                <span class="badge bg-warning ms-1" title="{{ $visitor->getAssistanceTypeLabel() }}">
                                                    <i class="bi bi-exclamation-triangle"></i>
                                                </span>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-secondary" title="عرض QR"
                                                        onclick="showVisitorQr({{ $visitor->id }})">
                                                    <i class="bi bi-qr-code"></i>
                                                </button>
                                                @if($visitor->isCurrentlyIn())
                                                    <button class="btn btn-outline-danger" title="تسجيل خروج"
                                                            onclick="checkOutVisitor({{ $visitor->id }})">
                                                        <i class="bi bi-box-arrow-right"></i>
                                                    </button>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">
                                            لا يوجد زوار مسجلين اليوم
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- QR Modal -->
<div class="modal fade" id="qrModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">رمز QR للزائر</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <div id="qrCode"></div>
                <p class="mt-3 mb-0" id="qrBadgeNumber"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إغلاق</button>
                <button type="button" class="btn btn-primary" onclick="printQr()">
                    <i class="bi bi-printer me-1"></i>طباعة
                </button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
async function showVisitorQr(visitorId) {
    try {
        const response = await fetch(`/api/emergency/visitors/${visitorId}/qr-code`, {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        });
        const data = await response.json();

        if (data.success) {
            document.getElementById('qrCode').innerHTML = '';
            new QRCode(document.getElementById('qrCode'), {text: data.data.qr_content || data.data.qr_token, width: 220, height: 220});
            document.getElementById('qrBadgeNumber').textContent = `رقم البطاقة: ${data.data.qr_token.substring(0, 8)}...`;
            new bootstrap.Modal(document.getElementById('qrModal')).show();
        }
    } catch (error) {
        console.error('Error fetching QR:', error);
    }
}

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

function printQr() {
    const printWindow = window.open('', '_blank');
    const qrImage = document.querySelector('#qrCode img').src;
    const badgeNumber = document.getElementById('qrBadgeNumber').textContent;

    printWindow.document.write(`
        <html dir="rtl">
        <head><title>طباعة QR</title></head>
        <body style="text-align: center; padding: 20px;">
            <img src="${qrImage}" style="max-width: 200px;">
            <p style="margin-top: 10px; font-family: Arial;">${badgeNumber}</p>
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
}

// Search functionality
document.getElementById('visitorSearch')?.addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    document.querySelectorAll('tbody tr').forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
});
</script>
@endpush
@endsection
