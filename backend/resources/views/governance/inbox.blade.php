@extends('layouts.app')
@section('title', 'ما ينتظرك الآن')
@section('content')
{{-- المرحلة ١١-٢ (قرار ٣٤): شاشة واحدة — سؤال وزر لكل بند. لا يحتاج المستخدم أن يتعلم شيئاً. --}}
{{-- المرحلة ١٣-٢ (قرار ٣٩): خمسة أجزاء بالترتيب — أرقام كبيرة · رسم وخريطة · ما ينتظرك · أريد أن… · آخر الإجراءات. الأرقام لمن يملك report.view. --}}
<div class="d-flex align-items-center gap-2 flex-wrap mb-2">
  <h1 class="page-h m-0" id="inboxTitle">
    @if($tasks->isEmpty()) لا شيء ينتظرك الآن
    @else عندك <span id="inboxCount">{{ $tasks->count() }}</span> {{ $tasks->count() === 1 ? 'شيء ينتظرك' : ($tasks->count() === 2 ? 'شيئان ينتظرانك' : 'أشياء تنتظرك') }}
    @endif
  </h1>
  <span class="small text-muted">{{ auth()->user()->name }} · {{ auth()->user()->roleName() }}@if($overview) · الشهر الجاري@endif</span>
</div>
<p class="small text-muted mb-3" id="inboxHint">كل ما يحتاجك يظهر هنا. لا تبحث عنه. <a href="#" id="inboxHintHide" class="text-muted">فهمت</a></p>
{{-- المرحلة ١٨-١ (ز، قرار ٤٦): بلا رقم للمهلة لا «متأخر» ولا تصعيد آلي — الرقم يُدخله مسؤول السلامة بيده --}}
@if(!empty($deadlinesUnset))
  <div class="alert alert-warning py-2 small mb-3 d-flex flex-wrap align-items-center gap-2" id="deadlineUnset">
    <i class="bi bi-hourglass"></i>
    <span class="flex-grow-1">لم تُضبط مهلة بلاغ الشاغل ({{ implode('، ', $deadlinesUnset) }}) — بلا رقم لا يظهر «متأخر» ولا يُصعَّد شيء آلياً.</span>
    <a class="btn btn-sm btn-o" href="{{ route('incidents.settings') }}">اضبط المهل</a>
  </div>
@endif

