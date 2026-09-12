@extends('layouts.app')
@section('title', 'ما ينتظرك الآن')
@section('content')
{{-- المرحلة ١١-٢ (قرار ٣٤): شاشة واحدة — سؤال وزر لكل بند. لا يحتاج المستخدم أن يتعلم شيئاً. --}}
<div class="d-flex align-items-center gap-2 flex-wrap mb-2">
  <h1 class="h4 m-0" id="inboxTitle">
    @if($tasks->isEmpty()) لا شيء ينتظرك الآن
    @else عندك <span id="inboxCount">{{ $tasks->count() }}</span> {{ $tasks->count() === 1 ? 'شيء ينتظرك' : ($tasks->count() === 2 ? 'شيئان ينتظرانك' : 'أشياء تنتظرك') }}
    @endif
  </h1>
  <span class="small text-muted">{{ auth()->user()->name }} · {{ auth()->user()->roleName() }}</span>
</div>
<p class="small text-muted mb-3" id="inboxHint">كل ما يحتاجك يظهر هنا. لا تبحث عنه. <a href="#" id="inboxHintHide" class="text-muted">فهمت</a></p>

@if($tasks->isEmpty())
  <div class="card"><div class="card-body text-center py-5 text-muted">
    <i class="bi bi-check-circle fs-1 text-success d-block mb-2"></i>
    لا شيء ينتظر قرارك. حين يحتاجك شيء يظهر هنا، ويصلك إشعار به. وما تريد أن تبدأه بنفسك تجده تحت «أريد أن…».
  </div></div>
@else
  {{-- قرار المستخدم ٢٠٢٦-٠٩-١٣: لا خلط — كل نوع في قسمه بأيقونته وعدّه. بلاغات الشاغلين ≠ بلاغات الفحص الفني --}}
  @php($ICONS = ['بلاغات الشاغلين' => 'bi-megaphone-fill', 'بلاغات الفحص' => 'bi-clipboard-check', 'الطوارئ' => 'bi-broadcast', 'التصاريح' => 'bi-file-earmark-check', 'المخاطر' => 'bi-lightning-charge', 'النماذج' => 'bi-ui-checks', 'المقاولون' => 'bi-buildings'])
  @php($ORDER = array_keys($ICONS))
  @php($groups = $tasks->groupBy('module')->sortBy(fn ($g, $m) => array_search($m, $ORDER) === false ? 99 : array_search($m, $ORDER)))
  <div class="d-grid gap-3" id="inboxList">
  @foreach($groups as $module => $items)
    <section data-module="{{ $module }}">
      <h2 class="h6 d-flex align-items-center gap-2 mb-2"><i class="bi {{ $ICONS[$module] ?? 'bi-dot' }} fs-5"></i> {{ $module }} <span class="badge text-bg-dark">{{ $items->count() }}</span></h2>
      <div class="d-grid gap-2">
    @foreach($items as $t)
      <div class="card {{ $t->isOverdue ? 'border-danger' : '' }}" data-task="{{ $t->key }}">
        <div class="card-body py-3 d-flex flex-wrap align-items-center gap-3">
          <div class="flex-grow-1" style="min-width:220px">
            <div class="fw-bold">{{ $t->question }}</div>
            <div class="small text-muted mt-1">
              @if($t->isOverdue)<span class="badge text-bg-danger">متأخر</span>
              @elseif($t->dueAt)<span class="badge text-bg-warning">المهلة {{ $t->dueAt->format('m/d H:i') }}</span>@endif
              @if($t->createdAt) · {{ $t->createdAt->diffForHumans() }}@endif
            </div>
          </div>
          <div class="d-flex gap-2 flex-wrap">
            @if($t->primaryMethod() === 'POST')
              <form method="post" action="{{ $t->primary['url'] }}" class="m-0">@csrf<button class="btn btn-g">{{ $t->primary['label'] }}</button></form>
            @else
              <a class="btn btn-g" href="{{ route('app.inbox.open', ['url' => $t->primary['url']]) }}" data-target="{{ $t->primary['url'] }}">{{ $t->primary['label'] }}</a>
            @endif
            @if($t->secondary)
              @if($t->secondaryMethod() === 'POST')
                <form method="post" action="{{ $t->secondary['url'] }}" class="m-0">@csrf<button class="btn btn-outline-secondary">{{ $t->secondary['label'] }}</button></form>
              @else
                <a class="btn btn-outline-secondary" href="{{ $t->secondary['url'] }}">{{ $t->secondary['label'] }}</a>
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
@include('governance._intents', ['intents' => $intents])
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
