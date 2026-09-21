@extends('layouts.app')
@section('title', 'حالة طارئة — ماذا أفعل')

{{--
  ٢٢-٢ (د): شاشة الشخص وقت الحالة. لكل حساب، والأهم أنها للموظف الذي لا صلاحية طوارئ له.
  الترتيب بترتيب الحاجة على الجوال: ما يجري ← إلى أين أخرج ← الزرّان ← ماذا أفعل.
  كل ما فيها كان مبنياً في الخلفية بلا شاشة (جرد ٢٢-١).
--}}

@section('content')
<div class="container-fluid px-0" style="max-width:760px">

  {{-- رسائل النجاح والخطأ يعرضها اللاي أوت مرة واحدة — لا تُكرَّر هنا --}}

  @if(!$incident)
    {{-- لا حالة: الشاشة لا تسقط، وتبقى مرجعاً هادئاً --}}
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body text-center py-4">
        <i class="bi bi-shield-check display-4 text-success"></i>
        <h1 class="h4 mt-2 mb-1">لا حالة طارئة الآن</h1>
        <p class="text-muted mb-0">إن وقعت حالة في مكانك ستفتح لك هذه الصفحة بما عليك فعله.</p>
      </div>
    </div>
    @if($nearestExit || $points->isNotEmpty())
      <div class="card mb-3">
        <div class="card-header"><strong>اعرفها من الآن</strong></div>
        <ul class="list-group list-group-flush">
          @if($nearestExit)
            <li class="list-group-item"><i class="bi bi-box-arrow-right text-success"></i> <strong>أقرب مخرج لك:</strong> {{ $nearestExit->name }}@if($nearestExit->floor) — {{ $nearestExit->floor->getDisplayName() }}@endif</li>
          @endif
          @foreach($points as $p)
            <li class="list-group-item"><i class="bi bi-geo-alt-fill text-success"></i> <strong>نقطة التجمع{{ $p->is_primary ? ' الرئيسية' : '' }}:</strong> {{ $p->name }}@if($p->directions) <span class="text-muted small">— {{ $p->directions }}</span>@endif</li>
          @endforeach
        </ul>
      </div>
    @endif
    <a class="btn btn-outline-secondary w-100" href="{{ route('app.home') }}">رجوع</a>

  @else
    {{-- ١) ما يجري --}}
    <div class="card border-danger border-2 mb-3">
      <div class="card-body">
        <div class="d-flex align-items-start gap-3">
          <i class="bi bi-exclamation-octagon-fill text-danger display-5 lh-1"></i>
          <div class="flex-grow-1">
            <h1 class="h4 mb-1">
              {{ $incident->is_drill ? 'تمرين إخلاء' : $incident->getTypeLabel() }}
              @if($incident->is_drill)<span class="badge text-bg-secondary align-middle">تمرين</span>@endif
            </h1>
            <div class="text-muted">
              {{ $incident->place?->name ?? $incident->building->name }}
              · بدأت {{ $incident->triggered_at?->diffForHumans() }}
              @if($incident->status !== 'active') · <span class="text-success">تحت السيطرة</span>@endif
            </div>
            @if($incident->description)<div class="mt-1">{{ $incident->description }}</div>@endif
          </div>
        </div>
      </div>
    </div>

    {{-- ٢) إلى أين أخرج --}}
    <div class="card mb-3">
      <div class="card-header bg-light"><strong><i class="bi bi-signpost-split"></i> إلى أين تخرج</strong></div>
      <ul class="list-group list-group-flush">
        @if($nearestExit)
          <li class="list-group-item py-3">
            <div class="text-muted small">أقرب مخرج لك</div>
            <div class="fs-5 fw-bold">{{ $nearestExit->name }}</div>
            <div class="small text-muted">
              @if($nearestExit->floor){{ $nearestExit->floor->getDisplayName() }}@endif
              @if($nearestExit->direction) · جهة {{ $nearestExit->direction }}@endif
            </div>
          </li>
        @else
          <li class="list-group-item text-muted">لم تُسجَّل مخارج المبنى بعد — اتبع لوحات الإرشاد وتعليمات الفريق.</li>
        @endif
        @foreach($points as $p)
          <li class="list-group-item py-3">
            <div class="text-muted small">نقطة التجمع{{ $p->is_primary ? ' الرئيسية' : ' البديلة' }}</div>
            <div class="fs-5 fw-bold">{{ $p->name }}</div>
            @if($p->directions)<div class="small text-muted">{{ $p->directions }}</div>@endif
          </li>
        @endforeach
      </ul>
    </div>

    {{-- ٣) الزرّان — أكبر ما في الصفحة --}}
    @php $isSafe = $checkIn && $checkIn->status === \App\Modules\Emergency\Models\EvacuationCheckIn::STATUS_SAFE; @endphp
    <div class="card mb-3">
      <div class="card-body d-grid gap-2">
        @if($isSafe)
          <div class="alert alert-success mb-0 d-flex align-items-center gap-2">
            <i class="bi bi-check-circle-fill fs-4"></i>
            <div>
              <strong>أنت مسجَّل بأمان.</strong>
              @if($checkIn->assemblyPoint) في {{ $checkIn->assemblyPoint->name }}@endif
              @if($checkIn->checked_in_at) · {{ $checkIn->checked_in_at->format('H:i') }}@endif
              <div class="small">ابقَ في نقطة التجمع حتى يُعلَن انتهاء الخطر.</div>
            </div>
          </div>
        @else
          <form method="post" action="{{ route('emergency.me.check-in') }}">
            @csrf
            @if($points->count() > 1)
              <label class="form-label small text-muted mb-1">أين أنت الآن؟</label>
              <select name="assembly_point_id" class="form-select form-select-lg mb-2">
                @foreach($points as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
              </select>
            @elseif($points->isNotEmpty())
              <input type="hidden" name="assembly_point_id" value="{{ $points->first()->id }}">
            @endif
            @if($points->isNotEmpty())
              <button class="btn btn-success btn-lg w-100 py-3 fw-bold">
                <i class="bi bi-check2-circle"></i> أنا بخير — سجّل وصولي
              </button>
            @else
              <div class="alert alert-warning mb-0">لم تُسجَّل نقاط تجمع بعد، فلا يمكن تسجيل الوصول.</div>
            @endif
          </form>
        @endif

        {{-- ٢٢-٤: عضو الفريق يسجّل وصوله إلى الموقع بنفسه (كانت نية «وصلتُ» تفتح لوحة المركز) --}}
        @if($teamMember)
          @if($arrivedLog)
            <div class="alert alert-info mb-0 d-flex align-items-center gap-2">
              <i class="bi bi-geo-alt-fill fs-4"></i>
              <div><strong>وصولك إلى الموقع مسجَّل</strong>@if($arrivedLog->logged_at) · {{ $arrivedLog->logged_at->format('H:i') }}@endif</div>
            </div>
          @else
            <form method="post" action="{{ route('emergency.me.arrived') }}">
              @csrf
              <button class="btn btn-dark btn-lg w-100 py-3 fw-bold">
                <i class="bi bi-geo-alt-fill"></i> وصلتُ إلى الموقع
              </button>
            </form>
          @endif
        @endif

        @if($checkIn && $checkIn->needs_assistance)
          <div class="alert alert-warning mb-0">
            <strong>طلبك وصل.</strong> {{ $checkIn->getAssistanceTypeLabel() }}@if($checkIn->notes) — {{ $checkIn->notes }}@endif
            <div class="small">فريق الاستجابة والمركز يريانه الآن. ابقَ مكانك إن كنت آمناً.</div>
          </div>
        @elseif($helpHandled)
          {{-- ٢٢-٦: من طلب مساعدة يعرف أنها عولجت فلا يظل ينتظر --}}
          <div class="alert alert-success mb-0">
            <strong>عولج طلبك.</strong>@if($helpHandled->logged_at) · {{ $helpHandled->logged_at->format('H:i') }}@endif
            <div class="small">إن احتجت شيئاً آخر فاضغط «أحتاج مساعدة» من جديد، أو اتصل بالمركز.</div>
          </div>
        @endif

        @if(!$checkIn || !$checkIn->needs_assistance)
          <button class="btn btn-outline-danger btn-lg w-100 py-3 fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#helpForm">
            <i class="bi bi-hand-index-thumb"></i> أحتاج مساعدة
          </button>
          <div class="collapse" id="helpForm">
            <form method="post" action="{{ route('emergency.me.help') }}" class="border rounded p-3 mt-2">
              @csrf
              <label class="form-label small text-muted mb-1">ما نوع المساعدة؟</label>
              <select name="help_type" class="form-select form-select-lg mb-2">
                @foreach($helpTypes as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
              </select>
              <input name="notes" class="form-control mb-2" maxlength="500" placeholder="أين أنت بالضبط؟ (اختياري)">
              <button class="btn btn-danger btn-lg w-100 fw-bold">أرسل الطلب الآن</button>
            </form>
          </div>
        @endif
      </div>
    </div>

    {{-- ٤) ماذا أفعل — نص التعليمات نفسه الذي يصل في التنبيه، مصدر واحد --}}
    @if($instructions)
      <div class="card mb-3">
        <div class="card-header bg-light"><strong><i class="bi bi-list-check"></i> ماذا تفعل</strong></div>
        <div class="card-body fs-6">{{ $instructions }}</div>
      </div>
    @endif

    <div class="card mb-3">
      <div class="card-body d-flex flex-wrap gap-2 align-items-center">
        <span class="text-muted small">مركز السلامة</span>
        <a class="btn btn-danger" href="tel:{{ \App\Modules\Governance\Models\Place::CENTER_PHONE }}">
          <i class="bi bi-telephone-fill"></i> {{ \App\Modules\Governance\Models\Place::CENTER_PHONE }}
        </a>
        <span class="text-muted small ms-auto">اتصل إن لم تستطع استعمال هذه الصفحة.</span>
      </div>
    </div>
  @endif

</div>
@endsection
