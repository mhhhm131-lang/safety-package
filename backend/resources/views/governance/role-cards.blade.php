@extends('layouts.app')
@section('title', 'بطاقات الأدوار — '.$building->name)
@section('content')
{{-- المرحلة ٣٠-٣ (قرار ٨٩ «كلام المكان»): البطاقات الـ٢١ في مبنى الجلسة من الصفوف — الرقم والفئة والملف للصنف؛
     الاسم والوصف والمهام والشاغل وهاتفه كلام المبنى بـ«تعديل» و«لا ينطبق هنا»؛ خطوات الاستجابة والقائمة الحية تقرأ بطاقة المبنى. --}}
@php($RC = \App\Modules\Emergency\Support\RoleCards::class)
@php($C = \App\Modules\Emergency\Models\BuildingRoleCard::class)
@php($AR = fn ($n) => strtr((string) $n, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']))
<style>
  .rc{border:1px solid var(--line);border-radius:10px;background:#fff;padding:10px 12px;margin-bottom:10px}
  .rc.na{opacity:.6}.rc.na .rc-name{text-decoration:line-through}
  .rc-no{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:50%;background:var(--g);color:#fff;font-weight:900}
  .rc-name{font-weight:800;font-size:1.05rem}
  .rc-src{color:#6b7a74;font-size:.8rem}
  .rc-tasks{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:8px}
  .rc-tasks ul{margin:0;padding-inline-start:18px;font-size:.9rem}
  .rc-tasks .h{font-weight:700;font-size:.85rem;color:var(--g)}
  .rc-ch{background:#fff8e1;border-radius:6px;padding:2px 6px}
  .rc-form{background:var(--tint);border-radius:10px;padding:10px 12px;margin-top:8px}
  @media(max-width:700px){.rc-tasks{grid-template-columns:1fr}}
</style>

<div class="d-flex flex-wrap align-items-center gap-2 mb-1 small">
  <a href="{{ route('app.roles') }}">الأدوار والبطاقات</a><span class="text-muted">›</span>
</div>
<h1 class="page-h mb-1">بطاقات الأدوار — {{ $building->name }}</h1>
<p class="small text-muted mb-2"><b>{{ $AR($cards->count()) }}</b> بطاقة · @if($edited)<b style="color:#7a5a00">{{ $AR($edited) }}</b> معدّلة في هذا المبنى · @endif
  الشكل للمعهد كله (الرقم والفئة والملف)، والكلام لهذا المبنى.</p>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
  <a class="btn btn-o btn-sm" href="/role-cards/index.html" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text"></i> ملفات البطاقات الأصلية</a>
  @if($canEdit)<span class="small text-muted">عدّل الاسم أو الوصف أو المهام، وسمِّ شاغل البطاقة في مبناك وهاتفه، أو اضغط «لا ينطبق هنا». ما تكتبه هنا تقرؤه خطوات الاستجابة والقائمة الحية وقت الطوارئ.</span>
  @else<span class="small text-muted">التعديل لمدير الفرع ومنسق سلامته ومدير المرافق ومسؤول السلامة.</span>@endif
</div>

@foreach($RC::CATEGORIES as $key => $label)
  @continue($byCategory[$key]->isEmpty())
  <h2 class="sec-h mt-3"><i class="bi bi-card-list"></i> {{ $label }} <span class="small text-muted fw-normal">{{ $AR($byCategory[$key]->count()) }} بطاقة</span></h2>
  @foreach($byCategory[$key] as $c)
    @php($changed = $c->changedFields())
    <div class="rc {{ $c->not_applicable ? 'na' : '' }}" id="c{{ $c->id }}" data-card="{{ $c->card_no }}" data-na="{{ $c->not_applicable ? 1 : 0 }}" data-edited="{{ $c->isEdited() ? 1 : 0 }}">
      <div class="d-flex flex-wrap align-items-start gap-2">
        <span class="rc-no">{{ $AR($c->card_no) }}</span>
        <div class="flex-grow-1">
          <div class="rc-name {{ in_array('name', $changed, true) ? 'rc-ch' : '' }}">{{ $c->name }}
            @if($c->not_applicable)<span class="badge text-bg-secondary">لا ينطبق هنا</span>@endif</div>
          <div class="small text-muted {{ in_array('description', $changed, true) ? 'rc-ch' : '' }}">{{ $c->description }}</div>
          <div class="rc-src">{{ $c->reports_to ? 'التبعية: '.$c->reports_to : '' }}{{ $c->basis ? ' · السند: '.$c->basis : '' }} · <a href="{{ $RC::url($c->card_no) }}" target="_blank" rel="noopener">الملف</a></div>
          <div class="small mt-1" data-holder="{{ $c->card_no }}">
            @if($c->holder_name || $c->holder_phone)
              <i class="bi bi-person"></i> <b>{{ $c->holder_name ?? '—' }}</b>
              @if($c->holder_phone) · <a href="tel:{{ $c->holder_phone }}" dir="ltr"><i class="bi bi-telephone"></i> {{ $c->holder_phone }}</a>@endif
            @else<span class="text-muted">لم يُسمَّ شاغل هذه البطاقة في هذا المبنى بعد.</span>@endif
          </div>
          @if($c->isEdited())<div class="rc-src">عدّله {{ $c->edited_by_name }} · {{ $c->edited_at->format('Y-m-d') }}@if($c->changedLabels()) · {{ implode('، ', $c->changedLabels()) }}@endif</div>@endif
        </div>
        @if($canEdit)<button type="button" class="btn btn-o btn-sm rc-edit" data-for="e{{ $c->id }}" aria-controls="e{{ $c->id }}" aria-expanded="false"><i class="bi bi-pencil"></i> تعديل</button>@endif
      </div>
      <div class="rc-tasks">
        @foreach($C::TASK_FIELDS as $f => $h)
          <div class="{{ in_array($f, $changed, true) ? 'rc-ch' : '' }}"><div class="h">{{ $h }}</div>
            @if($c->lines($f))<ul>@foreach($c->lines($f) as $l)<li>{{ $l }}</li>@endforeach</ul>@else<span class="small text-muted">—</span>@endif
            @if(in_array($f, $changed, true))<div class="rc-src">الأصل في الملف: {{ str_replace("\n", ' · ', (string) ($c->source[$f] ?? '—')) }}</div>@endif
          </div>
        @endforeach
      </div>
      @if($canEdit)
      <div class="rc-form" id="e{{ $c->id }}" @if($openCard !== $c->id) hidden @endif>
        <form method="post" action="{{ route('app.role-cards.update', $c) }}" data-card-form="{{ $c->id }}">@csrf
          <input type="hidden" name="_card" value="{{ $c->id }}">
          <div class="row g-2">
            <div class="col-12 col-md-6"><label class="form-label small mb-0">الاسم</label><input name="name" class="form-control form-control-sm" maxlength="160" value="{{ old('name', $c->name) }}" required></div>
            <div class="col-12 col-md-6"><label class="form-label small mb-0">الوصف</label><input name="description" class="form-control form-control-sm" maxlength="255" value="{{ old('description', $c->description) }}"></div>
            <div class="col-12 col-md-6"><label class="form-label small mb-0">الشاغل في هذا المبنى</label><input name="holder_name" class="form-control form-control-sm" maxlength="120" value="{{ old('holder_name', $c->holder_name) }}" placeholder="الاسم"></div>
            <div class="col-12 col-md-6"><label class="form-label small mb-0">الهاتف</label><input name="holder_phone" class="form-control form-control-sm" maxlength="60" value="{{ old('holder_phone', $c->holder_phone) }}" dir="ltr" inputmode="tel" placeholder="يُنادى وقت الحالة"></div>
            @foreach($C::TASK_FIELDS as $f => $h)
              <div class="col-12 col-md-4"><label class="form-label small mb-0">{{ $h }} <span class="text-muted">— سطر لكل مهمة</span></label>
                <textarea name="{{ $f }}" class="form-control form-control-sm" rows="5" maxlength="4000">{{ old($f, $c->{$f}) }}</textarea>
                @if(in_array($f, $changed, true))<div class="rc-src">الأصل في الملف: {{ str_replace("\n", ' · ', (string) ($c->source[$f] ?? '—')) }}</div>@endif
              </div>
            @endforeach
          </div>
          <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
            <div class="form-check me-2"><input class="form-check-input" type="checkbox" name="not_applicable" value="1" id="na{{ $c->id }}" @checked(old('not_applicable', $c->not_applicable))><label class="form-check-label" for="na{{ $c->id }}">لا ينطبق هنا — لا شاغل لهذه البطاقة في هذا المبنى</label></div>
            <button class="btn btn-g btn-sm">حفظ</button>
            <button type="button" class="btn btn-o btn-sm rc-cancel" data-for="e{{ $c->id }}">إلغاء</button>
          </div>
        </form>
        @if($c->isEdited())<form method="post" action="{{ route('app.role-cards.restore', $c) }}" class="mt-1">@csrf<button class="btn btn-link btn-sm p-0" data-restore="{{ $c->id }}">استعادة الأصل من الملف</button></form>@endif
      </div>
      @endif
    </div>
  @endforeach
@endforeach

@if($log->isNotEmpty())
  <h2 class="sec-h mt-3"><i class="bi bi-clock-history"></i> سجل التعديلات <span class="small text-muted fw-normal">من عدّل، وفي كذا، ومتى</span></h2>
  <div class="card"><div class="card-body py-2">
    @foreach($log as $l)
      <div class="small border-bottom py-1" data-log="{{ $l->id }}"><b>{{ $l->user?->name ?? '—' }}</b> · {{ $l->created_at?->format('Y-m-d H:i') }} · {{ $l->description }}</div>
    @endforeach
  </div></div>
@endif

<script>
(function(){
  function show(id, open){ var r=document.getElementById(id); if(!r) return; r.hidden=!open; var b=document.querySelector('[data-for="'+id+'"].rc-edit'); if(b) b.setAttribute('aria-expanded', open?'true':'false'); if(open){ var i=r.querySelector('input:not([type=hidden]),textarea'); if(i) i.focus(); } }
  document.querySelectorAll('.rc-edit').forEach(function(b){ b.addEventListener('click', function(){ var r=document.getElementById(b.getAttribute('data-for')); show(b.getAttribute('data-for'), r && r.hidden); }); });
  document.querySelectorAll('.rc-cancel').forEach(function(b){ b.addEventListener('click', function(){ show(b.getAttribute('data-for'), false); }); });
  if(location.hash && /^#c\d+$/.test(location.hash)){ var h=document.getElementById(location.hash.slice(1)); if(h) h.scrollIntoView({block:'center'}); }
})();
</script>
@endsection
