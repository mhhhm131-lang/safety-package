@extends('layouts.app')
@section('page_title', 'توليد نموذج من المخاطر')
@section('content')

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="{{ route('forms.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-right"></i></a>
  <h1 class="h5 m-0"><i class="bi bi-magic"></i> توليد نموذج من المخاطر</h1>
</div>

<div class="alert alert-light border py-2 small">
  <i class="bi bi-info-circle"></i>
  يُبنى النموذج من المخاطر المختارة: نص المقدمة يعرض كل خطر وضوابطه الاستباقية،
  و<strong>نوع النموذج يُشتق من درجة أشدّ خطر</strong> —
  ≤٥ توعية، ≤٩ تأكيد قراءة، ≤١٤ إقرار موقَّع، ١٥ فأكثر إقرار موقَّع بشاهد.
</div>

<form method="get" class="row g-2 align-items-end mb-3">
  <div class="col-md-4">
    <label class="form-label small mb-1">تصفية بالمكان</label>
    <select name="place_id" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">كل الأماكن</option>
      @foreach($places as $p)
        <option value="{{ $p->id }}" @selected(request('place_id') == $p->id)>{{ $p->code }} — {{ $p->name }}</option>
      @endforeach
    </select>
  </div>
</form>

<form method="post" action="{{ route('forms.generate.store') }}">
  @csrf
  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header d-flex align-items-center">
          <span><i class="bi bi-exclamation-triangle text-warning"></i> اختر المخاطر</span>
          <span class="badge bg-light text-dark border ms-2" id="riskCount">0</span>
        </div>
        <div class="card-body p-0" style="max-height:460px;overflow:auto">
          @forelse($risks as $risk)
            <label class="d-flex align-items-center gap-2 px-3 py-2 border-bottom small mb-0" style="cursor:pointer">
              <input type="checkbox" name="risk_ids[]" value="{{ $risk->id }}" class="form-check-input mt-0 risk-check"
                     data-score="{{ $risk->risk_score }}" @checked(in_array($risk->id, (array) old('risk_ids', [])))>
              <span class="text-muted" dir="ltr">{{ $risk->code }}</span>
              <span class="flex-grow-1">{{ $risk->title }}</span>
              <span class="text-muted">{{ $risk->place?->name }}</span>
              <x-risk-score-badge :score="$risk->risk_score" />
            </label>
          @empty
            <div class="text-center text-muted py-4 small">
              لا مخاطر فعّالة. فعّل مخاطر من السجل العام أولاً في شاشة المخاطر.
            </div>
          @endforelse
        </div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card">
        <div class="card-header">بيانات النموذج</div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">العنوان <span class="text-danger">*</span></label>
            <input type="text" name="title" class="form-control" required maxlength="200"
                   value="{{ old('title') }}" placeholder="مثال: إقرار بمخاطر غرف الكهرباء">
          </div>
          <div class="mb-3">
            <label class="form-label">وصف مختصر</label>
            <input type="text" name="description" class="form-control" maxlength="2000" value="{{ old('description') }}">
          </div>

          <div class="alert alert-light border small mb-3">
            <div>النوع المتوقَّع: <strong id="expectedType">—</strong></div>
            <div class="text-muted" id="expectedNote">اختر المخاطر ليُحدَّد النوع.</div>
          </div>

          <button class="btn btn-g w-100" id="genBtn" disabled><i class="bi bi-magic"></i> ولّد النموذج</button>
        </div>
      </div>
    </div>
  </div>
</form>

@push('scripts')
<script>
// النوع المتوقَّع يتبع أشدّ خطر مختار — الحد نفسه المطبَّق في الخادم.
(function () {
  var checks = document.querySelectorAll('.risk-check');
  var countEl = document.getElementById('riskCount');
  var typeEl = document.getElementById('expectedType');
  var noteEl = document.getElementById('expectedNote');
  var btn = document.getElementById('genBtn');

  function typeFor(score) {
    if (score <= 5) return ['توعية', 'تأكيد قراءة بلا توقيع.'];
    if (score <= 9) return ['تأكيد قراءة', 'إقرار بالاطلاع وملاحظات.'];
    if (score <= 14) return ['إقرار موقَّع', 'إقرار + توقيع إلزامي.'];
    return ['إقرار موقَّع بشاهد', 'إقرار + توقيع + شاهد يوقّع.'];
  }

  function sync() {
    var max = 0, n = 0;
    checks.forEach(function (c) { if (c.checked) { n++; max = Math.max(max, +c.dataset.score || 0); } });
    countEl.textContent = n;
    btn.disabled = n === 0;
    if (n === 0) { typeEl.textContent = '—'; noteEl.textContent = 'اختر المخاطر ليُحدَّد النوع.'; return; }
    var t = typeFor(max);
    typeEl.textContent = t[0];
    noteEl.textContent = t[1] + ' (أشدّ خطر: ' + max + ')';
  }

  checks.forEach(function (c) { c.addEventListener('change', sync); });
  sync();
})();
</script>
@endpush
@endsection
