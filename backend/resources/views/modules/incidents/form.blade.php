@extends('layouts.public')
@php($labels = ['normal' => 'بلاغ عادي', 'urgent' => 'بلاغ عاجل', 'secret' => 'بلاغ سري'])
@section('page_title', $labels[$type])
@section('content')
<div class="card p-4">
  <div class="d-flex align-items-center gap-2 mb-1">
    <div class="card-h">{{ $labels[$type] }}</div>
    <a class="small ms-auto" href="{{ route('incident.landing') }}">تغيير النوع</a>
  </div>
  @if($type === 'urgent')
    <div class="alert alert-danger py-2 small"><b>خطر داهم؟</b> اتصل أولاً: <b dir="ltr">998</b> الدفاع المدني · <b dir="ltr">997</b> الهلال الأحمر. ثم أرسل هذا البلاغ ليصل مركز السلامة والفني فوراً.</div>
  @elseif($type === 'secret')
    <div class="alert alert-secondary py-2 small"><b>هويتك مخفية.</b> لا اسم ولا هاتف ولا حساب. يصلك رمز تتبع بعد الإرسال — احفظه، فهو الطريقة الوحيدة لمتابعة بلاغك.</div>
  @else
    <div class="text-muted small mb-2">لا يتطلب تسجيل دخول. يصل مركز السلامة ويُحال إلى فني المكان.</div>
  @endif

  <form method="post" action="{{ route('incident.store', $type) }}" id="incForm">
    @csrf
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label fw-bold">المكان <span class="text-danger">*</span></label>
        <select name="place_id" class="form-select" required>
          <option value="">— اختر المكان —</option>
          @foreach($places as $p)
            <option value="{{ $p->id }}" @selected(old('place_id', $preset && $p->code === $preset ? $p->id : null) == $p->id)>{{ $p->code }} — {{ $p->name }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-bold">الموضع بدقة</label>
        <input name="location_text" class="form-control" value="{{ old('location_text') }}" placeholder="مثال: الدور الثاني، قرب المصعد، غرفة ٢٠٤" maxlength="200">
      </div>
      <div class="col-12">
        <label class="form-label fw-bold">ماذا رأيت؟ <span class="text-danger">*</span></label>
        <textarea name="description" class="form-control" rows="4" required minlength="5" placeholder="صف الخطر بكلماتك: ما هو، أين بالضبط، منذ متى، هل يوجد مصاب">{{ old('description') }}</textarea>
      </div>

      {{-- تصنيف الخطر من السجل العام للمعهد: فئة ← فرعية ← خطر --}}
      <div class="col-12">
        <div class="p-3 rounded" style="background:#eef5f1;border:1px solid var(--line)">
          <div class="fw-bold mb-2"><i class="bi bi-shield-exclamation me-1"></i> نوع الخطر من سجل المعهد @if($type !== 'secret')<span class="text-danger">*</span>@else<span class="text-muted small">(اختياري في السري)</span>@endif</div>
          <div class="row g-2">
            <div class="col-md-4"><select id="riskCat" class="form-select form-select-sm" @if($type !== 'secret') required @endif>
              <option value="">١. الفئة الرئيسية</option>
              @foreach($riskCategories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
            </select></div>
            <div class="col-md-4"><select id="riskSub" class="form-select form-select-sm" disabled><option value="">٢. الفئة الفرعية</option></select></div>
            <div class="col-md-4"><select id="riskId" name="risk_id" class="form-select form-select-sm" disabled @if($type !== 'secret') required @endif><option value="">٣. الخطر</option></select></div>
          </div>
          <div id="riskHint" class="small mt-2 text-muted" hidden></div>
          <div class="small mt-2 text-muted">لا تجد ما يطابق؟ اختر الأقرب واكتب التفاصيل في الوصف — مركز السلامة يصحّح التصنيف.</div>
        </div>
      </div>

      <div class="col-12">
        <label class="form-label fw-bold">صورة (اختياري)</label>
        <input type="file" id="img" accept="image/*" capture="environment" class="form-control">
        <input type="hidden" name="photo" id="photo" value="{{ old('photo') }}">
        <img id="prev" alt="" style="max-width:100%;max-height:220px;border-radius:8px;margin-top:8px;display:none">
      </div>

      @if($type !== 'secret')
        @auth
          <div class="col-12 small text-muted">تُرسل باسمك: <b>{{ auth()->user()->name }}</b> — وستُطلب موافقتك قبل إغلاق البلاغ.</div>
        @else
          <div class="col-md-6"><label class="form-label">اسمك (اختياري)</label><input name="reporter_name" class="form-control" value="{{ old('reporter_name') }}" maxlength="120"></div>
          <div class="col-md-6"><label class="form-label">هاتفك (اختياري — للتواصل عند الحاجة)</label><input name="reporter_phone" class="form-control" value="{{ old('reporter_phone') }}" maxlength="30" dir="ltr"></div>
        @endauth
      @else
        <div class="col-12"><label class="form-label">سبب طلب السرية (اختياري)</label><input name="secrecy_reason" class="form-control" value="{{ old('secrecy_reason') }}" maxlength="1000"></div>
      @endif

      <div class="col-12 d-grid mt-2">
        <button class="btn {{ $type === 'urgent' ? 'btn-red' : 'btn-g' }} btn-lg"><i class="bi bi-send-fill me-1"></i> إرسال البلاغ</button>
      </div>
    </div>
  </form>
</div>
@endsection
@push('scripts')
<script>
(function(){
  const cat=document.getElementById('riskCat'),sub=document.getElementById('riskSub'),rk=document.getElementById('riskId'),hint=document.getElementById('riskHint');
  const subUrl=@json(route('incident.api.sub-categories')),riskUrl=@json(route('incident.api.risks'));
  function reset(el,ph){el.innerHTML='<option value="">'+ph+'</option>';el.disabled=true;}
  cat.addEventListener('change',function(){
    reset(sub,'جارٍ التحميل…');reset(rk,'٣. الخطر');hint.hidden=true;
    if(!this.value){reset(sub,'٢. الفئة الفرعية');return;}
    fetch(subUrl+'?category_id='+this.value,{headers:{'Accept':'application/json'}}).then(r=>r.json()).then(d=>{
      sub.innerHTML='<option value="">٢. الفئة الفرعية</option>';d.forEach(s=>{const o=document.createElement('option');o.value=s.id;o.textContent=s.name;sub.appendChild(o);});sub.disabled=false;
    }).catch(()=>reset(sub,'تعذّر التحميل'));
  });
  sub.addEventListener('change',function(){
    reset(rk,'جارٍ التحميل…');hint.hidden=true;
    if(!this.value){reset(rk,'٣. الخطر');return;}
    fetch(riskUrl+'?sub_category_id='+this.value,{headers:{'Accept':'application/json'}}).then(r=>r.json()).then(d=>{
      rk.innerHTML='<option value="">٣. الخطر</option>';
      if(!d.length){rk.innerHTML='<option value="">لا أخطار مسجلة لهذه الفئة</option>';return;}
      d.forEach(r=>{const o=document.createElement('option');o.value=r.id;o.textContent=(r.code?r.code+' — ':'')+r.title;o.dataset.c=r.corrective_action||'';rk.appendChild(o);});rk.disabled=false;
    }).catch(()=>reset(rk,'تعذّر التحميل'));
  });
  rk.addEventListener('change',function(){const o=this.options[this.selectedIndex];const c=o?o.dataset.c:'';hint.hidden=!c;hint.textContent=c?'الإجراء المتوقع من سجل المخاطر: '+c:'';});
  /* صورة مضغوطة في المتصفح (كما في report.html): ≤١٠٢٤ بكسل، JPEG ٧٠٪ */
  document.getElementById('img').addEventListener('change',e=>{
    const f=e.target.files[0],ph=document.getElementById('photo'),pv=document.getElementById('prev');
    if(!f){ph.value='';pv.style.display='none';return;}
    const rd=new FileReader();rd.onload=()=>{const im=new Image();im.onload=()=>{
      const M=1024,s=Math.min(1,M/Math.max(im.width,im.height)),c=document.createElement('canvas');
      c.width=Math.round(im.width*s);c.height=Math.round(im.height*s);c.getContext('2d').drawImage(im,0,0,c.width,c.height);
      ph.value=c.toDataURL('image/jpeg',.7);pv.src=ph.value;pv.style.display='block';};im.src=rd.result;};rd.readAsDataURL(f);
  });
})();
</script>
@endpush
