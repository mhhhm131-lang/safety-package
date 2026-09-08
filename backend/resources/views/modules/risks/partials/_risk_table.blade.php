{{--
    Risk table partial — used by master / reference / index (active) views.

    Variables expected:
      $risks         — paginated/filtered Risk collection (with all phases loaded)
      $registry_type — 'master' | 'reference' | 'active'
      $can_edit      — bool, optional
--}}
@php
    use App\Modules\Risk\Models\RiskPhase;

    $registry_type = $registry_type ?? 'reference';
    $can_edit = $can_edit ?? true;
    $isActive = $registry_type === 'active';

    $phaseOrder = [
        RiskPhase::PHASE_PROACTIVE   => ['label' => 'استباقي',  'color' => '#0891b2', 'bg' => 'rgba(8,145,178,.10)'],
        RiskPhase::PHASE_OPERATIONAL => ['label' => 'تشغيلي',   'color' => '#d97706', 'bg' => 'rgba(217,119,6,.10)'],
        RiskPhase::PHASE_RESPONSE    => ['label' => 'استجابة',  'color' => '#dc2626', 'bg' => 'rgba(220,38,38,.10)'],
    ];
@endphp

<div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color);">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm risk-table mb-0">
                <thead>
                    <tr>
                        <th>الكود</th>
                        <th class="text-center">الدرجة</th>
                        <th>العنوان</th>
                        <th>الفئة</th>
                        <th class="phase-col">الطور</th>
                        <th>الأسباب</th>
                        <th>المتأثرون</th>
                        <th>الإجراء التصحيحي</th>
                        <th>الإجراء الوقائي</th>
                        @if($isActive)
                        <th>الوحدة</th>
                        <th>الحالة</th>
                        @endif
                        <th>الإدارة</th>
                        <th>المسؤول</th>
                        <th>الفريق التنفيذي</th>
                        <th>الجهة / المفوّض</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($risks ?? [] as $risk)
                    @php
                        $score      = $risk->risk_score ?? 0;
                        $scoreColor = $score >= 15 ? '#ef4444' : ($score >= 7 ? '#f59e0b' : '#10b981');
                        $phasesByKey = $risk->phases->keyBy('phase');
                        $totalCols = $isActive ? 16 : 14;
                    @endphp

                    @foreach($phaseOrder as $phaseKey => $phaseMeta)
                    @php
                        $phase      = $phasesByKey->get($phaseKey);
                        $isFirst    = $loop->first;
                        $isLast     = $loop->last;
                        $causesList = $phase?->causes?->pluck('name') ?? collect();
                        $groupsList = $phase?->affectedGroups?->pluck('name') ?? collect();
                        $corrective = $phase?->corrective_action;
                        $preventive = $phase?->preventive_action;
                        $orgUnit    = $phase?->responsible_org_unit_display;
                        $user       = $phase?->responsible_user_display;

                        $rowStyle = 'background:' . $phaseMeta['bg'] . ';';
                        if ($isFirst)  $rowStyle .= 'border-top: 2px solid var(--border-color);';
                        if ($isLast)   $rowStyle .= 'border-bottom: 3px solid var(--border-color);';
                    @endphp
                    <tr style="{{ $rowStyle }}">

                        {{-- Code + Score + Title + Category: span 3 rows on first phase only --}}
                        @if($isFirst)
                        <td rowspan="3" class="align-middle text-center" style="vertical-align:middle!important;">
                            <span class="code-pill code-pill-{{ $registry_type }}">
                                {{ $risk->code ?? 'R-' . $risk->id }}
                            </span>
                        </td>
                        <td rowspan="3" class="align-middle text-center" style="vertical-align:middle!important;">
                            <span class="score-circle" style="background:{{ $scoreColor }};">{{ $score }}</span>
                        </td>
                        <td rowspan="3" class="risk-title-cell align-middle" style="vertical-align:middle!important;">
                            <a href="{{ route('risk.show', $risk) }}" class="risk-title-link"
                               title="{{ $risk->title }}">
                                {{ \Illuminate\Support\Str::limit($risk->title, 35) }}
                            </a>
                        </td>
                        <td rowspan="3" class="align-middle" style="vertical-align:middle!important;">
                            <small class="text-muted">{{ $risk->category->name ?? '—' }}</small>
                        </td>
                        @endif

                        {{-- Phase badge --}}
                        <td class="text-nowrap">
                            <span class="phase-badge" style="background:{{ $phaseMeta['bg'] }}; color:{{ $phaseMeta['color'] }}; border:1px solid {{ $phaseMeta['color'] }};">
                                {{ $phaseMeta['label'] }}
                            </span>
                        </td>

                        {{-- Causes --}}
                        <td>
                            @if($causesList->isNotEmpty())
                                <span class="pop-trigger"
                                      data-bs-toggle="popover"
                                      data-bs-placement="top"
                                      data-bs-trigger="click"
                                      data-bs-title="الأسباب ({{ $phaseMeta['label'] }})"
                                      data-bs-html="true"
                                      data-bs-content="{{ e('<ul class=\'mb-0 ps-3\'>' . $causesList->map(fn($c) => '<li>' . e($c) . '</li>')->implode('') . '</ul>') }}">
                                    <span class="badge-count">{{ $causesList->count() }} سبب</span>
                                    <i class="bi bi-chevron-down pop-arrow"></i>
                                </span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>

                        {{-- Affected groups --}}
                        <td>
                            @if($groupsList->isNotEmpty())
                                <span class="pop-trigger"
                                      data-bs-toggle="popover"
                                      data-bs-placement="top"
                                      data-bs-trigger="click"
                                      data-bs-title="المتأثرون ({{ $phaseMeta['label'] }})"
                                      data-bs-html="true"
                                      data-bs-content="{{ e('<ul class=\'mb-0 ps-3\'>' . $groupsList->map(fn($g) => '<li>' . e($g) . '</li>')->implode('') . '</ul>') }}">
                                    <span class="badge-count">{{ $groupsList->count() }} فئة</span>
                                    <i class="bi bi-chevron-down pop-arrow"></i>
                                </span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>

                        {{-- Corrective action --}}
                        <td>
                            @if($corrective)
                                <span class="pop-trigger"
                                      data-bs-toggle="popover"
                                      data-bs-placement="top"
                                      data-bs-trigger="click"
                                      data-bs-title="الإجراء التصحيحي ({{ $phaseMeta['label'] }})"
                                      data-bs-content="{{ e($corrective) }}">
                                    <span class="pop-preview">{{ \Illuminate\Support\Str::words($corrective, 2, '…') }}</span>
                                    <i class="bi bi-chevron-down pop-arrow"></i>
                                </span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>

                        {{-- Preventive action --}}
                        <td>
                            @if($preventive)
                                <span class="pop-trigger"
                                      data-bs-toggle="popover"
                                      data-bs-placement="top"
                                      data-bs-trigger="click"
                                      data-bs-title="الإجراء الوقائي ({{ $phaseMeta['label'] }})"
                                      data-bs-content="{{ e($preventive) }}">
                                    <span class="pop-preview">{{ \Illuminate\Support\Str::words($preventive, 2, '…') }}</span>
                                    <i class="bi bi-chevron-down pop-arrow"></i>
                                </span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>

                        {{-- Active-only columns --}}
                        @if($isActive && $isFirst)
                        <td rowspan="3" class="align-middle" style="vertical-align:middle!important;">
                            <small>{{ \Illuminate\Support\Str::limit($risk->organizationUnit->name ?? '—', 15) }}</small>
                        </td>
                        <td rowspan="3" class="align-middle" style="vertical-align:middle!important;">
                            @php
                                $statusBadge = [
                                    'draft'            => 'secondary',
                                    'pending_approval' => 'info',
                                    'approved'         => 'success',
                                    'active'           => 'primary',
                                    'in_progress'      => 'warning',
                                    'closed'           => 'dark',
                                    'rejected'         => 'danger',
                                ][$risk->status] ?? 'secondary';
                            @endphp
                            <span class="badge bg-{{ $statusBadge }}">{{ $risk->status_label }}</span>
                        </td>
                        @elseif($isActive && !$isFirst)
                        {{-- cells already covered by rowspan --}}
                        @endif

                        {{-- الإدارة / المسؤول / الفريق التنفيذي --}}
                        @if($isFirst)
                        <td rowspan="3" class="align-middle" style="vertical-align:middle!important;">
                            <small class="text-muted">{{ $risk->owner_department ?? '—' }}</small>
                        </td>
                        <td rowspan="3" class="align-middle" style="vertical-align:middle!important;">
                            <small class="text-muted">{{ $risk->assignedCoordinator?->name ?? '—' }}</small>
                        </td>
                        <td rowspan="3" class="align-middle" style="vertical-align:middle!important;">
                            <small class="text-muted">{{ $risk->assignedFieldTeam?->name ?? '—' }}</small>
                        </td>
                        @endif

                        {{-- Responsible --}}
                        <td>
                            @if($orgUnit || $user)
                                <span class="pop-trigger"
                                      data-bs-toggle="popover"
                                      data-bs-placement="top"
                                      data-bs-trigger="click"
                                      data-bs-title="الجهة والمفوّض ({{ $phaseMeta['label'] }})"
                                      data-bs-html="true"
                                      data-bs-content="{{ e('<b>الجهة:</b> ' . ($orgUnit ?? '—') . '<br><b>المفوّض:</b> ' . ($user ?? '—')) }}">
                                    <span class="pop-preview">{{ \Illuminate\Support\Str::words($orgUnit ?? $user ?? '', 2, '…') }}</span>
                                    <i class="bi bi-chevron-down pop-arrow"></i>
                                </span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>

                        {{-- Actions: first row only, spans 3 --}}
                        @if($isFirst)
                        <td rowspan="3" class="align-middle" style="vertical-align:middle!important;">
                            <div class="d-flex flex-column gap-1">
                                <a href="{{ route('risk.show', $risk) }}" class="btn btn-sm btn-outline-primary" title="عرض">
                                    <i class="bi bi-eye"></i>
                                </a>
                                @if($can_edit)
                                <a href="{{ $registry_type === 'master'
                                    ? route('risk.master.edit', $risk)
                                    : ($registry_type === 'reference'
                                        ? route('risk.reference.edit', $risk)
                                        : route('risk.active.edit', $risk)) }}"
                                   class="btn btn-sm btn-outline-secondary" title="تعديل">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                @endif
                                @if($registry_type === 'master')
                                <button type="button"
                                        class="btn btn-sm btn-outline-info btn-copy-master"
                                        data-risk-id="{{ $risk->id }}"
                                        data-copy-url="{{ route('risk.copyFromMaster', $risk->id) }}"
                                        title="نسخ للسجل المرجعي">
                                    <i class="bi bi-arrow-down-circle"></i>
                                </button>
                                @endif
                                @if($registry_type === 'reference')
                                <a href="{{ route('risk.activate.form', $risk) }}" class="btn btn-sm btn-outline-success" title="تفعيل">
                                    <i class="bi bi-lightning"></i>
                                </a>
                                @endif
                            </div>
                        </td>
                        @endif

                    </tr>
                    @endforeach

                @empty
                    <tr>
                        <td colspan="{{ $isActive ? 16 : 14 }}" class="text-center py-5">
                            <i class="bi bi-inbox" style="font-size:2.5rem; color:var(--text-muted);"></i>
                            <div class="mt-2 text-muted">لا توجد مخاطر مسجلة</div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($risks instanceof \Illuminate\Pagination\LengthAwarePaginator && $risks->hasPages())
    <div class="card-footer" style="background:transparent; border-top:1px solid var(--border-color);">
        {{ $risks->withQueryString()->links() }}
    </div>
    @endif
