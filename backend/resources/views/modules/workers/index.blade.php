@extends('layouts.app')

@section('page_title', 'العمال')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="fw-bold mb-0" style="color: var(--text-main);">قائمة العمال</h5>
        <a href="{{ route('workers.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> إضافة عامل
        </a>
    </div>

    {{-- Filter --}}
    <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
        <div class="card-body">
            <form method="GET" action="{{ route('workers.index') }}" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label" style="color: var(--text-muted);">بحث</label>
                    <input type="text" name="search" class="form-control" value="{{ request('search') }}" placeholder="الاسم أو رقم الهوية..."
                           style="background: var(--bg-main); color: var(--text-main); border-color: var(--border-color);">
                </div>
                <div class="col-md-3">
                    <label class="form-label" style="color: var(--text-muted);">الحالة</label>
                    <select name="status" class="form-select" style="background: var(--bg-main); color: var(--text-main); border-color: var(--border-color);">
                        <option value="">الكل</option>
                        <option value="draft"           @selected(request('status') == 'draft')>مسودة</option>
                        <option value="submitted"       @selected(request('status') == 'submitted')>مُقدَّم</option>
                        <option value="induction"       @selected(request('status') == 'induction')>تعريف</option>
                        <option value="training"        @selected(request('status') == 'training')>تدريب</option>
                        <option value="approved"        @selected(request('status') == 'approved')>معتمد</option>
                        <option value="work_authorized" @selected(request('status') == 'work_authorized')>مصرح بالعمل</option>
                        <option value="role_authorized" @selected(request('status') == 'role_authorized')>مصرح بالمهمة</option>
                        <option value="blocked"         @selected(request('status') == 'blocked')>محظور</option>
                        <option value="suspended"       @selected(request('status') == 'suspended')>موقوف</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" style="color: var(--text-muted);">الطرف الخارجي</label>
                    <select name="external_party_id" class="form-select" style="background: var(--bg-main); color: var(--text-main); border-color: var(--border-color);">
                        <option value="">الكل</option>
                        @foreach($externalParties ?? [] as $party)
                            <option value="{{ $party->id }}" @selected(request('external_party_id') == $party->id)>{{ $party->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-search me-1"></i> بحث</button>
                    <a href="{{ route('workers.index') }}" class="btn btn-outline-secondary">مسح</a>
                    @if(auth()->user()->can_('worker.approve'))<a href="{{ route('workers.approval-queue') }}" class="btn btn-outline-warning">طابور الاعتماد</a>@endif
                </div>
            </form>
        </div>
    </div>

    <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color);">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-dark table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="color: var(--text-muted);">الاسم</th>
                            <th style="color: var(--text-muted);">رقم الهوية</th>
                            <th style="color: var(--text-muted);">المهنة</th>
                            <th style="color: var(--text-muted);">الحالة</th>
                            <th style="color: var(--text-muted);">الطرف الخارجي</th>
                            <th style="color: var(--text-muted);">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($workers as $worker)
                            @php
                                $wStatusColors = [
                                    'draft' => 'secondary', 'submitted' => 'info', 'induction' => 'info',
                                    'training' => 'warning', 'approved' => 'success', 'work_authorized' => 'success',
                                    'role_authorized' => 'success', 'blocked' => 'danger', 'suspended' => 'warning',
                                ];
                                $wStatusLabels = [
                                    'draft' => 'مسودة', 'submitted' => 'مُقدَّم', 'induction' => 'تعريف',
                                    'training' => 'تدريب', 'approved' => 'معتمد', 'work_authorized' => 'مصرح بالعمل',
                                    'role_authorized' => 'مصرح بالمهمة', 'blocked' => 'محظور', 'suspended' => 'موقوف',
                                ];
                            @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('workers.show', $worker) }}" style="color: var(--accent-color); text-decoration: none;">{{ $worker->full_name }}</a>
                                </td>
                                <td style="color: var(--text-main);" dir="ltr">{{ $worker->national_id }}</td>
                                <td style="color: var(--text-main);">{{ $worker->trade->name ?? '-' }}</td>
                                <td>
                                    <span class="badge bg-{{ $wStatusColors[$worker->status] ?? 'secondary' }}">
                                        {{ $wStatusLabels[$worker->status] ?? $worker->status }}
                                    </span>
                                </td>
                                <td style="color: var(--text-main);">{{ $worker->externalParty->name ?? '-' }}</td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="{{ route('workers.show', $worker) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                                        <a href="{{ route('workers.edit', $worker) }}" class="btn btn-sm btn-outline-warning"><i class="bi bi-pencil"></i></a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center py-4" style="color: var(--text-muted);">لا يوجد عمال</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if($workers->hasPages())
        <div class="d-flex justify-content-center mt-4">
            {{ $workers->withQueryString()->links() }}
        </div>
    @endif
</div>
@endsection