@if($overview)
  @php
    $rg = $overview['response'];
  @endphp
  {{-- ١. الأرقام الكبيرة — كل رقم يفتح مصدره --}}
  <div class="tiles mb-3" id="tiles">
    <a class="card tile" href="#inboxList" data-tile="waiting"><span class="lbl">ينتظرك الآن</span><span class="n">{{ $overview['waiting'] }}</span><span class="lbl">{{ $overview['waiting'] ? 'قرارات تحتاجك' : 'لا شيء يحتاجك' }}</span></a>
    <a class="card tile" href="{{ route('incidents.index') }}" data-tile="pending"><span class="lbl">بلاغات شاغلين قبل استلام الفني</span><span class="n">{{ $rg['incident']['pending'] }}</span><span class="lbl">{{ $rg['incident']['pending'] ? 'بانتظار فني' : 'كلها استُلمت' }}</span></a>
    <a class="card tile" href="{{ route('emergency.incidents.index') }}" data-tile="unack"><span class="lbl">حالات طارئة بلا إقرار</span><span class="n {{ $rg['emergency']['unacknowledged'] ? 'text-danger' : '' }}">{{ $rg['emergency']['unacknowledged'] }}</span><span class="lbl">{{ $rg['emergency']['unacknowledged'] ? 'يحتاج إقراراً الآن' : 'لا شيء معلّق' }}</span></a>
    <a class="card tile" href="{{ route('reports.dashboard') }}" data-tile="gap">
      <span class="lbl">فجوة الاستجابة</span>
      @if($rg['incident']['avg_minutes'] === null)
        <span class="n text-muted" style="font-size:1.4rem">لا بيانات</span><span class="lbl">لا بلاغ استُلم هذا الشهر</span>
      @else
        <span class="n">{{ $rg['incident']['avg_minutes'] }} <small>دقيقة</small></span><span class="lbl">من البلاغ إلى استلام الفني · {{ $rg['incident']['count'] }} {{ $rg['incident']['count'] === 1 ? 'بلاغ' : 'بلاغات' }}</span>
      @endif
    </a>
  </div>

  {{-- ٢. الرسم والخريطة — على الجوال خلف «التفاصيل» --}}
  <button class="btn btn-o w-100 d-md-none mb-3" type="button" data-bs-toggle="collapse" data-bs-target="#homeDetails" id="homeDetailsToggle"><i class="bi bi-bar-chart"></i> الرسم وخريطة الأماكن <i class="bi bi-chevron-down small"></i></button>
  <div class="collapse d-md-block mb-3" id="homeDetails">
    <div class="row g-3">
      <div class="col-md-7">
        <div class="card h-100"><div class="card-body">
          <h2 class="sec-h"><i class="bi bi-bar-chart"></i> بلاغات الشاغلين شهراً بشهر</h2>
          @php
            $months = ['01' => 'يناير', '02' => 'فبراير', '03' => 'مارس', '04' => 'أبريل', '05' => 'مايو', '06' => 'يونيو', '07' => 'يوليو', '08' => 'أغسطس', '09' => 'سبتمبر', '10' => 'أكتوبر', '11' => 'نوفمبر', '12' => 'ديسمبر'];
            $bm = $overview['by_month'];
            $max = count($bm) ? max(array_column($bm, 'count')) : 0;
            $w = 600; $h = 190; $base = 160; $n = max(count($bm), 1); $slot = ($w - 40) / $n; $bw = min(28, $slot * 0.5);
          @endphp
          @if(!count($bm))
            <p class="text-muted small m-0" id="trendEmpty">لا بلاغات في الأشهر الستة الأخيرة.</p>
          @else
          <svg viewBox="0 0 {{ $w }} {{ $h }}" width="100%" role="img" aria-label="بلاغات الشاغلين شهراً بشهر" id="trend">
            <line x1="20" y1="{{ $base }}" x2="{{ $w - 20 }}" y2="{{ $base }}" stroke="#d9e2de"></line>
            @foreach($bm as $i => $m)
              @php
                $x = 20 + $slot * $i + ($slot - $bw) / 2;
                $bh = $max ? max(4, round($m['count'] / $max * ($base - 30))) : 4;
                $last = $i === count($bm) - 1;
                $mm = substr($m['label'], 5, 2);
              @endphp
              <g data-month="{{ $m['label'] }}" data-count="{{ $m['count'] }}">
                <rect x="{{ $x }}" y="{{ $base - $bh }}" width="{{ $bw }}" height="{{ $bh }}" rx="4" fill="{{ $last ? '#d9b25a' : '#0f4c3a' }}"><title>{{ $months[$mm] ?? $m['label'] }}: {{ $m['count'] }}</title></rect>
                <rect x="{{ $x }}" y="{{ $base - 4 }}" width="{{ $bw }}" height="4" fill="{{ $last ? '#d9b25a' : '#0f4c3a' }}"></rect>
                @if($last || $m['count'] === $max)<text x="{{ $x + $bw / 2 }}" y="{{ $base - $bh - 8 }}" font-size="14" font-weight="700" text-anchor="middle" fill="#1a2a24">{{ $m['count'] }}</text>@endif
                <text x="{{ $x + $bw / 2 }}" y="{{ $base + 20 }}" font-size="12" text-anchor="middle" fill="{{ $last ? '#1a2a24' : '#6b7a74' }}" font-weight="{{ $last ? '700' : '400' }}">{{ $months[$mm] ?? $m['label'] }}</text>
              </g>
            @endforeach
          </svg>
          @endif
        </div></div>
      </div>
      <div class="col-md-5">
        <div class="card h-100"><div class="card-body">
          <h2 class="sec-h"><i class="bi bi-geo-alt"></i> الأماكن التسعة · بلاغات الشهر</h2>
          @php
            $pmax = max(array_column($overview['by_place'], 'incidents') ?: [0]);
          @endphp
          <div class="places" id="places">
            @foreach($overview['by_place'] as $p)
              @php
                $c = $p['incidents'];
                $lvl = !$c ? 0 : ($c >= $pmax ? 3 : ($c >= $pmax / 2 ? 2 : 1));
              @endphp
              <div class="place lvl{{ $lvl }}" data-place="{{ $p['code'] }}" data-count="{{ $c }}" title="{{ $p['name'] }}: {{ $c }} بلاغ هذا الشهر{{ $p['emergency'] ? '، '.$p['emergency'].' حالة طارئة' : '' }}"><span class="pn">{{ $p['name'] }}</span><span class="pc">{{ $c }}</span></div>
            @endforeach
          </div>
        </div></div>
      </div>
    </div>
  </div>
