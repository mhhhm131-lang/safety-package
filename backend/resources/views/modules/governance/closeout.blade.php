@extends('layouts.app')
@section('page_title', 'الإغلاق والتسليم')
@section('content')

<div class="mb-3">
  <h1 class="h5 m-0">الإغلاق والتسليم</h1>
  <div class="small text-muted">تُستعمل مرة واحدة عند التسليم: تعطيل الحسابات التجريبية وحذف بيانات التجربة.</div>
</div>

@if(count($unclassified))
  <div class="alert alert-warning py-2" data-unclassified="{{ count($unclassified) }}">
    جداول بلا تصنيف في خدمة الإغلاق: <span class="font-monospace">{{ implode('، ', $unclassified) }}</span>.
    صنّفها قبل الحذف حتى لا يبقى ما يجب حذفه ولا يُحذف ما يجب بقاؤه.
  </div>
@endif

{{-- ٢١-٩ (قرار ٥٤): وضع التجربة — النظام يُعبَّأ كما يجب أن يعمل، وكل ما يُنشأ أثناءه يُحذف عند إنهائه، وما قبله لا يُمس --}}
<div class="card mb-3" id="trialCard" style="border-color:#d9b25a">
  <div class="card-body">
    <h2 class="h6 mb-2"><i class="bi bi-cone-striped"></i> وضع التجربة</h2>
    @if(!$trialOn)
      <p class="small text-muted mb-2">يُعبّئ النظام كله بحسابات وفرق وسجلات فعلية تجريبية لتجرّبه كاملاً. <strong>كل ما يُدخل والوضع مشغَّل — منك أو من غيرك — يُحذف عند إنهائه</strong>، وما كان قبله لا يُمس. خذ نسخة احتياطية أولاً.</p>
      <button class="btn btn-sm btn-warning" id="trialStart"><i class="bi bi-play-fill"></i> ابدأ التجربة وعبّئ النظام</button>
    @else
      <p class="small mb-2"><span class="badge text-bg-warning">مشغَّل</span> أُنشئ منذ تشغيله:
        @forelse($trialInventory as $t => $n)<span class="badge text-bg-light border font-monospace">{{ $t }} {{ $n }}</span> @empty <span class="text-muted">لا شيء بعد</span> @endforelse</p>
      @if($trialFilling)<button class="btn btn-sm btn-outline-warning mb-2" id="trialResume"><i class="bi bi-arrow-repeat"></i> أكمل التعبئة</button>@endif
      <form method="post" action="{{ route('app.closeout.trial.stop') }}" class="d-flex gap-2 flex-wrap align-items-center">@csrf
        <input name="confirm" class="form-control form-control-sm" style="max-width:220px" placeholder="اكتب: {{ $confirmTrial }}" required>
        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-stop-fill"></i> أنهِ التجربة واحذف بياناتها</button>
      </form>
      @error('confirm')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
    @endif
    <div id="trialLog" class="small mt-2" hidden></div>
  </div>
</div>

{{-- ٠) النسخة الاحتياطية — قبل أي حذف --}}
<div class="card mb-3">
  <div class="card-body">
    <h2 class="h6 mb-2"><i class="bi bi-download"></i> نسخة احتياطية</h2>
    <p class="small text-muted mb-2">
      الاستضافة الحالية بلا قرص دائم: ما يُكتب في الخادم يزول عند إعادة النشر.
      <strong>النسخة التي تبقى هي التي تُنزّلها وتحفظها عندك.</strong>
      خذ واحدة قبل الحذف.
    </p>
    <a href="{{ route('app.closeout.backup') }}" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-download"></i> نزّل نسخة الآن
    </a>

    @if(count($backups))
      <div class="mt-3">
        <div class="small text-muted mb-1">نسخ داخل الخادم (تزول عند إعادة النشر — لا يُعتمد عليها):</div>
        <ul class="list-unstyled small m-0">
          @foreach($backups as $b)
            <li class="font-monospace" data-backup="{{ $b['name'] }}">
              {{ $b['name'] }} — {{ round($b['bytes'] / 1024) }} كيلوبايت
            </li>
          @endforeach
        </ul>
      </div>
    @endif
  </div>
</div>