</div>

<style>
    .risk-table { font-size: 0.8rem; color: var(--text-main); }
    .risk-table thead th {
        background: var(--bg-dark);
        color: var(--text-muted);
        font-weight: 600;
        font-size: 0.7rem;
        white-space: nowrap;
        border-bottom: 2px solid var(--border-color);
        padding: 0.6rem 0.5rem;
    }
    .risk-table tbody td {
        padding: 0.4rem 0.5rem;
        border-color: var(--border-color);
        vertical-align: middle;
        white-space: nowrap;
    }

    .phase-col { width: 80px; }

    .phase-badge {
        display: inline-block;
        padding: .18rem .55rem;
        border-radius: 20px;
        font-size: .68rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .code-pill {
        display: inline-block;
        padding: .2rem .5rem;
        border-radius: .25rem;
        font-family: monospace;
        font-size: .7rem;
        font-weight: 600;
        white-space: nowrap;
    }
    .code-pill-master    { background: rgba(139,92,246,.18); color: #a78bfa; }
    .code-pill-reference { background: rgba(8,145,178,.18);  color: #06b6d4; }
    .code-pill-active    { background: rgba(16,185,129,.18); color: #34d399; }

    .score-circle {
        display: inline-flex; align-items: center; justify-content: center;
        width: 30px; height: 30px; border-radius: 50%;
        color: #fff; font-weight: 700; font-size: .78rem;
    }

    .risk-title-cell { max-width: 180px; }
    .risk-title-link {
        color: var(--text-main); text-decoration: none; font-weight: 500;
        display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .risk-title-link:hover { color: var(--accent); }

    .pop-trigger {
        display: inline-flex; align-items: center; gap: 3px;
        cursor: pointer;
        padding: 2px 7px;
        border-radius: 20px;
        border: 1px solid var(--border-color);
        background: var(--bg-dark);
        font-size: .72rem;
        color: var(--text-main);
        transition: background .15s;
        white-space: nowrap;
    }
    .pop-trigger:hover { background: rgba(8,145,178,.12); border-color: var(--accent); color: var(--accent); }

    .badge-count { font-weight: 600; }
    .pop-preview { max-width: 80px; overflow: hidden; text-overflow: ellipsis; display: inline-block; vertical-align: middle; }
    .pop-arrow   { font-size: .55rem; opacity: .6; }

    .popover { max-width: 320px; font-size: .82rem; direction: rtl; text-align: right; }
    .popover-body ul { margin: 0; padding-right: 1.2rem; padding-left: 0; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const popEls = document.querySelectorAll('[data-bs-toggle="popover"]');
    const pops = [...popEls].map(el => new bootstrap.Popover(el, { container: 'body' }));

    popEls.forEach((el, i) => {
        el.addEventListener('show.bs.popover', () => {
            pops.forEach((p, j) => { if (j !== i) p.hide(); });
        });
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('[data-bs-toggle="popover"]') && !e.target.closest('.popover')) {
            pops.forEach(p => p.hide());
        }
    });

    document.querySelectorAll('.btn-copy-master').forEach(btn => {
        btn.addEventListener('click', async function () {
            const url  = this.dataset.copyUrl;
            const icon = this.querySelector('i');
            this.disabled = true;
            icon.className = 'bi bi-hourglass-split';

            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });
                const data = await res.json().catch(() => ({}));
                if (res.ok) {
                    icon.className = 'bi bi-check-circle-fill';
                    this.classList.replace('btn-outline-info', 'btn-outline-success');
                    this.title = 'تم النسخ';
                    showTableToast('تم نسخ الخطر للسجل المرجعي ✓', 'success');
                } else {
                    throw new Error(data.message || 'خطأ');
                }
            } catch (e) {
                icon.className = 'bi bi-arrow-down-circle';
                this.disabled = false;
                showTableToast('فشل النسخ: ' + e.message, 'error');
            }
        });
    });

    function showTableToast(msg, type) {
        const t = document.createElement('div');
        t.style.cssText = `position:fixed;bottom:20px;left:20px;z-index:9999;
            padding:10px 18px;border-radius:10px;font-size:.85rem;font-weight:600;
            box-shadow:0 4px 16px rgba(0,0,0,.25);transition:opacity .4s;direction:rtl;`;
        t.style.background = type === 'success' ? '#16a34a' : '#dc2626';
        t.style.color = '#fff';
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 400); }, 3000);
    }
});
</script>
