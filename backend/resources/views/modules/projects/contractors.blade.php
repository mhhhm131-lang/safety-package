@extends('layouts.app')

@section('page_title', 'مقاولو المشروع — ' . $project->name)

@section('content')
<div class="container-fluid">

    {{-- Project breadcrumb --}}
    <div class="d-flex align-items-center gap-2 mb-3 small" style="color: var(--text-muted);">
        <a href="{{ route('projects.index') }}" class="text-decoration-none" style="color: var(--text-muted);">المشاريع</a>
        <i class="bi bi-chevron-left"></i>
        <a href="{{ route('projects.show', $project) }}" class="text-decoration-none" style="color: var(--text-muted);">{{ $project->name }}</a>
        <i class="bi bi-chevron-left"></i>
        <span>المقاولون</span>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0" style="color: var(--text-main);">
            <i class="bi bi-people-fill me-1"></i> مقاولو المشروع
            <span class="badge" style="background: var(--accent); color: #fff;">{{ $contractors->count() }}</span>
        </h5>
        @if(auth()->user()->can_('project.edit'))
        <div class="dropdown">
            <button class="btn btn-accent dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-person-plus me-1"></i> إضافة مقاول
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <a class="dropdown-item" href="{{ route('projects.contractors.new', $project) }}">
                        <i class="bi bi-person-plus-fill me-2"></i>
                        <span class="fw-bold">إنشاء مقاول جديد</span>
                        <div class="small text-muted">بيانات الطرف والعقد معاً</div>
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="dropdown-item" href="{{ route('projects.contractors.assign', $project) }}">
                        <i class="bi bi-link-45deg me-2"></i>
                        <span>ربط مقاول موجود</span>
                        <div class="small text-muted">اختيار من قائمة الأطراف الخارجية</div>
                    </a>
                </li>
            </ul>
        </div>
        @endif
    </div>

    {{-- Summary strip --}}
    @php
        $byStatus = $contractors->groupBy('qualification_status');
        $statusLabels = [
            'draft' => 'مسودة',
            'pre_review' => 'مراجعة التأهيل المسبق',
            'pre_approved' => 'مؤهَّل مسبقاً',
            'post_review' => 'مراجعة التأهيل اللاحق',
            'post_approved' => 'مؤهَّل — جاهز للعمل',
            'suspended' => 'موقوف',
            'expired' => 'منتهٍ',
        ];
        $statusColors = [
            'draft' => '#6c757d',
            'pre_review' => '#0d6efd',
            'pre_approved' => '#0dcaf0',
            'post_review' => '#fd7e14',
            'post_approved' => '#198754',
            'suspended' => '#dc3545',
            'expired' => '#343a40',
        ];
    @endphp
    <div class="row g-2 mb-4">
        @foreach($statusLabels as $key => $label)
            <div class="col">
                <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-top: 3px solid {{ $statusColors[$key] }};">
                    <div class="card-body text-center py-2">
                        <h6 class="mb-0 fw-bold" style="color: var(--text-main);">{{ ($byStatus->get($key) ?? collect())->count() }}</h6>
                        <small style="color: var(--text-muted);">{{ $label }}</small>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Contractors list --}}
    <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color);">
        <div class="table-responsive">
            <table class="table table-borderless mb-0" style="color: var(--text-main);">
                <thead style="background: var(--bg-main);">
                    <tr>
                        <th>المقاول</th>
                        <th>الدور</th>
                        <th>حالة التأهيل</th>
                        <th>بداية العقد</th>
                        <th>ينتهي</th>
                        <th>آخر نشاط</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($contractors as $pc)
                        <tr>
                            <td>
                                <strong>{{ $pc->externalParty?->name ?? '—' }}</strong>
                                @if($pc->externalParty?->cr_number)
                                    <br><small style="color: var(--text-muted);">السجل: {{ $pc->externalParty->cr_number }}</small>
                                @endif
                            </td>
                            <td class="small">
                                @switch($pc->role)
                                    @case('main') <span class="badge bg-primary">رئيسي</span> @break
                                    @case('sub') <span class="badge bg-secondary">فرعي</span> @break
                                    @case('consultant') <span class="badge bg-info">استشاري</span> @break
                                    @case('supplier') <span class="badge bg-dark">مورد</span> @break
                                @endswitch
                            </td>
                            <td>
                                <span class="badge" style="background: {{ $statusColors[$pc->qualification_status] ?? 'var(--bg-main)' }}; color: #fff;">
                                    {{ $statusLabels[$pc->qualification_status] ?? $pc->qualification_status }}
                                </span>
                            </td>
                            <td class="small" style="color: var(--text-muted);">{{ $pc->contract_start_date?->toDateString() ?? '—' }}</td>
                            <td class="small" style="color: var(--text-muted);">{{ $pc->expires_at?->toDateString() ?? '—' }}</td>
                            <td class="small" style="color: var(--text-muted);">
                                @if($pc->events->isNotEmpty())
                                    {{ $pc->events->first()->event_type }}
                                    · {{ $pc->events->first()->created_at?->diffForHumans() }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('external-parties.profile', $pc->external_party_id) }}" class="btn btn-sm btn-outline-primary" title="ملف التأهيل"><i class="bi bi-patch-check"></i> التأهيل</a>
                                @php($sm = app(\App\Modules\Project\StateMachines\ProjectContractorStateMachine::class))
                                @php($isContractorUser = auth()->user()->isContractorRole())
                                @foreach(array_keys($sm->getAvailable($pc->qualification_status)) as $ns)
                                    @if($ns === 'expired' || $ns === $pc->qualification_status) @continue @endif
                                    @if($isContractorUser && !in_array($ns, ['pre_review', 'post_review'])) @continue @endif
                                    @if(!$isContractorUser && !$sm->canUserTransition($pc->qualification_status, $ns, auth()->user()->role())) @continue @endif
                                    <form method="POST" action="{{ route('projects.contractors.transition', [$project, $pc]) }}" class="d-inline" data-transition="{{ $ns }}">
                                        @csrf<input type="hidden" name="status" value="{{ $ns }}">
                                        <button class="btn btn-sm {{ in_array($ns, ['pre_approved','post_approved']) ? 'btn-success' : ($ns === 'suspended' ? 'btn-outline-danger' : 'btn-outline-secondary') }}">{{ \App\Modules\Project\Models\ProjectContractor::STATUSES[$ns] ?? $ns }}</button>
                                    </form>
                                @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-5" style="color: var(--text-muted);">
                                <i class="bi bi-people fs-2 d-block mb-2"></i>
                                لا يوجد مقاولون مرتبطون بهذا المشروع بعد.
                                <div class="mt-3 d-flex gap-2 justify-content-center">
                                    <a href="{{ route('projects.contractors.new', $project) }}" class="btn btn-accent btn-sm">
                                        <i class="bi bi-person-plus-fill me-1"></i> إنشاء مقاول جديد
                                    </a>
                                    <a href="{{ route('projects.contractors.assign', $project) }}" class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-link-45deg me-1"></i> ربط مقاول موجود
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