{{-- ١) الحسابات التجريبية --}}
<div class="card mb-3">
  <div class="card-body">
    <h2 class="h6 mb-2"><i class="bi bi-person-lock"></i> الحسابات التجريبية</h2>
    <p class="small text-muted">
      <strong>الخطر في كلمة المرور لا في الاسم.</strong> الحساب الذي غيّرت كلمته صار حساباً حقيقياً يعمل به صاحبه.
      والباقي على الكلمة المبذورة خطرٌ ما دام الموقع مفتوحاً للإنترنت.
      والتعطيل لا يحذف: سجل التدقيق وأحداث البلاغات تشير إلى أصحابها.
    </p>

    @if($risky)
      <div class="alert alert-danger py-2 small" data-risky="{{ $risky }}">
        <strong>{{ $risky }}</strong> حساباً ما زال على كلمة المرور المبذورة. عطّلها أو غيّر كلماتها.
      </div>
    @else
      <div class="alert alert-success py-2 small" data-risky="0">
        لا حساب على كلمة المرور المبذورة.
      </div>
    @endif

    <div class="table-responsive">
      <table class="table table-sm align-middle m-0">
        <thead><tr><th>اسم الدخول</th><th>الاسم</th><th>الدور</th><th>كلمة المرور</th><th>الحالة</th></tr></thead>
        <tbody>
          @foreach($demoAccounts as $acc)
            <tr data-demo="{{ $acc['username'] }}">
              <td class="font-monospace">{{ $acc['username'] }}</td>
              <td>{{ $acc['name'] }}</td>
              <td class="small">{{ \App\Core\Permissions\PermissionRegistry::ROLES[$acc['role']] ?? $acc['role'] }}</td>
              <td>
                <span class="badge bg-{{ $acc['seeded'] ? 'danger' : 'success' }}" data-seeded="{{ $acc['username'] }}">
                  {{ $acc['seeded'] ? 'مبذورة' : 'غُيّرت' }}
                </span>
              </td>
              <td>
                <span class="badge bg-{{ $acc['active'] ? 'warning text-dark' : 'secondary' }}" data-state="{{ $acc['username'] }}">
                  {{ $acc['active'] ? 'نشط' : 'معطَّل' }}
                </span>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    <form method="post" action="{{ route('app.closeout.demo-off') }}" class="mt-3">
      @csrf
      <button class="btn btn-sm btn-outline-danger" @disabled(!$risky)>
        <i class="bi bi-person-x"></i> عطّل ما بقي على الكلمة المبذورة
      </button>
      <span class="small text-muted ms-2">لا يمسّ الحسابات التي غُيّرت كلماتها.</span>
    </form>
  </div>
</div>

{{-- ٢) بيانات التجربة --}}
<div class="card mb-3">
  <div class="card-body">
    <h2 class="h6 mb-2"><i class="bi bi-trash3"></i> بيانات التجربة</h2>

    <div class="row g-3">
      <div class="col-md-6">
        <div class="small text-muted mb-1">سيُحذف</div>
        <table class="table table-sm m-0">
          <tbody>
            @foreach($inventory as $label => $n)
              <tr data-purge="{{ $label }}">
                <td class="small">{{ $label }}</td>
                <td class="text-end"><span class="badge bg-{{ $n ? 'danger' : 'secondary' }}">{{ $n }}</span></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="col-md-6">
        <div class="small text-muted mb-1">سيبقى</div>
        <table class="table table-sm m-0">
          <tbody>
            @foreach($preserved as $label => $n)
              <tr data-keep="{{ $label }}">
                <td class="small">{{ $label }}</td>
                <td class="text-end"><span class="badge bg-success">{{ $n }}</span></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>

    <form method="post" action="{{ route('app.closeout.purge') }}" class="mt-3 d-flex flex-wrap align-items-end gap-2">
      @csrf
      <div>
        <label class="form-label small mb-0 text-muted">اكتب «{{ $confirmWord }}» للتأكيد</label>
        <input type="text" name="confirm" class="form-control form-control-sm" style="max-width:12rem" autocomplete="off">
      </div>
      <button class="btn btn-sm btn-danger"><i class="bi bi-trash3"></i> احذف بيانات التجربة</button>
      <span class="small text-muted">لا رجعة في هذا الإجراء. خذ نسخة احتياطية أولاً.</span>
    </form>
  </div>
</div>