@endif

{{-- ٣. ما ينتظرك --}}
@if($tasks->isEmpty())
  <div class="card mb-3"><div class="card-body text-center py-5 text-muted">
    <i class="bi bi-check-circle fs-1 text-success d-block mb-2"></i>
    لا شيء ينتظر قرارك. حين يحتاجك شيء يظهر هنا، ويصلك إشعار به. وما تريد أن تبدأه بنفسك تجده تحت «أريد أن…».
  </div></div>
@else
  {{-- قرار المستخدم ٢٠٢٦-٠٩-١٣: لا خلط — كل نوع في قسمه بأيقونته وعدّه. بلاغات الشاغلين ≠ بلاغات الفحص الفني --}}
  @php
    $ICONS = ['بلاغات الشاغلين' => 'bi-megaphone-fill', 'بلاغات الفحص' => 'bi-clipboard-check', 'جولات الفحص' => 'bi-calendar-check', 'الطوارئ' => 'bi-broadcast', 'التصاريح' => 'bi-file-earmark-check', 'المخاطر' => 'bi-lightning-charge', 'النماذج' => 'bi-ui-checks', 'المقاولون' => 'bi-buildings'];
    $ORDER = array_keys($ICONS);
    $groups = $tasks->groupBy('module')->sortBy(fn ($g, $m) => array_search($m, $ORDER) === false ? 99 : array_search($m, $ORDER));
  @endphp
  @if($overview)<h2 class="sec-h sec-h-lg mb-2"><i class="bi bi-inbox-fill"></i> ما ينتظرك</h2>@endif
  <div class="d-grid gap-3 mb-3" id="inboxList">
  @foreach($groups as $module => $items)
    <section data-module="{{ $module }}">
      <h3 class="sec-h mb-2"><i class="bi {{ $ICONS[$module] ?? 'bi-dot' }} text-g"></i> {{ $module }} <span class="badge text-bg-dark">{{ $items->count() }}</span></h3>
      <div class="d-grid gap-2">
    @foreach($items as $t)
      <div class="card task {{ $t->isOverdue ? 'task-late' : '' }}" data-task="{{ $t->key }}">
        <div class="card-body py-3 d-flex flex-wrap align-items-center gap-3">
          <div class="flex-grow-1" style="min-width:220px">
            <div class="fw-bold">{{ $t->question }}</div>
            <div class="small text-muted mt-1">
              @if($t->isOverdue)<span class="badge st-late">متأخر</span>
              @elseif($t->dueAt)<span class="badge st-wait">المهلة {{ $t->dueAt->format('m/d H:i') }}</span>@endif
              @if($t->createdAt) · {{ $t->createdAt->diffForHumans() }}@endif
            </div>
          </div>
          <div class="task-actions">
            @if($t->primaryMethod() === 'POST')
              <form method="post" action="{{ $t->primary['url'] }}" class="m-0">@csrf<button class="btn btn-g">{{ $t->primary['label'] }}</button></form>
            @else
              <a class="btn btn-g" href="{{ route('app.inbox.open', ['url' => $t->primary['url']]) }}" data-target="{{ $t->primary['url'] }}">{{ $t->primary['label'] }}</a>
            @endif
            @if($t->secondary)
              @if($t->secondaryMethod() === 'POST')
                <form method="post" action="{{ $t->secondary['url'] }}" class="m-0">@csrf<button class="btn btn-o">{{ $t->secondary['label'] }}</button></form>
              @else
                <a class="btn btn-o" href="{{ $t->secondary['url'] }}">{{ $t->secondary['label'] }}</a>
              @endif
            @endif
          </div>
        </div>
      </div>
    @endforeach
      </div>
    </section>
  @endforeach
  </div>
@endif

