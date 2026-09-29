@extends('layouts.app')
@section('title', 'ملف '.$place->name)
@section('content')
{{-- المرحلة ١٩-١ (قرار ٤٨): ملف المكان — ما كان في dashboard.html#place= بالبيانات نفسها، داخل الخلفية. قراءة؛ التحرير في مواضعه.
     ٢٦-٦ (قرار ٦٦): البنود بالترتيب — خطة السلامة ← خطة الاستجابة ← نماذج الفحص ← مخاطر المكان ← المفتوح الآن ← الفريق الأولي ← طلب تصريح هنا.
     ما ليس للشخص يظهر باهتاً باسم صاحبه (لا «ليس لديك صلاحية» بعد ضغطة).
     ٢٦-٦-ب (قرار ٦٨): شاشة واحدة — سطر الحال ← سطر الوحدات (مرشّح «أين بالضبط؟»، كان القسم ٦) ← سبعة أزرار في عمودين بترتيب ثابت،
     كل زر يحمل حاله ولونه؛ الزر يفتح قسمه تحت الشبكة في مكانه؛ الخطتان تفتحان الخطة، و«طلب تصريح» هو الفعل. المحتوى والمعرّفات كما هي. --}}
@php($R = \App\Modules\Store\Services\InspectionDocReader::class)
@php($L = \App\Modules\Governance\Models\PlaceUnit::TYPE_LABELS)
@php($ST = ['ok' => 'st-ok', 'late' => 'st-late', 'fault' => 'st-wait', 'none' => 'text-bg-secondary'])
@php($PL = collect($plans)->keyBy('key'))
@php($ins = $PL['insp'] ?? null)
@php($tm = $PL['team'] ?? null)
{{-- ٢٦-٦-ب: حال كل زر — رقم أو كلمة، ولون: ok أخضر · warn ذهبي · bad أحمر · none بلا لون · dim باهت لمن لا يملكه · act فعل.
     الصيغة المختصرة للـphp وحدها في هذا الملف: كتلة php مغلقة (حتى داخل تعليق) تُلتقط من أول توجيه php في الملف فتُفسده --}}