{{-- ٣) كتاب المعهد (المرحلة ٩) --}}
<div class="card mb-3">
  <div class="card-body">
    <h2 class="h6 mb-2"><i class="bi bi-book"></i> كتاب المعهد</h2>
    <p class="small text-muted mb-2">يستبدل شجرة المخاطر كلها (الأصناف، الفروع، الأخطار، طبقاتها، بنود التحكم، قواعد التصاريح) بالكتاب المعتمد في المرحلة ٩. المتأثرون والأماكن والهيكل لا تُمس. يُرفض ما دام هناك عمل تشغيلي مربوط بالمخاطر.</p>
    <table class="table table-sm m-0" style="max-width:32rem">
      <tbody>
        @foreach($book as $label => $n)
          <tr data-book="{{ $label }}"><td class="small">{{ $label }}</td><td class="text-end"><span class="badge bg-{{ str_contains($label, 'OHSMS') && $n ? 'warning text-dark' : 'secondary' }}">{{ $n }}</span></td></tr>
        @endforeach
      </tbody>
    </table>
    @if($bookBlockers)
      <div class="alert alert-warning small mt-2 mb-0">يمنع الاستبدال: @foreach($bookBlockers as $k => $v){{ $k }} ({{ $v }})@if(!$loop->last)، @endif @endforeach — احذف بيانات التجربة أولاً.</div>
    @endif
    <form method="post" action="{{ route('app.closeout.book-replace') }}" class="mt-3 d-flex flex-wrap align-items-end gap-2">
      @csrf
      <div>
        <label class="form-label small mb-0 text-muted">اكتب «{{ $confirmBook }}» للتأكيد</label>
        <input type="text" name="confirm" class="form-control form-control-sm" style="max-width:12rem" autocomplete="off">
      </div>
      <button class="btn btn-sm btn-warning" @disabled($bookBlockers)><i class="bi bi-arrow-repeat"></i> استبدل الكتاب بكتاب المعهد</button>
      <span class="small text-muted">خذ نسخة احتياطية أولاً.</span>
    </form>
  </div>
</div>

@endsection
@push('scripts')
<script>
/* ٢١-٩: التعبئة تُستأنف — كل طلب دون حد قطع الخادم، والشاشة تكرره حتى تكتمل */
(function(){
  var log=document.getElementById('trialLog'), tok=document.querySelector('meta[name=csrf-token]').content;
  function post(u){return fetch(u,{method:'POST',headers:{'X-CSRF-TOKEN':tok,'Accept':'application/json'},credentials:'same-origin'}).then(function(r){return r.json().then(function(j){if(!r.ok)throw new Error(j.message||('HTTP '+r.status));return j;});});}
  function say(h){log.hidden=false;log.innerHTML=h;}
  function fill(head){
    post({{ \Illuminate\Support\Js::from(route('app.closeout.trial.fill')) }}).then(function(j){
      var made=Object.keys(j.made||{}).map(function(k){return k+' '+j.made[k];}).join(' · ');
      if(j.done){say(head+'<div class="text-success fw-bold">اكتملت التعبئة: '+made+'</div>');return;}
      say(head+'<div>جارٍ: '+j.step+' '+j.progress+' — '+made+'</div>');fill(head);
    }).catch(function(e){say(head+'<div class="text-danger">توقفت: '+e.message+' — اضغط «أكمل التعبئة» بعد تحديث الصفحة.</div>');});
  }
  var s=document.getElementById('trialStart');
  if(s) s.addEventListener('click',function(){
    if(!confirm('يبدأ وضع التجربة ويُعبَّأ النظام. كل ما يُدخل بعد الآن يُحذف عند إنهائها. متابعة؟'))return;
    s.disabled=true;say('جارٍ التشغيل…');
    post({{ \Illuminate\Support\Js::from(route('app.closeout.trial.start')) }}).then(function(j){
      fill('<div class="alert alert-warning py-2 mb-2">كلمة مرور الحسابات التجريبية (تظهر <b>مرة واحدة</b> — انسخها الآن): <code dir="ltr" style="font-size:1.05rem">'+j.password+'</code><br>أسماء الدخول تبدأ بـ <code dir="ltr">'+j.prefix+'</code> — مثل <code dir="ltr">tj.hr.m</code> مدير، <code dir="ltr">tj.hr.c</code> منسق سلامة، <code dir="ltr">tj.hr.e1</code> موظف، <code dir="ltr">tj.fani.electrical</code> فني.</div>');
    }).catch(function(e){s.disabled=false;say('<span class="text-danger">'+e.message+'</span>');});
  });
  var r=document.getElementById('trialResume');
  if(r) r.addEventListener('click',function(){r.disabled=true;fill('');});
})();
</script>
@endpush
