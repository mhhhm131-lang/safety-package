@extends('layouts.app')
@section('title', 'ما ينتظرك الآن')
@section('content')
{{-- المرحلة ١١-٢ (قرار ٣٤): شاشة واحدة — سؤال وزر لكل بند. لا يحتاج المستخدم أن يتعلم شيئاً. --}}
{{-- المرحلة ١٣-٢ (قرار ٣٩): خمسة أجزاء بالترتيب — أرقام كبيرة · رسم وخريطة · ما ينتظرك · أريد أن… · آخر الإجراءات. الأرقام لمن يملك report.view. --}}
{{-- المرحلة ٢٥-١ (قرار ٦٤): الأماكن لكل حساب في نطاقه، مربعاتها تفتح ملف المكان؛ لا شبكة ميتة. ٢٦-١ (قرار ٦٦): الترتيب الأماكن ← الرسم ← ما ينتظرك ← أريد أن… --}}
{{-- ٢٦-١-ب (بكلمته «موافق»): عنوان الصفحة «عندك N» حُذف — «ما ينتظرك» في زر الشريط وفي عنوان القسم فقط --}}
<div class="small text-muted mb-2">{{ auth()->user()->name }} · {{ auth()->user()->roleName() }}</div>
{{-- ١٩-٦ (قرار ٤٩) ثم ٢٥-٣ (قرار ٦٥): سطر «مكاني» حُذف — مربع المكان يقوم مقامه، وهاتف المركز في بطاقة الأماكن --}}
<p class="small text-muted mb-3" id="inboxHint">كل ما يحتاجك يظهر هنا. لا تبحث عنه. <a href="#" id="inboxHintHide" class="text-muted">فهمت</a></p>
{{-- المرحلة ١٨-١ (ز، قرار ٤٦): بلا رقم للمهلة لا «متأخر» ولا تصعيد آلي — الرقم يُدخله مسؤول السلامة بيده --}}
@if(!empty($deadlinesUnset))
  <div class="alert alert-warning py-2 small mb-3 d-flex flex-wrap align-items-center gap-2" id="deadlineUnset">
    <i class="bi bi-hourglass"></i>
    <span class="flex-grow-1">لم تُضبط مهلة بلاغ الشاغل ({{ implode('، ', $deadlinesUnset) }}) — بلا رقم لا يظهر «متأخر» ولا يُصعَّد شيء آلياً.</span>
    <a class="btn btn-sm btn-o" href="{{ route('incidents.settings') }}">اضبط المهل</a>
  </div>
@endif

