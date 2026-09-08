@extends('layouts.app')

@section('page_title', 'اعتماد المخاطر')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="fw-bold mb-0" style="color: var(--text-main);">قائمة المخاطر بانتظار الاعتماد</h5>
        <span class="badge bg-warning fs-6">{{ $risks->total() ?? 0 }} خطر معلق</span>
    </div>

    <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color);">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="color: var(--text-muted);">الكود</th>
                            <th style="color: var(--text-muted);">العنوان</th>
                            <th style="color: var(--text-muted);">الفئة</th>
                            <th style="color: var(--text-muted);">الدرجة</th>
                            <th style="color: var(--text-muted);">مقدم من</th>
                            <th style="color: var(--text-muted);">التاريخ</th>
                            <th style="color: var(--text-muted);">إجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($risks as $risk)
                            @php
                                $score = $risk->severity * $risk->likelihood;
                                if ($score >= 15) $scoreColor = 'danger';
                                elseif ($score >= 9) $scoreColor = 'warning';
                                elseif ($score >= 4) $scoreColor = 'info';
                                else $scoreColor = 'success';
                            @endphp
                            <tr>
                                <td style="color: var(--text-main);">{{ $risk->code }}</td>
                                <td>
                                    <a href="{{ route('risk.show', $risk) }}" style="color: var(--accent); text-decoration: none;">{{ $risk->title }}</a>
                                </td>
                                <td style="color: var(--text-main);">{{ $risk->category->name ?? '-' }}</td>
                                <td><span class="badge bg-{{ $scoreColor }}">{{ $score }}</span></td>
                                <td style="color: var(--text-main);">{{ $risk->createdBy->name ?? '-' }}</td>
                                <td style="color: var(--text-muted);">{{ $risk->created_at->format('Y-m-d') }}</td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#approveModal{{ $risk->id }}">
                                            <i class="bi bi-check-lg"></i> اعتماد
                                        </button>
                                        <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal{{ $risk->id }}">
                                            <i class="bi bi-x-lg"></i> رفض
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            {{-- Approve Modal --}}
                            <div class="modal fade" id="approveModal{{ $risk->id }}" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                                        <form method="POST" action="{{ route('risk.approve', $risk) }}">
                                            @csrf
                                            <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                                                <h5 class="modal-title" style="color: var(--text-main);">اعتماد الخطر</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <p style="color: var(--text-main);">هل تريد اعتماد الخطر: <strong>{{ $risk->title }}</strong>؟</p>
                                                <textarea name="note" class="form-control" rows="3" placeholder="ملاحظة (اختياري)..."
                                                          style="background: var(--bg-main); color: var(--text-main); border-color: var(--border-color);"></textarea>
                                            </div>
                                            <div class="modal-footer" style="border-top: 1px solid var(--border-color);">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                                <button type="submit" class="btn btn-success">اعتماد</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            {{-- Reject Modal --}}
                            <div class="modal fade" id="rejectModal{{ $risk->id }}" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                                        <form method="POST" action="{{ route('risk.reject', $risk) }}">
                                            @csrf
                                            <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                                                <h5 class="modal-title" style="color: var(--text-main);">رفض الخطر</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <p style="color: var(--text-main);">هل تريد رفض الخطر: <strong>{{ $risk->title }}</strong>؟</p>
                                                <textarea name="note" class="form-control" rows="3" placeholder="سبب الرفض..." required
                                                          style="background: var(--bg-main); color: var(--text-main); border-color: var(--border-color);"></textarea>
                                            </div>
                                            <div class="modal-footer" style="border-top: 1px solid var(--border-color);">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                                <button type="submit" class="btn btn-danger">رفض</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-4" style="color: var(--text-muted);">لا توجد مخاطر بانتظار الاعتماد</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if($risks->hasPages())
        <div class="d-flex justify-content-center mt-4">
            {{ $risks->links() }}
        </div>
    @endif
</div>
@endsection
