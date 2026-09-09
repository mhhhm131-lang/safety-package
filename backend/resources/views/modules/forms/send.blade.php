@extends('layouts.app')
@section('page_title', 'تكليف بنموذج')
@section('content')

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="{{ route('forms.show', $form) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-right"></i></a>
  <div>
    <h1 class="h5 m-0">تكليف بنموذج</h1>
    <div class="small text-muted">{{ $form->title }}</div>
  </div>
  @if($stats['total'] > 0)
    <span class="badge bg-light text-dark border ms-auto">مكلَّف حالياً: {{ $stats['total'] }}</span>
  @endif
</div>

<form method="post" action="{{ route('forms.send.store', $form) }}">
  @csrf
  <div class="card">
    <div class="card-body">

      <label class="form-label fw-bold">من يُكلَّف؟</label>
      <div class="row g-2 mb-3">
        @foreach([
          ['users', 'أشخاص بأعيانهم', 'bi-person-check'],
          ['role', 'كل من يحمل دوراً', 'bi-person-badge'],
          ['unit', 'كل من في وحدة', 'bi-diagram-3'],
          ['place', 'كل من في مكان', 'bi-geo-alt'],
        ] as [$mode, $label, $icon])
          <div class="col-6 col-md-3">
            <label class="card h-100 text-center mb-0" style="cursor:pointer">
              <div class="card-body py-3">
                <input type="radio" name="mode" value="{{ $mode }}" class="form-check-input mode-radio"
                       @checked(old('mode', 'users') === $mode)>
                <i class="bi {{ $icon }} d-block fs-4 my-1 text-muted"></i>
                <small>{{ $label }}</small>
              </div>
            </label>
          </div>
        @endforeach
      </div>

      <div class="mode-box" data-mode="users">
        <label class="form-label">الأشخاص</label>
        @if($users->isEmpty())
          <div class="alert alert-light border small">كل المستخدمين المفعّلين مكلَّفون بهذا النموذج.</div>
        @else
          <div class="border rounded p-2" style="max-height:280px;overflow:auto">
            @foreach($users as $u)
              <label class="d-flex align-items-center gap-2 small py-1 mb-0" style="cursor:pointer">
                <input type="checkbox" name="user_ids[]" value="{{ $u->id }}" class="form-check-input mt-0">
                <span class="flex-grow-1">{{ $u->name }}</span>
                <span class="badge bg-light text-muted border">{{ $u->roleName() }}</span>
              </label>
            @endforeach
          </div>
        @endif
      </div>

      <div class="mode-box" data-mode="role" style="display:none">
        <label class="form-label">الدور</label>
        <select name="role" class="form-select">
          @foreach($roles as $key => $label)
            <option value="{{ $key }}">{{ $label }}</option>
          @endforeach
        </select>
        <div class="form-text">يُكلَّف كل من يحمل هذا الدور وحسابه مفعَّل.</div>
      </div>

      <div class="mode-box" data-mode="unit" style="display:none">
        <label class="form-label">الوحدة التنظيمية</label>
        <select name="unit_id" class="form-select">
          @foreach($units as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach
        </select>
        <div class="form-text">يشمل الوحدة وما تحتها.</div>
      </div>

      <div class="mode-box" data-mode="place" style="display:none">
        <label class="form-label">المكان</label>
        <select name="place_id" class="form-select">
          @foreach($places as $p)<option value="{{ $p->id }}">{{ $p->code }} — {{ $p->name }}</option>@endforeach
        </select>
        <div class="form-text">يُكلَّف كل من مكانه المسجَّل هو هذا المكان.</div>
      </div>

      <div class="mt-3" style="max-width:240px">
        <label class="form-label">المهلة (اختيارية)</label>
        <input type="date" name="due_date" class="form-control" value="{{ old('due_date') }}" min="{{ now()->toDateString() }}">
        <div class="form-text">بعدها يصير التكليف «متأخراً» ويُنبَّه صاحبه.</div>
      </div>
    </div>
    <div class="card-footer d-flex gap-2">
      <button class="btn btn-g"><i class="bi bi-send"></i> كلّف وأشعر</button>
      <a href="{{ route('forms.show', $form) }}" class="btn btn-outline-secondary">إلغاء</a>
    </div>
  </div>
</form>

@push('scripts')
<script>
(function () {
  function sync() {
    var mode = document.querySelector('.mode-radio:checked')?.value || 'users';
    document.querySelectorAll('.mode-box').forEach(function (box) {
      box.style.display = box.dataset.mode === mode ? '' : 'none';
    });
  }
  document.querySelectorAll('.mode-radio').forEach(function (r) { r.addEventListener('change', sync); });
  sync();
})();
</script>
@endpush
@endsection