{{-- ٢٦-١٠ (قرار ٦٦ «أرقام واحدة»، بكلمته «ابدأ»): الأرقام الأربعة الكبيرة حُذفت — «ينتظرك» في عنوان قسمه وزر الشريط، وبلاغات الشاغلين والحالات في أعمدة الرسم،
     و«فجوة الاستجابة» أول صفحة التقارير (زرها تحت الرسم لمن يملكها) --}}
{{-- ٢. الأماكن (٢٥-١، قرار ٦٤) والرسم (٢٥-٢) — أولاً، ثم «ما ينتظرك» ثم «أريد أن…» (٢٦-١، قرار ٦٦ بكلمته): لكل حساب في نطاقه، لونه من حال الفحص؛ مكان واحد = المربع يفتح ملفه، أكثر = المربع يرشّح الرسم وزر «افتح ملف المكان» يفتحه --}}
<div class="row g-3 mb-3">
  @if(!empty($placeTiles))
  <div class="col-md-5">
    <div class="card h-100" id="placesCard"><div class="card-body">
      <h2 class="sec-h d-flex align-items-center gap-2 flex-wrap"><i class="bi bi-geo-alt"></i> {{ $scopeAll ? 'الأماكن (٨+١)' : (count($placeTiles) === 1 ? 'مكانك' : 'أماكنك') }} <span class="small text-muted fw-normal">اضغط المكان لملفه</span>
        <a class="btn btn-o btn-sm ms-auto" href="tel:{{ \App\Modules\Governance\Models\Place::CENTER_PHONE }}"><i class="bi bi-telephone-fill"></i> المركز <span dir="ltr">{{ \App\Modules\Governance\Models\Place::CENTER_PHONE }}</span></a></h2>
      <div class="pl-grid" id="places">
        @foreach($placeTiles as $hz => $t)
          @if($p = $placeByCode[$hz] ?? null)
          <a class="pl-tile {{ $t['cls'] }}" href="{{ $hz === 'HZ-00' ? route('emergency.dashboard') : route('app.places.units.file', $p) }}" data-place="{{ $hz }}" data-cls="{{ $t['cls'] }}" data-open="{{ $t['open'] }}" data-od="{{ $t['od'] }}" data-a="{{ $t['a'] }}"@if(count($placeTiles) > 1) data-filter="1"@endif>
            <span class="small text-muted" dir="ltr">{{ $hz }}</span>
            <span class="nm">{{ $p->name }}</span>
            <span class="small st">
              @if(!$t['has'])<span class="text-muted">لم تُفتح جولة بعد</span>
              @elseif($t['open'])<b>{{ $t['open'] }}</b> مفتوح@if($t['od']) · <b class="text-danger">{{ $t['od'] }} متجاوز</b>@endif
              @else<b class="text-success">لا شيء مفتوح</b>@endif
            </span>
          </a>
          @endif
        @endforeach
      </div>
      <p class="small text-muted mt-2 mb-0">أخضر لا شيء مفتوح · ذهبي مفتوح · أحمر متجاوز</p>
    </div></div>
  </div>
  @endif
  @if(!empty($placeTiles))
  <div class="col-md-7">
    {{-- ٢٥-٢: الرسم «حال الآن» — ستة أعمدة من مصدر واحد لنطاق الحساب، تتغيّر بالمكان المضغوط بلا طلب ثانٍ، وكل عمود يفتح قائمته --}}
    @php
      $S = \App\Modules\Governance\Services\PlaceSnapshot::class;
      $cmax = max(max($snapshot['total']), 1);
    @endphp
    <div class="card h-100" id="chart"><div class="card-body d-flex flex-column">
      <h2 class="sec-h d-flex align-items-center gap-2 flex-wrap"><i class="bi bi-bar-chart"></i> حال الآن · <span id="chartScope">{{ $scopeAll ? 'المبنى كله' : (count($placeTiles) === 1 ? reset($snapshot['places'])['name'] : 'أماكنك') }}</span>
        @if(count($placeTiles) > 1)
        <span class="ms-auto d-flex gap-1">
          <a class="btn btn-g btn-sm" id="chartFile" href="#" hidden>افتح ملف المكان</a>
          <button class="btn btn-o btn-sm" id="chartAll" type="button" hidden>{{ $scopeAll ? 'المبنى كله' : 'أماكنك' }}</button>
        </span>
        @endif
      </h2>
      <div class="bars flex-grow-1" id="bars">
        @foreach($S::KEYS as $k => [$label, $warn])
          @php
            $n = $snapshot['total'][$k];
            $href = $snapshot['links'][$k];
          @endphp
          <a class="bar {{ $n ? '' : 'zero' }} {{ $warn && $n ? 'warn' : '' }}" data-k="{{ $k }}" data-n="{{ $n }}"@if($href) href="{{ $href }}"@endif title="{{ $label }} — اضغط للقائمة"><span class="col"><span class="fill" style="height:{{ $n ? max(6, round($n / $cmax * 100)) : 3 }}%"></span></span><span class="n">{{ $n }}</span><span class="lbl">{{ $label }}</span></a>
        @endforeach
      </div>
      <p class="small text-muted mt-2 mb-0">{{ count($placeTiles) > 1 ? 'اضغط مكاناً فيتغيّر الرسم له وحده، واضغط عموداً لقائمته.' : 'اضغط عموداً لقائمته.' }}</p>
      @if($canReports)<div class="mt-2"><a class="btn btn-o btn-sm" id="reportsDoor" href="{{ route('reports.dashboard') }}" title="فجوة الاستجابة والمؤشرات والسجلات"><i class="bi bi-clipboard-data"></i> التقارير</a></div>@endif
    </div></div>
    <script type="application/json" id="snapshot">{!! json_encode(['places' => $snapshot['places'], 'total' => $snapshot['total'], 'links' => $snapshot['links'], 'keys' => array_map(fn ($v) => $v[1], $S::KEYS)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
  </div>
  @endif
</div>
{{-- ٢٦-١٠: من يملك التقارير ولا مكان في نطاقه لا رسم عنده — زره هنا حتى لا يُخفى عنه (قرار ٦١) --}}
@if($canReports && empty($placeTiles))
  <div class="mb-3"><a class="btn btn-o btn-sm" id="reportsDoor" href="{{ route('reports.dashboard') }}" title="فجوة الاستجابة والمؤشرات والسجلات"><i class="bi bi-clipboard-data"></i> التقارير</a></div>
@endif

{{-- ٣. ما ينتظرك --}}
@if($tasks->isEmpty())
  <h2 class="sec-h sec-h-lg mb-2"><i class="bi bi-inbox-fill"></i> لا شيء ينتظرك الآن</h2>
  <div class="card mb-3" id="inboxEmpty"><div class="card-body text-center py-5 text-muted">
    <i class="bi bi-check-circle fs-1 text-success d-block mb-2"></i>
    لا شيء ينتظر قرارك. حين يحتاجك شيء يظهر هنا، ويصلك إشعار به. وما تريد أن تبدأه بنفسك تجده تحت «أريد أن…».
  </div></div>
@else
  {{-- قرار المستخدم ٢٠٢٦-٠٩-١٣: لا خلط — كل نوع في قسمه بأيقونته وعدّه. بلاغات الشاغلين ≠ بلاغات الفحص الفني --}}
  @php
    $ICONS = \App\Core\Inbox\InboxService::GROUPS; // ٢٦-٨ (قرار ٦٧): المجموعات العشر نفسها في «ما ينتظرك» و«أريد أن»
    $ORDER = array_keys($ICONS);
    $groups = $tasks->groupBy('module')->sortBy(fn ($g, $m) => array_search($m, $ORDER) === false ? 99 : array_search($m, $ORDER));
  @endphp
  {{-- ٢٥-٣-ب (بكلمته «ما ينتظرك قائمة منسدلة» ثم «احذف سطر العدّادات… أيقونة ورقم وعلامة حمراء وتفتح بالنقر»): كل مجموعة سطر واحد مطويّ دائماً — الأيقونة والاسم والعدد، وبالأحمر إن فيها متأخر؛ البطاقات تنزل بالنقر؛ ومطويّة عند كل فتح للصفحة --}}
  <h2 class="sec-h sec-h-lg mb-2"><i class="bi bi-inbox-fill"></i> ما ينتظرك <span class="badge text-bg-dark" id="inboxCount">{{ $tasks->count() }}</span></h2>
  <div class="d-grid gap-2 mb-3" id="inboxList">
  @foreach($groups as $module => $items)
    @php $od = $items->where('isOverdue', true)->count(); @endphp
    <section data-module="{{ $module }}" data-n="{{ $items->count() }}" data-od="{{ $od }}">
      <button class="grp-h collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#grp-{{ $loop->index }}" aria-expanded="false" aria-controls="grp-{{ $loop->index }}"><span class="grp-ic {{ $od ? 'late' : '' }}"><i class="bi {{ $ICONS[$module] ?? 'bi-folder2' }}"></i><span class="grp-n">{{ $items->count() }}</span></span> <span class="grp-nm">{{ $module }}</span>@if($od)<span class="grp-od" title="{{ $od }} متأخر">{{ $od }} متأخر</span>@endif <i class="bi bi-chevron-down small ms-auto grp-caret"></i></button>
      <div class="collapse" id="grp-{{ $loop->index }}">
      <div class="d-grid gap-2">
    {{-- ٢٦-١١ (قرار ٦٦): المتطابقة — السؤال نفسه عن المكان نفسه ولا يميّزها إلا الرقم — بطاقة واحدة بعددها، زرها يفتح بنودها في مكانها؛ كل بند بزره كما كان، والعدّادات تعدّ الأشياء --}}
    @foreach(\App\Core\Inbox\Batch::group($items) as $e)
      @if(isset($e['task']))
      @php $t = $e['task']; @endphp
      <div class="card task {{ $t->isOverdue ? 'task-late' : '' }}" data-task="{{ $t->key }}">
        <div class="card-body py-3 d-flex flex-wrap align-items-center gap-3">
          @include('governance._task_body', ['t' => $t, 'label' => $t->question])
        </div>
      </div>
      @else
      @php $bid = 'batch-'.$loop->parent->index.'-'.$loop->index; @endphp
      <div class="card task {{ $e['late'] ? 'task-late' : '' }}" data-batch="{{ $e['batch'] }}" data-n="{{ count($e['tasks']) }}" data-od="{{ $e['late'] }}">
        <div class="card-body py-3 d-flex flex-wrap align-items-center gap-3">
          <div class="flex-grow-1" style="min-width:220px">
            <div class="fw-bold">{{ $e['question'] }}</div>
            @if($e['late'])<div class="small text-muted mt-1"><span class="badge st-late">{{ $e['late'] }} متأخر</span></div>@endif
          </div>
          <div class="task-actions"><button class="btn btn-g collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $bid }}" aria-expanded="false" aria-controls="{{ $bid }}">{{ $e['open'] }} <i class="bi bi-chevron-down small"></i></button></div>
        </div>
        <div class="collapse" id="{{ $bid }}">
          @foreach($e['tasks'] as $t)
          <div class="batch-row d-flex flex-wrap align-items-center gap-3" data-task="{{ $t->key }}">
            @include('governance._task_body', ['t' => $t, 'label' => $t->item ?? $t->question])
          </div>
          @endforeach
        </div>
      </div>
      @endif
    @endforeach
      </div>
      </div>
    </section>
  @endforeach
  </div>
@endif

{{-- ٤. المرحلة ١٩-٣ (قرار ٤٨): من العمل اليومي — أين تقف بلاغات الفحص، وبلاغاتي التي قررتُ فيها ولم تُغلق --}}
@if(!empty($follow))
  @php $RD = \App\Modules\Store\Services\InspectionDocReader::class; @endphp
  <div class="card mb-3" id="flowRail"><div class="card-body">
    <h2 class="sec-h"><i class="bi bi-signpost-split"></i> أين تقف بلاغات الفحص <span class="small text-muted fw-normal">{{ $follow['rail']['open'] ? $follow['rail']['open'].' مفتوح' : 'لا بلاغات مفتوحة' }}</span></h2>
    <div class="row g-2 text-center">
      @foreach([1, 2, 3, 4] as $n)
        @php $c = $follow['rail']['cnt'][$n]; @endphp
        @php $o = $follow['rail']['od'][$n]; @endphp
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
      @php $over = $RD::overdueHours($r); @endphp
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

{{-- ٥. أريد أن… --}}
@include('governance._intents', ['intents' => $intents])

{{-- ٦. آخر الإجراءات --}}
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
/* ٢٥-٣-ب (بكلمته «قابلة للطي تنطوي»): المجموعات مطويّة عند كل فتح — لا تذكّر */
/* ٢٥-٢: ضغطة المكان ترشّح الرسم من بيانات الصفحة نفسها (بلا طلب)؛ «المبنى كله» يعيده؛ بلا سكربت يبقى المربع رابطاً إلى ملف المكان */
(function(){
  var el=document.getElementById('snapshot'),bars=document.getElementById('bars');if(!el||!bars)return;
  var D=JSON.parse(el.textContent),scope=document.getElementById('chartScope'),all=document.getElementById('chartAll'),file=document.getElementById('chartFile');
  function draw(n,links,name,fileUrl,fileName){
    var vals=Object.keys(n).map(function(k){return n[k]}),m=Math.max.apply(null,vals.concat([1]));
    bars.querySelectorAll('.bar').forEach(function(b){
      var k=b.dataset.k,v=n[k]||0;b.dataset.n=v;b.querySelector('.n').textContent=v;
      b.querySelector('.fill').style.height=(v?Math.max(6,Math.round(v/m*100)):3)+'%';
      b.classList.toggle('zero',!v);b.classList.toggle('warn',!!(D.keys[k]&&v));
      if(links[k])b.setAttribute('href',links[k]);else b.removeAttribute('href');
    });
    scope.textContent=name;
    if(all)all.hidden=!fileUrl;if(file){file.hidden=!fileUrl;if(fileUrl){file.href=fileUrl;file.textContent=fileName||'افتح ملف المكان';}}
  }
  var base=scope.textContent;
  document.querySelectorAll('#places .pl-tile[data-filter]').forEach(function(t){
    t.addEventListener('click',function(e){
      e.preventDefault();var p=D.places[t.dataset.place];if(!p)return;
      document.querySelectorAll('#places .pl-tile.on').forEach(function(x){x.classList.remove('on')});t.classList.add('on');
      /* ٢٦-١٢: مربع المركز يفتح صفحة المركز لا ملف مكان — الزر باسم ما يفتحه */
      draw(p.n,p.links,p.name,p.file,p.code==='HZ-00'?'افتح صفحة المركز':null);
    });
  });
  if(all)all.addEventListener('click',function(){document.querySelectorAll('#places .pl-tile.on').forEach(function(x){x.classList.remove('on')});draw(D.total,D.links,base,null);});
})();
</script>
@endpush
