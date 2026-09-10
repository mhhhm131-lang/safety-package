@extends('layouts.app')

@section('page_title', 'تفاصيل الخطر')

@section('content')
<div class="container-fluid">
    <div class="row g-4">
        {{-- Main Info --}}
        <div class="col-md-8">
            <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div class="card-header d-flex justify-content-between align-items-center" style="background: transparent; border-bottom: 1px solid var(--border-color);">
                    <h5 class="mb-0 fw-bold" style="color: var(--text-main);">{{ $risk->title }}</h5>
                    <span class="badge bg-{{ $risk->status === 'approved' ? 'primary' : ($risk->status === 'mitigated' ? 'success' : 'warning') }} fs-6">
                        {{ $risk->status_label ?? $risk->status }}
                    </span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <strong style="color: var(--text-muted);">الكود:</strong>
                            <span class="d-block" style="color: var(--text-main);">{{ $risk->code }}</span>
                        </div>
                        <div class="col-md-3">
                            <strong style="color: var(--text-muted);">الفئة:</strong>
                            <span class="d-block" style="color: var(--text-main);">{{ $risk->category->name ?? '-' }}</span>
                        </div>
                        <div class="col-md-3">
                            <strong style="color: var(--text-muted);">الوحدة:</strong>
                            <span class="d-block" style="color: var(--text-main);">{{ $risk->organizationUnit->name ?? '-' }}</span>
                        </div>
                        <div class="col-md-3">
                            <strong style="color: var(--text-muted);">التاريخ:</strong>
                            <span class="d-block" style="color: var(--text-main);">{{ $risk->created_at->format('Y-m-d') }}</span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <strong style="color: var(--text-muted);">الوصف:</strong>
                        <p class="mt-2 p-3 rounded" style="color: var(--text-main); background: var(--bg-main);">{{ $risk->description }}</p>
                    </div>
                    @if($risk->legal_reference)
                    <div class="mb-3 p-3 rounded" style="background: var(--bg-main); border-inline-start: 3px solid var(--accent);">
                        <strong style="color: var(--text-muted);"><i class="bi bi-book-half me-1"></i>المرجع القانوني:</strong>
                        <p class="mt-1 mb-0" style="color: var(--text-main);">{{ $risk->legal_reference }}</p>
                    </div>
                    @endif
                    @if($risk->corrective_action)
                        <div class="mb-3">
                            <strong style="color: var(--text-muted);">الإجراء التصحيحي:</strong>
                            <p class="mt-1" style="color: var(--text-main);">{{ $risk->corrective_action }}</p>
                        </div>
                    @endif
                    @if($risk->preventive_action)
                        <div class="mb-3">
                            <strong style="color: var(--text-muted);">الإجراء الوقائي:</strong>
                            <p class="mt-1" style="color: var(--text-main);">{{ $risk->preventive_action }}</p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Risk Score Matrix 5x5 --}}
            <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div class="card-header" style="background: transparent; border-bottom: 1px solid var(--border-color);">
                    <h6 class="mb-0 fw-bold" style="color: var(--text-main);">مصفوفة الخطر</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered text-center mb-0" style="table-layout: fixed;">
                            <thead>
                                <tr>
                                    <th style="color: var(--text-muted); background: var(--bg-main);">الخطورة \ الاحتمالية</th>
                                    @for($l = 1; $l <= 5; $l++)
                                        <th style="color: var(--text-muted); background: var(--bg-main);">{{ $l }}</th>
                                    @endfor
                                </tr>
                            </thead>
                            <tbody>
                                @for($s = 5; $s >= 1; $s--)
                                    <tr>
                                        <th style="color: var(--text-muted); background: var(--bg-main);">{{ $s }}</th>
                                        @for($l = 1; $l <= 5; $l++)
                                            @php
                                                $score = $s * $l;
                                                if ($score >= 15) $bg = '#dc3545';
                                                elseif ($score >= 9) $bg = '#ffc107';
                                                elseif ($score >= 4) $bg = '#0dcaf0';
                                                else $bg = '#198754';
                                                $isActive = ($s == $risk->severity && $l == $risk->likelihood);
                                            @endphp
                                            <td style="background: {{ $bg }}; color: #fff; font-weight: bold; {{ $isActive ? 'border: 3px solid #fff; font-size: 1.2em;' : 'opacity: 0.5;' }}">
                                                {{ $score }}
                                                @if($isActive)
                                                    <i class="bi bi-geo-alt-fill"></i>
                                                @endif
                                            </td>
                                        @endfor
                                    </tr>
                                @endfor
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex gap-3 mt-3 justify-content-center">
                        <span class="badge" style="background: #198754;">1-3 منخفض</span>
                        <span class="badge" style="background: #0dcaf0; color: #000;">4-8 متوسط</span>
                        <span class="badge" style="background: #ffc107; color: #000;">9-14 مرتفع</span>
                        <span class="badge" style="background: #dc3545;">15-25 حرج</span>
                    </div>
                </div>
            </div>

            {{-- المراحل الثلاث --}}
            @if($risk->phases->isNotEmpty())
            @php
                $phaseMeta = [
                    'proactive'   => ['label' => 'استباقي',  'icon' => 'bi-shield-plus',      'color' => '#3b82f6'],
                    'operational' => ['label' => 'تشغيلي',  'icon' => 'bi-lightning-charge', 'color' => '#f59e0b'],
                    'response'    => ['label' => 'استجابة', 'icon' => 'bi-bandaid',          'color' => '#ef4444'],
                ];
            @endphp
            <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div class="card-header" style="background: transparent; border-bottom: 1px solid var(--border-color);">
                    <h6 class="mb-0 fw-bold" style="color: var(--text-main);"><i class="bi bi-layers me-2"></i>مراحل الخطر</h6>
                </div>
                <div class="card-body p-0">
                    @foreach($risk->phases->sortBy(fn($p) => array_search($p->phase, ['proactive','operational','response'])) as $phase)
                    @php $meta = $phaseMeta[$phase->phase] ?? ['label' => $phase->phase, 'icon' => 'bi-circle', 'color' => '#6c757d']; @endphp
                    <div class="p-3" style="border-bottom: 1px solid var(--border-color);">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <i class="bi {{ $meta['icon'] }}" style="color: {{ $meta['color'] }};"></i>
                            <strong style="color: {{ $meta['color'] }};">{{ $meta['label'] }}</strong>
                        </div>
                        <div class="row g-3">
                            @if($phase->preventive_action)
                            <div class="col-md-6">
                                <small class="text-muted d-block mb-1">الإجراء الوقائي</small>
                                <div class="p-2 rounded small" style="background: var(--bg-main); color: var(--text-main);">{{ $phase->preventive_action }}</div>
                            </div>
                            @endif
                            @if($phase->corrective_action)
                            <div class="col-md-6">
                                <small class="text-muted d-block mb-1">الإجراء التصحيحي</small>
                                <div class="p-2 rounded small" style="background: var(--bg-main); color: var(--text-main);">{{ $phase->corrective_action }}</div>
                            </div>
                            @endif
                            @if($phase->residual_assessment)
                            <div class="col-12">
                                <small class="text-muted d-block mb-1"><i class="bi bi-clipboard2-check me-1"></i>التقييم بعد الإجراءات</small>
                                <div class="p-2 rounded small" style="background: {{ $meta['color'] }}10; border-inline-start: 3px solid {{ $meta['color'] }}; color: var(--text-main);">{{ $phase->residual_assessment }}</div>
                            </div>
                            @endif
                            @if($phase->responsible_org_unit_display || $phase->responsible_user_display)
                            <div class="col-12">
                                <small class="text-muted"><i class="bi bi-person-badge me-1"></i>
                                    {{ $phase->responsible_org_unit_display }}
                                    @if($phase->responsible_org_unit_display && $phase->responsible_user_display) — @endif
                                    {{ $phase->responsible_user_display }}
                                </small>
                            </div>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- النماذج المرتبطة (مُولَّدة تلقائياً من الخطر) --}}
            @if(isset($linkedForms) && $linkedForms->isNotEmpty())
            @php
                $formTypeLabels = [
                    'awareness'              => ['label' => 'استبيان توعوي',          'color' => '#198754', 'icon' => 'bi-eye'],
                    'confirmation'           => ['label' => 'تأكيد قراءة واستيعاب',   'color' => '#0dcaf0', 'icon' => 'bi-check2-square'],
                    'declaration'            => ['label' => 'إقرار موقَّع',            'color' => '#0d6efd', 'icon' => 'bi-pen'],
                    'declaration_witnessed'  => ['label' => 'إقرار + شاهد + مدير',    'color' => '#dc3545', 'icon' => 'bi-shield-lock'],
                    'custom'                 => ['label' => 'نموذج مخصص',              'color' => '#6c757d', 'icon' => 'bi-file-earmark'],
                ];
            @endphp
            <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div class="card-header d-flex align-items-center gap-2" style="background: transparent; border-bottom: 1px solid var(--border-color);">
                    <i class="bi bi-file-earmark-check" style="color: var(--accent);"></i>
                    <h6 class="mb-0 fw-bold" style="color: var(--text-main);">النماذج الرقمية المرتبطة</h6>
                    <span class="badge rounded-pill ms-auto"
                          style="background: var(--bg-main); color: var(--text-muted); border: 1px solid var(--border-color);">
                        {{ $linkedForms->count() }}
                    </span>
                </div>
                <div class="card-body p-0">
                    @foreach($linkedForms as $form)
                        @php $ft = $formTypeLabels[$form->form_type] ?? $formTypeLabels['custom']; @endphp
                        <div class="d-flex align-items-center gap-3 px-3 py-2"
                             style="border-bottom: 1px solid var(--border-color);">
                            <i class="bi {{ $ft['icon'] }}" style="color: {{ $ft['color'] }};"></i>
                            <div class="flex-grow-1 small">
                                <div class="fw-semibold" style="color: var(--text-main);">{{ $form->title }}</div>
                                <span class="px-2 py-0 rounded"
                                      style="font-size:.72rem; background: {{ $ft['color'] }}15; color: {{ $ft['color'] }}; border: 1px solid {{ $ft['color'] }}40;">
                                    {{ $ft['label'] }}
                                </span>
                                @if($form->source_risk_id === $risk->id)
                                    <span class="px-2 py-0 rounded ms-1"
                                          style="font-size:.72rem; background: #0dcaf015; color: #0dcaf0; border: 1px solid #0dcaf040;">
                                        مُولَّد تلقائياً
                                    </span>
                                @endif
                            </div>
                            <div class="d-flex gap-1 flex-shrink-0">
                                @if(Route::has('forms.fill'))
                                    <a href="{{ route('forms.fill', $form) }}" class="btn btn-sm btn-outline-primary py-0 px-2" title="تعبئة">
                                        <i class="bi bi-pencil-square"></i>
                                    </a>
                                @endif
                                @if(Route::has('forms.submissions', $form))
                                    <a href="{{ route('forms.submissions', $form) }}" class="btn btn-sm btn-outline-secondary py-0 px-2" title="النتائج">
                                        <i class="bi bi-bar-chart"></i>
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Notes --}}
            <div class="card mb-4" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div class="card-header" style="background: transparent; border-bottom: 1px solid var(--border-color);">
                    <h6 class="mb-0 fw-bold" style="color: var(--text-main);">الملاحظات</h6>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('risk.notes.store', $risk) }}" class="mb-4">
                        @csrf
                        <div class="input-group">
                            <textarea name="note" class="form-control" rows="2" placeholder="أضف ملاحظة..."
                                      style="background: var(--bg-main); color: var(--text-main); border-color: var(--border-color);"></textarea>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i></button>
                        </div>
                    </form>
                    {{-- عمود risks.notes (نص) يحجب علاقة notes() — نقرأ العلاقة صراحةً --}}
                    @forelse(($risk->relationLoaded('notes') ? $risk->getRelation('notes') : $risk->notes()->with('createdBy')->get()) as $note)
                        <div class="p-3 rounded mb-2" style="background: var(--bg-main);">
                            <div class="d-flex justify-content-between mb-1">
                                <strong style="color: var(--accent);">{{ $note->createdBy->name ?? 'النظام' }}</strong>
                                <small style="color: var(--text-muted);">{{ $note->created_at->diffForHumans() }}</small>
                            </div>
                            <p class="mb-0" style="color: var(--text-main);">{{ $note->note }}</p>
                        </div>
                    @empty
                        <p class="text-muted text-center">لا توجد ملاحظات</p>
                    @endforelse
                </div>
            </div>

            {{-- Linked Incidents --}}
            <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div class="card-header" style="background: transparent; border-bottom: 1px solid var(--border-color);">
                    <h6 class="mb-0 fw-bold" style="color: var(--text-main);">الحوادث المرتبطة</h6>
                </div>
                <div class="card-body">
                    @forelse(($risk->relationLoaded('incidents') ? $risk->incidents : collect()) as $incident)
                        <div class="d-flex justify-content-between align-items-center p-2 rounded mb-2" style="background: var(--bg-main);">
                            <a href="{{ route('incidents.show', $incident) }}" style="color: var(--accent); text-decoration: none;">{{ $incident->title }}</a>
                            <span class="badge bg-{{ ['new'=>'primary','received'=>'info','in_progress'=>'warning','resolved'=>'success','escalated'=>'danger','closed'=>'dark'][$incident->status] ?? 'secondary' }}">
                                {{ $incident->status_label ?? $incident->status }}
                            </span>
                        </div>
                    @empty
                        <p class="text-muted text-center">لا توجد حوادث مرتبطة</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Sidebar - Events Timeline --}}
        <div class="col-md-4">
            <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color);">
                <div class="card-header" style="background: transparent; border-bottom: 1px solid var(--border-color);">
                    <h6 class="mb-0 fw-bold" style="color: var(--text-main);">سجل الأحداث</h6>
                </div>
                <div class="card-body">
                    @forelse($risk->events ?? [] as $event)
                        <div class="d-flex mb-3">
                            <div class="me-3">
                                <div class="rounded-circle d-flex align-items-center justify-content-center"
                                     style="width: 36px; height: 36px; background: rgba(13,202,175,0.15);">
                                    <i class="bi bi-clock" style="color: var(--accent);"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <strong style="color: var(--text-main);">{{ $event->action_label }}</strong>
                                <small class="d-block" style="color: var(--text-muted);">
                                    {{ $event->actor->name ?? 'النظام' }} - {{ $event->created_at->format('Y-m-d H:i') }}
                                </small>
                                @if($event->note)
                                    <p class="mt-1 mb-0 small" style="color: var(--text-muted);">{{ $event->note }}</p>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-muted text-center">لا توجد أحداث</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