@php($openN = count($open) + $incidents->count() + $openEmergency->count())
@php($btnForms = !count($systems) ? ['warn', 'لم تُفتح جولة بعد'] : ($sum['late'] ? ['bad', $sum['late'].' متأخر أو لم يُفحص'.($sum['fault'] ? ' · '.$sum['fault'].' عطل' : '')] : ($sum['fault'] ? ['bad', $sum['fault'].' فيه عطل مفتوح'] : ['ok', 'كلها فُحصت في موعدها'])))
@php($btnRisks = !$canRisks ? ['dim', 'لمنسق السلامة ومديري الإدارات ومسؤول السلامة'] : ($risksTotal ? ['none', $risksTotal.' مفعّل'] : ['none', 'لا خطر مفعّل بعد']))
@php($btnNow = $openN ? [($kpi['overdue'] || $openEmergency->isNotEmpty()) ? 'bad' : 'warn', $openN.' مفتوح'.($kpi['overdue'] ? ' · '.$kpi['overdue'].' متجاوز' : '').($openEmergency->isNotEmpty() ? ' · حالة طارئة' : '')] : ['ok', 'لا شيء مفتوح'])
@php($btnTeam = [$tm['cls'] ?? 'none', $tm['v'] ?? '—'])
@php($btnPermit = $canPermit ? ['act', 'اطلب تصريح عمل في '.$place->name] : ['dim', 'لمركز السلامة ومنسق السلامة والمقاول'])
@php($manyUnits = $units->count() > 12)
@php($unitTitle = fn ($u) => ($L[$u->type] ?? $u->type).($u->floor ? ' · الدور '.$u->floor : '').($u->location ? ' · '.$u->location : '').($u->capacity ? ' · '.$u->capacity.' شخصاً' : '').($u->operator ? ' · '.$u->operator : ''))
<style>
  .pf-sys{display:grid;grid-template-columns:1.6fr 1fr 1fr auto auto;gap:6px 12px;align-items:center;padding:10px 12px;border:1px solid var(--bs-border-color);border-radius:10px;background:#fff}
  .pf-sys small{color:#6b7a74;display:block}
  /* ١٩-٥: الفريق — أربع بطاقات ومسار الخطوات الثلاث */
  .pf-team{border:1px solid var(--bs-border-color);border-inline-start:5px solid #adb5bd;border-radius:10px;padding:8px 10px;background:#fff}
  .pf-team.ok{border-inline-start-color:#0f4c3a}.pf-team.warn{border-inline-start-color:#d9b25a}.pf-team.bad{border-inline-start-color:#c62828}
  .pf-members{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px}
  .pf-m{border:1px solid var(--bs-border-color);border-radius:8px;padding:6px 8px;min-width:0;overflow-wrap:anywhere}
  .pf-steps{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
  .pf-steps span{border:1px solid var(--bs-border-color);border-radius:999px;padding:2px 10px;color:#6b7a74}
  .pf-steps span.done{background:#e7f1ec;border-color:#0f4c3a;color:#0f4c3a}.pf-steps span.cur{border-color:#d9b25a;color:#7a5b00}
  /* ٢٦-٦: الباهت لمن لا يملكه */
  .pf-dim{opacity:.5}.pf-dim .btn,.pf-dim a{pointer-events:none}
  .pf-n{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:var(--tint);color:var(--g);font-weight:900;font-size:.85rem;margin-inline-end:.35rem}
  /* ٢٦-٦-ب: الشبكة وأزرارها بحالها، ورقاقات الوحدات */
  .pf-grid{display:grid;grid-template-columns:1fr 1fr;gap:.5rem}
  .pf-btn{display:block;width:100%;text-align:start;text-decoration:none;color:inherit;border:1px solid var(--bs-border-color);border-inline-start:5px solid #adb5bd;border-radius:10px;padding:8px 10px;background:#fff;min-height:64px;cursor:pointer;font:inherit}
  .pf-btn[data-state=ok]{border-inline-start-color:#0f4c3a}.pf-btn[data-state=warn]{border-inline-start-color:#d9b25a}.pf-btn[data-state=bad]{border-inline-start-color:#c62828}
  .pf-btn[data-state=dim]{opacity:.5;pointer-events:none}
  .pf-btn[data-state=act]{background:var(--g);color:#fff;border-color:var(--g)}.pf-btn[data-state=act] .pf-n{background:#fff}
  .pf-btn .t{font-weight:700}.pf-btn .s{font-size:.85rem;color:#6b7a74;margin-top:2px}
  .pf-btn[data-state=bad] .s{color:#c62828;font-weight:700}.pf-btn[data-state=ok] .s{color:#0f4c3a}.pf-btn[data-state=act] .s{color:#e3ede8}
  .pf-btn.open{background:var(--tint)}.pf-btn.wide{grid-column:1/-1}
  .pf-chip{border:1px solid var(--bs-border-color);border-radius:999px;padding:3px 12px;background:#fff;color:inherit;font-size:.9rem}.pf-chip.on{background:var(--g);color:#fff;border-color:var(--g)}
  .pf-sec{margin-top:.75rem}
  @media(max-width:700px){.pf-members{grid-template-columns:1fr 1fr}.pf-sys{grid-template-columns:1fr 1fr}.pf-sys .nm{grid-column:1/-1}
    /* الأرقام الأربعة مربعان في صفين على الجوال — كلها ظاهرة بلا تمرير أفقي */
    #pfKpi.tiles{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));overflow:visible;margin-inline:0;padding-inline:0;gap:.6rem}
    #pfKpi.tiles .tile{min-width:0}}
</style>

<div class="d-flex flex-wrap align-items-center gap-2 mb-1">
  <a class="small" href="{{ route('app.home') }}#places">الأماكن</a><span class="text-muted">›</span>
  <h1 class="page-h m-0">{{ $place->name }}</h1><span class="small text-muted" dir="ltr">{{ $place->code }}</span>
</div>
<p class="small text-muted mb-2" id="pfSum" data-sum-ok="{{ $sum['ok'] }}" data-sum-late="{{ $sum['late'] }}" data-sum-fault="{{ $sum['fault'] }}">
  @if(count($systems))
    <b>{{ $sum['ok'] }}</b> من {{ count($systems) }} نظاماً فُحص في موعده وسليم@if($sum['late']) · <b class="text-danger">{{ $sum['late'] }}</b> فحصه متأخر أو لم يُفحص@endif @if($sum['fault']) · <b style="color:#b8860b">{{ $sum['fault'] }}</b> فيه عطل مفتوح@endif
  @else لم تُفتح جولة لهذا المكان بعد @endif
</p>

{{-- سطر الوحدات (كان القسم ٦): السؤال الأول «أين بالضبط؟» — الافتراضي الكل؛ الاختيار يحصر بلاغات الفحص وبلاغات الشاغلين (والفريق في المكاتب) --}}
<div class="d-flex flex-wrap align-items-center gap-2 mb-2" id="pfUnitsLine">
  <span class="small text-muted">الوحدات <span class="badge text-bg-dark">{{ $units->count() }}</span></span>
  @if($units->isEmpty())
    <span class="small text-muted">لم تُدخل وحدات هذا المكان بعد.@if($canUnits) <a href="{{ route('app.places.units.index', $place) }}">أدخلها الآن</a>@endif</span>
  @elseif($manyUnits)
    <select class="form-select form-select-sm" id="pfUnitSelect" style="max-width:22rem" data-unit-filter>
      <option value="" data-unit="">الكل</option>
      @foreach($units as $u)<option value="{{ $u->name }}" data-unit="{{ $u->name }}"@if($u->type === 'department') data-unit-chip="{{ $u->unit_code ?? $u->organization_unit_id }}" data-unit-code="{{ $u->unit_code ?? '' }}"@endif>{{ $u->name }}{{ $u->floor ? ' · الدور '.$u->floor : '' }}{{ $u->location ? ' · '.$u->location : '' }}</option>@endforeach
    </select>
  @else
    <div class="d-flex flex-wrap gap-1" id="pfUnits">
      <button type="button" class="pf-chip on" data-unit="" data-unit-filter>الكل</button>
      @foreach($units as $u)<button type="button" class="pf-chip" data-unit="{{ $u->name }}" data-unit-filter title="{{ $unitTitle($u) }}" @if($u->type === 'department') data-unit-chip="{{ $u->unit_code ?? $u->organization_unit_id }}" data-unit-code="{{ $u->unit_code ?? '' }}"@endif>{{ $u->name }}</button>@endforeach
    </div>
  @endif
  @if($canUnits && $units->isNotEmpty())<a class="small ms-auto" href="{{ route('app.places.units.index', $place) }}">تعديل الوحدات</a>@endif
</div>
<div class="small text-muted mb-2" id="pfUnitInfo" hidden></div>

{{-- الأزرار السبعة بترتيب ثابت: ١ ٢ ٣ ٤ ٥ ٧ ٨ --}}
<div class="pf-grid mb-2" id="pfGrid">
  @foreach(['sa', 'ra'] as $k)
    @continue(!$PL->has($k))
    @php($p = $PL[$k])
    <a class="pf-btn" id="{{ $k === 'sa' ? 'pfPlanSafety' : 'pfPlanResponse' }}" data-sec="{{ $k }}" data-state="{{ $p['cls'] }}" data-ready="{{ $p['key'] }}" href="{{ $p['url'] }}">
      <div class="t"><span class="pf-n">{{ $k === 'sa' ? '١' : '٢' }}</span>{{ $p['label'] }}{{ $k === 'sa' ? ' — الاستباقية والتشغيلية' : '' }} <i class="bi bi-box-arrow-up-left small"></i></div>
      <div class="s">{{ $p['v'] }}<br>{{ $p['d'] }}</div>
    </a>
  @endforeach
  <button type="button" class="pf-btn" id="pfForms" data-sec="forms" data-state="{{ $btnForms[0] }}" aria-controls="pfSecForms" aria-expanded="false">
    <div class="t"><span class="pf-n">٣</span><i class="bi bi-clipboard-check"></i> نماذج الفحص <span class="badge text-bg-dark">{{ count($systems) }}</span></div>
    <div class="s">{{ $btnForms[1] }}</div>
  </button>
  <button type="button" class="pf-btn" id="pfRisks" data-sec="risks" data-state="{{ $btnRisks[0] }}" aria-controls="pfSecRisks" aria-expanded="false">
    <div class="t"><span class="pf-n">٤</span><i class="bi bi-lightning-charge"></i> مخاطر المكان @if($canRisks)<span class="badge text-bg-dark">{{ $risksTotal }}</span>@endif</div>
    <div class="s">{{ $btnRisks[1] }}</div>
  </button>
  <button type="button" class="pf-btn" id="pfNow" data-sec="now" data-state="{{ $btnNow[0] }}" aria-controls="pfSecNow" aria-expanded="false">
    <div class="t"><span class="pf-n">٥</span><i class="bi bi-activity"></i> المفتوح الآن @if($openN)<span class="badge {{ $btnNow[0] === 'bad' ? 'text-bg-danger' : 'text-bg-dark' }}">{{ $openN }}</span>@endif</div>
    <div class="s">{{ $btnNow[1] }}</div>
  </button>
  <button type="button" class="pf-btn" id="pfTeamH" data-sec="team" data-state="{{ $btnTeam[0] }}" aria-controls="pfSecTeam" aria-expanded="false">
    <div class="t"><span class="pf-n">٧</span><i class="bi bi-people-fill"></i> الفريق الأولي</div>
    <div class="s" data-ready="team">{{ $btnTeam[1] }}</div>
  </button>
  {{-- ٨. طلب تصريح عمل هنا (المكان محدد) — الزر هو الفعل؛ لمن يملك طلب التصاريح، وإلا باهت باسم صاحبه --}}
  @if($canPermit)<a class="pf-btn wide" id="pfPermit" data-sec="permit" data-state="act" href="{{ route('permits.create') }}?place={{ $place->code }}" data-act="permit">
    <div class="t"><span class="pf-n">٨</span><i class="bi bi-file-earmark-plus"></i> طلب تصريح عمل هنا</div><div class="s">{{ $btnPermit[1] }}</div></a>
  @else<span class="pf-btn wide" id="pfPermit" data-sec="permit" data-state="dim" data-act="permit" title="لمن يملك طلب التصاريح">
    <div class="t"><span class="pf-n">٨</span><i class="bi bi-file-earmark-plus"></i> طلب تصريح عمل هنا</div><div class="s">— {{ $btnPermit[1] }}</div></span>@endif
</div>
@if($can['plans'])
  <details class="mb-2" id="pfPlansEdit"><summary class="small" style="cursor:pointer;color:#0f4c3a">تحرير الخطتين</summary>
    <form method="post" action="{{ route('app.places.team.plans', $place) }}" class="card card-body mt-2">@csrf
      <div class="row g-2">
        <div class="col-6 col-md-3"><label class="form-label small">اعتماد خطة السلامة</label><input type="date" class="form-control form-control-sm" name="sa" value="{{ $pl['sa'] ?? '' }}"></div>
        <div class="col-6 col-md-3"><label class="form-label small">المعتمِد</label><input class="form-control form-control-sm" name="sa_by" maxlength="120" placeholder="الإدارة العليا" value="{{ $pl['saBy'] ?? '' }}"></div>
        <div class="col-6 col-md-3"><label class="form-label small">اعتماد خطة الاستجابة</label><input type="date" class="form-control form-control-sm" name="ra" value="{{ $pl['ra'] ?? '' }}"></div>
        <div class="col-6 col-md-3"><label class="form-label small">آخر تمرين على الخطة</label><input type="date" class="form-control form-control-sm" name="drill" value="{{ $pl['drill'] ?? '' }}"></div>
      </div>
      <div class="mt-2"><button class="btn btn-g btn-sm">حفظ</button></div>
    </form>
  </details>
@endif

{{-- ٣. نماذج الفحص الخاصة بالمكان: النموذج بضغطة، وكل نظام بآخر جولة وموعد القادمة --}}
<section class="pf-sec" id="pfSecForms" hidden>
<h2 class="sec-h"><span class="pf-n">٣</span><i class="bi bi-clipboard-check"></i> نماذج الفحص <span class="badge text-bg-dark">{{ count($systems) }}</span>
  @if($ins)<span class="small text-muted fw-normal" data-ready="insp">{{ $ins['v'] }}</span>@endif
  {{-- ٢٦-٨ (قرار ٦٧): «نماذج الفحص» كلها خرجت من «أريد أن» — بابها من داخل ملف المكان --}}
  @if($ui)<a class="btn btn-o btn-sm ms-auto" href="{{ route('app.inspections') }}">النماذج العشرة</a>@endif</h2>
<div class="d-flex flex-wrap gap-2 mb-2" id="pfActs">
  @if($ui)@foreach($forms as $f)<a class="btn btn-g btn-sm" href="/{{ $f['file'] }}"><i class="bi bi-clipboard-check"></i> {{ str_contains($f['key'], 'fire') ? 'فحص أنظمة الحريق' : (str_contains($f['key'], 'center') ? 'فحص الجاهزية' : 'نموذج الفحص') }}</a>@endforeach
  @else<span class="btn btn-o btn-sm pf-dim" title="لفني المكان ومدير المرافق ومسؤول السلامة"><i class="bi bi-clipboard-check"></i> نموذج الفحص <span class="small">— لفني المكان</span></span>@endif
</div>
@if(!count($systems))
  <div class="card mb-3"><div class="card-body small text-muted py-2">لا بيانات — افتح نموذج الفحص وسجّل جولة.</div></div>
@else
  <div class="d-grid gap-2 mb-3" id="pfSystems">
    @foreach($systems as $s)
      {{-- ١٩-٢: الصف يفتح ملف النظام (جولاته وبنوده وبلاغاته) --}}
      <a class="pf-sys text-decoration-none text-reset" data-sys="{{ $s['k'] }}" data-st="{{ $s['st'] }}" href="{{ route('app.places.units.system', [$place, $s['form']['key'], $s['k']]) }}">
        <div class="nm"><b>{{ $s['name'] }}</b><small>{{ $s['code'] ? 'النظام '.$s['code'] : '' }}</small></div>
        <div><small>آخر فحص</small>{{ $s['last']['d'] ?? 'لم يُفحص' }}@if($s['last']) <small>{{ $s['last']['ok'] ?? 0 }}✓ {{ !empty($s['last']['no']) ? $s['last']['no'].'✗' : '' }}</small>@endif</div>
        <div><small>المستحق القادم{{ $s['freq'] ? ' ('.$s['freq'].')' : '' }}</small>{{ $s['next'] ?? ($s['days'] ? 'الآن' : '—') }}</div>
        <span class="badge {{ $s['open'] ? 'text-bg-danger' : 'text-bg-light border' }}" title="بلاغات مفتوحة">{{ $s['open'] }}</span>
        <span class="badge {{ $ST[$s['st']] }}">{{ $R::STATE_LABELS[$s['st']] }}</span>
      </a>
    @endforeach
  </div>
@endif
</section>

{{-- ٤. مخاطر المكان المفعّلة بمنسقها ومعالجها — لمن يملك السجل؛ وإلا باهت باسم صاحبه --}}
<section class="pf-sec" id="pfSecRisks" hidden>
<h2 class="sec-h {{ $canRisks ? '' : 'pf-dim' }}"><span class="pf-n">٤</span><i class="bi bi-lightning-charge"></i> مخاطر المكان
  @if($canRisks)<span class="badge text-bg-dark">{{ $risksTotal }}</span><a class="btn btn-o btn-sm ms-auto" href="{{ route('risk.active.index') }}?place={{ $place->code }}">السجل كاملاً</a>
  @else<span class="small text-muted fw-normal">— لمنسق السلامة ومديري الإدارات ومسؤول السلامة</span>@endif</h2>
@if($canRisks)
  <div class="d-grid gap-1 mb-3">
    @forelse($risks as $rk)
      <div class="card" data-risk="{{ $rk->code }}"><div class="card-body py-2 small d-flex flex-wrap align-items-center gap-2">
        <div class="flex-grow-1"><b>{{ $rk->code }}</b> · {{ $rk->title }}
          <div class="text-muted">الدرجة {{ $rk->risk_score }} · المنسق: {{ $rk->assignedCoordinator?->name ?? '—' }} · المعالج: {{ $rk->assignedFieldTeam?->name ?? '—' }}</div></div>
      </div></div>
    @empty
      <div class="card"><div class="card-body small text-muted py-2">لم يُفعَّل خطر لهذا المكان بعد.</div></div>
    @endforelse
  </div>
@else
  <div class="mb-3"></div>
@endif
</section>

{{-- ٥. المفتوح الآن: الأرقام الأربعة، بلاغات الفحص ومتأخرها، بلاغات الشاغلين، الحالة الطارئة --}}
<section class="pf-sec" id="pfSecNow" hidden>
<h2 class="sec-h"><span class="pf-n">٥</span><i class="bi bi-activity"></i> المفتوح الآن</h2>
@php($KL = ['open' => 'بلاغ فحص مفتوح', 'overdue' => 'متجاوز المهلة', 'cat-a' => 'فئة أ — حماية معطّلة', 'closed' => 'عطل أُصلح وأُغلق'])
<div class="tiles mb-2" id="pfKpi">
  @foreach($kpi as $k => $v)
    <div class="card tile" data-kpi="{{ $k }}" data-v="{{ $v }}"><span class="n {{ $v && in_array($k, ['overdue', 'cat-a']) ? 'text-danger' : '' }}">{{ $v }}</span><span class="lbl">{{ $KL[$k] }}</span></div>
  @endforeach
</div>
@foreach($openEmergency as $em)
  <div class="alert alert-{{ $em->status === 'contained' ? 'warning' : 'danger' }} d-flex align-items-center gap-3 py-2 mb-2" data-open-emergency="{{ $em->incident_code }}">
    <i class="bi bi-broadcast fs-4"></i>
    <div class="flex-grow-1"><b>{{ $em->is_drill ? 'تمرين' : 'حالة طارئة' }} {{ $em->getStatusLabel() }}:</b> {{ $em->incident_code }} — {{ $em->getTypeLabel() }} <span class="small text-muted">· منذ {{ $em->triggered_at->diffForHumans(null, true) }}</span></div>
    @if($canEmergency)<a class="btn btn-light btn-sm" href="{{ route('emergency.incidents.live', $em) }}">التتبع</a>@else<span class="small">شاشتك من الزر الأحمر</span>@endif
  </div>
@endforeach
<h3 class="sec-h mb-2"><i class="bi bi-clipboard-x"></i> بلاغات الفحص المفتوحة <span class="badge {{ $kpi['overdue'] ? 'text-bg-danger' : 'text-bg-dark' }}">{{ count($open) }}</span></h3>
<div class="d-grid gap-2 mb-3" id="pfReports">
  @forelse($open as $r)
    @php($over = $R::overdueHours($r))
    <div class="card task {{ $over !== null && $over > 0 ? 'task-late' : '' }}" data-unit="{{ $r['unit'] ?? '' }}"><div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
      <div class="flex-grow-1"><b>{{ $r['id'] ?? $r['row'] ?? '' }}</b> · {{ $r['sys'] ?? '' }}@if(!empty($r['unit'])) · {{ $r['unit'] }}@endif<div class="small">{{ $r['item'] ?? '' }}</div>
        <div class="small text-muted">@if($over !== null && $over > 0)<span class="badge st-late">متأخر</span> @endif المهلة: {{ $r['due'] ?? '—' }} · عند المستوى {{ $R::holder($r) }}</div></div>
      @if($ui)<a class="btn btn-o btn-sm" href="/{{ $r['_form']['file'] }}#open={{ rawurlencode((string) ($r['row'] ?? '')) }}">افتحه</a>@endif
    </div></div>
  @empty
    <div class="card"><div class="card-body small text-muted py-2"><i class="bi bi-check-circle text-success"></i> لا بلاغات فحص مفتوحة.</div></div>
  @endforelse
</div>
<h3 class="sec-h mb-2"><i class="bi bi-megaphone-fill"></i> بلاغات الشاغلين المفتوحة <span class="badge text-bg-dark">{{ $incidents->count() }}</span>
  @if($canIncidents)<a class="btn btn-o btn-sm ms-auto" href="{{ route('incidents.index') }}?place={{ $place->code }}">سجل بلاغات المكان</a>@endif</h3>
<div class="d-grid gap-2 mb-3" id="pfIncidents">
  @forelse($incidents as $i)
    <div class="card" data-unit="{{ $i->placeUnit?->name ?? '' }}"><div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
      <div class="flex-grow-1"><b>{{ $i->code }}</b> · {{ $i->title }}@if($i->placeUnit) · {{ $i->placeUnit->type_label }} {{ $i->placeUnit->name }}@endif
        <div class="small text-muted">{{ $i->status_label }} · {{ $i->created_at?->diffForHumans() }}</div></div>
      @if($canIncidents)<a class="btn btn-o btn-sm" href="{{ route('incidents.show', $i) }}">افتحه</a>@endif
    </div></div>
  @empty
    <div class="card"><div class="card-body small text-muted py-2"><i class="bi bi-check-circle text-success"></i> لا بلاغات شاغلين مفتوحة.</div></div>
  @endforelse
</div>
</section>

{{-- ٧. الفريق الأولي بهواتفه (وفي القاعات فرق الفعاليات) ورقم المركز --}}
<section class="pf-sec" id="pfSecTeam" hidden>
<h2 class="sec-h"><span class="pf-n">٧</span><i class="bi bi-people-fill"></i> الفريق الأولي @if($tm)<span class="small text-muted fw-normal">{{ $tm['v'] }}</span>@endif</h2>
@php($UST = \App\Modules\Emergency\Services\PlaceProfile::UST)
@php($ROLES = \App\Modules\Emergency\Services\PlaceProfile::TEAM)
@php($TAG = ['ok' => 'st-ok', 'warn' => 'st-wait', 'bad' => 'text-bg-secondary'])
{{-- ١٩-٦ (قرار ٤٩): رقم مركز السلامة مع الفريق — اتصال بضغطة --}}
@php($centerPhone = \App\Modules\Governance\Models\Place::CENTER_PHONE)
<div class="card mb-2" id="pfCenter"><div class="card-body py-2 d-flex flex-wrap align-items-center gap-2 small">
  <span><i class="bi bi-broadcast text-g"></i> <b>مركز السلامة</b> — لأي حالة عاجلة</span>
  <a class="btn btn-g btn-sm ms-auto" href="tel:{{ $centerPhone }}"><i class="bi bi-telephone-fill"></i> <span dir="ltr">{{ $centerPhone }}</span></a>
</div></div>
@if($events)
  {{-- ١٩-٥: القاعات — قاعدة ثابتة، وفريق يُكلَّف قبل كل فعالية (إدارة القاعات ترشّح ورئيس الأمن والسلامة يعتمد) --}}
  <div class="card mb-2"><div class="card-body py-2 small"><b>قاعدة القاعات</b>
    <div class="text-muted">المحاضر منسق ومسعف لقاعته (بطاقة ٦) · أفراد الأمن القريبون منقذ وإطفائي للقاعات (بطاقة ٥) — لا ترشيح أسماء لكل قاعة.<br>وفي الفعاليات الكبرى فريق يُكلَّف قبل كل فعالية: إدارة القاعات ترشّحه، ورئيس الأمن والسلامة يعتمده.</div></div></div>
  <h3 class="sec-h mb-2" id="pfEvents"><i class="bi bi-calendar-event"></i> فرق الفعاليات <span class="badge text-bg-dark">{{ count($events['up']) }}</span>
    <span class="small text-muted fw-normal">القادمة{{ count($events['past']) ? ' · و'.count($events['past']).' منتهية' : '' }}</span>
    @if($can['events'])<a class="btn btn-g btn-sm ms-auto" href="{{ route('app.places.team.event.edit', [$place, 'new']) }}">فعالية جديدة</a>@endif</h3>
  <div class="d-grid gap-2 mb-3">
    @forelse(array_merge($events['up'], $events['past']) as $r)
      @php($e = $r['e'])
      @php($evUrl = route('app.places.team.event.edit', [$place, $r['i']], false))
      <div class="pf-team {{ $r['past'] ? 'bad' : $r['st'][0] }}" data-event="{{ $r['i'] }}">
        <div class="d-flex flex-wrap align-items-center gap-2">
          <b>{{ ($e['name'] ?? '') ?: 'فعالية بلا اسم' }}</b>
          <span class="badge {{ $r['past'] ? 'text-bg-secondary' : $TAG[$r['st'][0]] }}">{{ $r['past'] ? 'منتهية' : $r['st'][1] }}</span>
        </div>
        <div class="small text-muted">{{ !empty($e['date']) ? $e['date'] : 'بلا تاريخ' }}{{ !empty($e['nom']['by']) ? ' · كلّفها '.$e['nom']['by'] : '' }}{{ !empty($e['appr']['date']) ? ' · اعتمده '.($e['appr']['by'] ?? '').' '.$e['appr']['date'] : '' }}</div>
        <div class="small mt-1">
          @foreach($ROLES as $j => $role){{ $j ? ' · ' : '' }}{{ $role }}: @if(!empty($e['team'][$j]['name']))<b>{{ $e['team'][$j]['name'] }}</b>@else<i class="text-muted">—</i>@endif @endforeach
          @if($r['named'] && $r['named'] < 4)<span style="color:#b8860b">({{ $r['named'] }} من ٤)</span>@endif
        </div>
        @if($can['events'] || $can['eventApprove'])
          <div class="d-flex flex-wrap gap-2 mt-2">
            @if($can['eventApprove'] && empty($e['appr']['date']) && !empty($e['nom']['date']))
              <form method="post" action="{{ $evUrl }}/approve" onsubmit="return confirm('اعتماد فريق هذه الفعالية؟')">@csrf<button class="btn btn-g btn-sm">اعتماد</button></form>@endif
            @if($can['events'])<a class="btn btn-o btn-sm" href="{{ $evUrl }}">تعديل</a>
              <form method="post" action="{{ $evUrl }}" onsubmit="return confirm('حذف الفعالية وفريقها؟')">@csrf @method('DELETE')<button class="btn btn-outline-danger btn-sm">حذف</button></form>@endif
          </div>
        @endif
      </div>
    @empty
      <div class="card"><div class="card-body small text-muted py-2">لا فعالية قادمة مسجّلة.</div></div>
    @endforelse
  </div>
@else
  {{-- ١٩-٥: الفريق الأولي — ترشيح مدير الإدارة ← اعتماد مدير الشؤون الإدارية والهندسية ← إحالة للموارد البشرية؛ فريق لكل ٢٥ موظفاً --}}
  <h3 class="sec-h mb-2" id="pfTeams"><i class="bi bi-people-fill"></i> {{ count($teamUnits) > 1 ? 'الفرق الأولية بحسب الإدارة' : 'الفريق الأولي' }}
    @if(count($teamUnits) > 1)<span class="badge text-bg-dark">{{ count($teamUnits) }}</span>@endif</h3>
  <div class="d-grid gap-2 mb-3">
    @forelse($teamUnits as $row)
      @php($un = $row['un'])
      <div class="card" data-unit-team="{{ $un['uid'] }}"><div class="card-body py-2">
        <div class="d-flex flex-wrap align-items-center gap-2">
          <b>{{ $un['label'] }}</b>
          @if(count($teamUnits) > 1 || count($row['teams']) > 1)<span class="badge {{ $TAG[$UST[$row['state']][0]] }}">{{ $UST[$row['state']][1] }}</span>@endif
        </div>
        <div class="small text-muted">{{ $un['mgr'] ? 'مديرها: '.$un['mgr'].' · ' : '' }}{{ $row['staff'] ? $row['staff'].' موظفاً ← المطلوب '.$row['need'].' (فريق لكل ٢٥ موظفاً)' : 'لم يُسجَّل عدد الموظفين — فريق واحد حتى يُسجَّل' }}</div>
        @if($row['can'])
          <details class="mt-1"><summary class="small" style="cursor:pointer;color:#0f4c3a">{{ $row['staff'] ? 'تعديل عدد الموظفين' : 'تسجيل عدد الموظفين' }}</summary>
            <form method="post" action="{{ route('app.places.team.staff', [$place, $un['uid']]) }}" class="d-flex gap-2 mt-1" style="max-width:280px">@csrf
              <input type="number" min="0" inputmode="numeric" class="form-control form-control-sm" name="staff" value="{{ $row['staff'] ?: '' }}" placeholder="عدد الموظفين في المكان"><button class="btn btn-g btn-sm">حفظ</button></form></details>
        @endif
        @foreach($row['teams'] as $tmr)
          @php($t = $tmr['t'])
          @php($base = route('app.places.team.edit', [$place, $un['uid'], $tmr['k']], false))
          <div class="pf-team {{ $UST[$tmr['st']][0] }} mt-2" data-team="{{ $un['uid'] }}:{{ $tmr['k'] }}" data-st="{{ $tmr['st'] }}">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
              @if(count($row['teams']) > 1)<b class="small">الفريق {{ $tmr['k'] + 1 }} من {{ count($row['teams']) }}</b>@if($tmr['extra'])<span class="small text-muted">زائد عن المطلوب</span>@endif @endif
              <span class="badge {{ $TAG[$UST[$tmr['st']][0]] }}">{{ $UST[$tmr['st']][1] }}</span>
              @if($tmr['named'] && $tmr['named'] < 4)<span class="small" style="color:#b8860b">({{ ['', '١', '٢', '٣'][$tmr['named']] }} من ٤)</span>@endif
            </div>
            <div class="pf-members">
              @foreach($ROLES as $i => $role)
                @php($m = $t['team'][$i] ?? [])
                <div class="pf-m"><div class="small text-muted">{{ $role }}</div>
                  @if(!empty($m['name']))<b>{{ $m['name'] }}</b>
                    @if(!empty($m['phone']))<div><a href="tel:{{ preg_replace('/[^0-9+]/', '', $m['phone']) }}" class="text-decoration-none"><i class="bi bi-telephone-fill"></i> {{ $m['phone'] }}</a></div>@endif
                    <div class="small text-muted">{{ $m['dept'] ?? '' }} @if(!empty($m['trained']))تدريب {{ $m['trained'] }}{{ !empty($m['trainer']) ? ' · '.$m['trainer'] : '' }}@else<span style="color:#b8860b">لم يُدرَّب</span>@endif</div>
                  @else<span class="text-muted">لم يُسمَّ بعد</span>@endif
                </div>
              @endforeach
            </div>
            {{-- مسار الخطوات الثلاث --}}
            <div class="pf-steps small">
              <span class="{{ !empty($t['nom']['date']) ? 'done' : 'cur' }}">١ ترشيح مدير الإدارة @if(!empty($t['nom']['date']))<small>{{ $t['nom']['by'] ?? '' }} · {{ $t['nom']['date'] }}</small>@endif</span>
              <span class="{{ !empty($t['appr']['date']) ? 'done' : (!empty($t['nom']['date']) ? 'cur' : '') }}">٢ اعتماد مدير الشؤون الإدارية والهندسية @if(!empty($t['appr']['date']))<small>{{ $t['appr']['date'] }}</small>@elseif(!empty($t['nom']['date']))<small>بانتظار الاعتماد</small>@endif</span>
              <span class="{{ !empty($t['hr']['date']) ? 'done' : (!empty($t['appr']['date']) ? 'cur' : '') }}">٣ إحالة للموارد البشرية @if(!empty($t['hr']['date']))<small>{{ $t['hr']['date'] }}</small>@elseif(!empty($t['appr']['date']))<small>بانتظار الإحالة</small>@endif</span>
            </div>
            @if($tmr['edit'] || $tmr['approve'] || $tmr['refer'])
              <div class="d-flex flex-wrap gap-2 mt-2">
                @if($tmr['approve'])<form method="post" action="{{ $base }}/approve" onsubmit="return confirm('اعتماد ترشيح الفريق الأولي؟')">@csrf<button class="btn btn-g btn-sm">اعتماد</button></form>@endif
                @if($tmr['refer'])<form method="post" action="{{ $base }}/refer" onsubmit="return confirm('تسجيل إحالة التكليف إلى إدارة الموارد البشرية بتاريخ اليوم؟')">@csrf<button class="btn btn-g btn-sm">تسجيل الإحالة</button></form>@endif
                @if($tmr['edit'])<a class="btn {{ $tmr['st'] === 'none' ? 'btn-g' : 'btn-o' }} btn-sm" href="{{ $base }}">{{ $tmr['edit'] }}</a>@endif
              </div>
            @endif
          </div>
        @endforeach
      </div></div>
    @empty
      <div class="card"><div class="card-body small text-muted py-2">لا إدارات مسجّلة في هذا المكان.</div></div>
    @endforelse
  </div>
@endif
</section>

<script>
(function(){
  // ٢٦-٦-ب: الزر يفتح قسمه في مكانه (ضغطة فتح، ضغطة إغلاق)، والرابط بمرساة داخل قسم (#pfReports, #pfSystems, #pfTeams…) يفتح قسمه وحده
  var grid=document.getElementById('pfGrid'); if(!grid) return;
  function toggle(b, open){ var sec=document.getElementById(b.getAttribute('aria-controls')); if(!sec) return false;
    var o = (open===undefined) ? sec.hidden : open; sec.hidden=!o; b.classList.toggle('open', o); b.setAttribute('aria-expanded', o?'true':'false'); return o; }
  grid.querySelectorAll('button.pf-btn[aria-controls]').forEach(function(b){ b.addEventListener('click', function(){
    if(toggle(b)) document.getElementById(b.getAttribute('aria-controls')).scrollIntoView({behavior:'smooth', block:'start'}); }); });
  function openHash(){ if(!location.hash || location.hash.length<2) return; var h=document.getElementById(location.hash.slice(1)); if(!h) return;
    var sec=h.closest('.pf-sec'); if(sec){ var b=grid.querySelector('[aria-controls="'+sec.id+'"]'); if(b) toggle(b, true); h.scrollIntoView(); } }
  openHash(); window.addEventListener('hashchange', openHash);
  // سطر الوحدات: يحصر بلاغات الفحص وبلاغات الشاغلين (وفرق الإدارات في المكاتب)، ويعيد عدّ زر «المفتوح الآن»
  var nowS=document.querySelector('#pfNow .s'), nowAll=nowS?nowS.textContent:'';
  function applyUnit(name, code){
    document.querySelectorAll('#pfReports [data-unit], #pfIncidents [data-unit]').forEach(function(el){ el.hidden = !!name && el.getAttribute('data-unit')!==name; });
    document.querySelectorAll('[data-unit-team]').forEach(function(el){ el.hidden = !!code && el.getAttribute('data-unit-team')!==code; });
    if(nowS){ if(!name) nowS.textContent=nowAll; else { var n=document.querySelectorAll('#pfReports [data-unit]:not([hidden]), #pfIncidents [data-unit]:not([hidden])').length; nowS.textContent = n ? n+' مفتوح في '+name : 'لا شيء مفتوح في '+name; } }
    var info=document.getElementById('pfUnitInfo'); if(info){ var el=name ? document.querySelector('button[data-unit-filter][data-unit="'+name.replace(/"/g,'\\"')+'"]') : null; var t=el?(el.getAttribute('title')||''):''; info.hidden=!t; info.textContent=t; }
  }
  document.querySelectorAll('button[data-unit-filter]').forEach(function(c){ c.addEventListener('click', function(){
    document.querySelectorAll('button[data-unit-filter]').forEach(function(x){ x.classList.remove('on'); }); c.classList.add('on');
    applyUnit(c.getAttribute('data-unit')||'', c.getAttribute('data-unit-code')||''); }); });
  var sel=document.getElementById('pfUnitSelect'); if(sel) sel.addEventListener('change', function(){ var o=sel.options[sel.selectedIndex]; applyUnit(o.value||'', o.getAttribute('data-unit-code')||''); });
})();
</script>
@endsection