{{-- ٣-ب. المرحلة ١٩-٣ (قرار ٤٨): من العمل اليومي — أين تقف بلاغات الفحص، وبلاغاتي التي قررتُ فيها ولم تُغلق --}}
@if(!empty($follow))
  @php($RD = \App\Modules\Store\Services\InspectionDocReader::class)
  <div class="card mb-3" id="flowRail"><div class="card-body">
    <h2 class="sec-h"><i class="bi bi-signpost-split"></i> أين تقف بلاغات الفحص <span class="small text-muted fw-normal">{{ $follow['rail']['open'] ? $follow['rail']['open'].' مفتوح' : 'لا بلاغات مفتوحة' }}</span></h2>
    <div class="row g-2 text-center">
      @foreach([1, 2, 3, 4] as $n)
        @php($c = $follow['rail']['cnt'][$n])
        @php($o = $follow['rail']['od'][$n])
        <div class="col-3"><div class="border rounded py-2 {{ $follow['me'] === $n ? 'border-2 border-success' : '' }} {{ $c ? 'bg-white' : 'bg-light text-muted' }}" data-lvl="{{ $n }}" data-n="{{ $c }}" data-od="{{ $o }}"@if($follow['me'] === $n) data-me="1"@endif>
          <div class="fs-4 fw-bold {{ $o ? 'text-danger' : '' }}">{{ $c }}</div>
          <div class="small">{{ $RD::LEVEL_NAMES[$n] }}@if($follow['me'] === $n) <span class="badge st-ok">أنت</span>@endif</div>
          @if($o)<div class="small text-danger">{{ $o }} متأخر</div>@endif
        </div></div>
      @endforeach
    </div>
  </div></div>
  @if($follow['hasLevel'])
  <div class="card mb-3" id="myReports"><div class="card-body">
    <h2 class="sec-h"><i class="bi bi-eye"></i> بلاغاتي <span class="badge text-bg-dark">{{ count($follow['mine']) }}</span> <span class="small text-muted fw-normal">قررتَ فيها ولم تُغلق بعد</span></h2>
    @forelse($follow['mine'] as $r)
      @php($over = $RD::overdueHours($r))
      <div class="d-flex flex-wrap align-items-center gap-2 border-top py-2">
        <div class="flex-grow-1 small"><b>{{ $r['id'] ?? $r['row'] ?? '' }}</b> · {{ $r['_form']['name'] }}@if(!empty($r['unit'])) · {{ $r['unit'] }}@endif: {{ $r['item'] ?? '' }}
          <div class="text-muted">@if($over !== null && $over > 0)<span class="badge st-late">متأخر</span> @endif عند {{ $RD::LEVEL_NAMES[$RD::holder($r)] ?? '—' }} · المهلة {{ $r['due'] ?? '—' }}</div></div>
        <a class="btn btn-o btn-sm" href="/{{ $r['_form']['file'] }}#open={{ rawurlencode((string) ($r['row'] ?? '')) }}">اعرضه</a>
      </div>
    @empty
      <div class="small text-muted">لا بلاغات قيد المتابعة عند غيرك.</div>
    @endforelse
  </div></div>
  @endif
@endif

{{-- ٤. أريد أن… --}}
@include('governance._intents', ['intents' => $intents])

{{-- ٥. آخر الإجراءات --}}
@if($recent->isNotEmpty())
<div class="card mt-4" id="recent"><div class="card-body">
  <h2 class="sec-h"><i class="bi bi-clock-history"></i> آخر الإجراءات</h2>
  <div class="recent">
    @foreach($recent as $r)
      <a class="row-act" href="{{ $r['url'] }}"><span class="when">{{ $r['at']?->isToday() ? $r['at']->format('H:i') : ($r['at']?->isYesterday() ? 'أمس' : $r['at']?->format('m/d')) }}</span><span>{{ $r['text'] }}</span></a>
    @endforeach
  </div>
</div></div>
@endif
@endsection
@push('scripts')
<script>
(function(){
  /* جملة أول فتح تُخفى بعد أن يقول «فهمت» — تفضيل لهذا المتصفح فقط */
  var h=document.getElementById('inboxHint'),b=document.getElementById('inboxHintHide');
  try{if(localStorage.getItem('ipa-inbox-hint')==='1')h.hidden=true;}catch(e){}
  b.addEventListener('click',function(e){e.preventDefault();h.hidden=true;try{localStorage.setItem('ipa-inbox-hint','1');}catch(x){}});
})();
</script>
@endpush
