@extends('layouts.app')

@section('title', 'الملفات الطبية للطوارئ')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="bi bi-heart-pulse me-2"></i>الملفات الطبية للطوارئ
        </h1>
        <a href="{{ route('emergency.medical.my-profile') }}" class="btn btn-primary">
            <i class="bi bi-person-badge me-1"></i>ملفي الطبي
        </a>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-0">إجمالي الملفات</h6>
                            <h2 class="mb-0">{{ $stats['total_profiles'] ?? 0 }}</h2>
                        </div>
                        <i class="bi bi-file-medical fs-1 opacity-50"></i>
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
        <div class="col-md-3">
            <div class="card bg-danger text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-0">لديهم حساسية</h6>
                            <h2 class="mb-0">{{ $stats['with_allergies'] ?? 0 }}</h2>
                        </div>
                        <i class="bi bi-exclamation-triangle fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-0">تم التحقق</h6>
                            <h2 class="mb-0">{{ $stats['verified'] ?? 0 }}</h2>
                        </div>
                        <i class="bi bi-check-circle fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Users Needing Assistance -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0">
                        <i class="bi bi-person-wheelchair me-2"></i>يحتاجون مساعدة في الإخلاء
                        <span class="badge bg-dark ms-2">{{ $needsAssistance->count() }}</span>
                    </h5>
                </div>
                <div class="card-body p-0">
                    @if($needsAssistance->isEmpty())
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-check-circle display-4 d-block mb-3 text-success"></i>
                            لا يوجد موظفين يحتاجون مساعدة خاصة
                        </div>
                    @else
                        <div class="list-group list-group-flush">
                            @foreach($needsAssistance as $person)
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="mb-1">{{ $person['user_name'] }}</h6>
                                            <span class="badge bg-{{ $person['mobility_level'] === 'wheelchair' ? 'danger' : 'warning' }} me-1">
                                                {{ $person['mobility_label'] }}
                                            </span>
                                            @if($person['uses_wheelchair'])
                                                <span class="badge bg-secondary">كرسي متحرك</span>
                                            @endif
                                        </div>
                                        <div class="text-end">
                                            @if($person['emergency_contact'])
                                                <small class="text-muted d-block">
                                                    <i class="bi bi-telephone me-1"></i>
                                                    {{ $person['emergency_contact']['phone'] }}
                                                </small>
                                            @endif
                                        </div>
                                    </div>
                                    @if($person['assistance_requirements'])
                                        <small class="text-muted d-block mt-2">
                                            <i class="bi bi-info-circle me-1"></i>
                                            {{ $person['assistance_requirements'] }}
                                        </small>
                                    @endif
                                    @if($person['evacuation_instructions'])
                                        <div class="alert alert-warning py-1 px-2 mt-2 mb-0 small">
                                            <i class="bi bi-arrow-right-circle me-1"></i>
                                            {{ $person['evacuation_instructions'] }}
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <!-- Profiles Needing Review -->
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-clock-history me-2"></i>تحتاج مراجعة
                        <span class="badge bg-secondary ms-2">{{ $needsReview->count() }}</span>
                    </h5>
                </div>
                <div class="card-body p-0">
                    @if($needsReview->isEmpty())
                        <div class="text-center text-muted py-5">
                            <i class="bi bi-check-all display-4 d-block mb-3 text-success"></i>
                            جميع الملفات محدّثة
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>الموظف</th>
                                        <th>آخر مراجعة</th>
                                        <th>الحالة</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($needsReview as $profile)
                                        <tr>
                                            <td>{{ $profile->user?->name ?? '-' }}</td>
                                            <td>
                                                @if($profile->last_reviewed_at)
                                                    {{ $profile->last_reviewed_at->diffForHumans() }}
                                                @else
                                                    <span class="text-danger">لم تتم المراجعة</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($profile->is_verified)
                                                    <span class="badge bg-success">محقق</span>
                                                @else
                                                    <span class="badge bg-warning">غير محقق</span>
                                                @endif
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary"
                                                        onclick="reviewProfile({{ $profile->id }})">
                                                    <i class="bi bi-check"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Blood Type Distribution -->
    @if(!empty($stats['blood_types']) && $stats['blood_types']->count() > 0)
    <div class="card mt-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-droplet me-2"></i>توزيع فصائل الدم</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                @foreach(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $bloodType)
                    <div class="col-md-3 col-6">
                        <div class="border rounded p-3 text-center">
                            <h4 class="mb-1 text-danger">{{ $bloodType }}</h4>
                            <h3 class="mb-0">{{ $stats['blood_types'][$bloodType] ?? 0 }}</h3>
                            <small class="text-muted">موظف</small>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    @endif
</div>

@push('scripts')
<script>
async function reviewProfile(profileId) {
    try {
        const response = await fetch(`/api/emergency/medical/${profileId}/reviewed`, {
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
