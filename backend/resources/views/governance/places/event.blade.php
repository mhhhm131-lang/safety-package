@extends('layouts.app')
@section('title', 'فريق فعالية — '.$place->name)
@section('content')
{{-- المرحلة ١٩-٥ (قرار ٤٨): فريق فعالية في القاعات — ما كان في نافذة openEventEdit باللوحة (dashboard.html:1029-1054) --}}
@php($ROLES = \App\Modules\Emergency\Services\PlaceProfile::TEAM)
<div class="d-flex flex-wrap align-items-center gap-2 mb-1">
  <a class="small" href="{{ route('app.places.units.file', $place) }}#pfEvents">{{ $place->name }}</a><span class="text-muted">›</span>
  <h1 class="page-h m-0">فريق فعالية</h1>
</div>
<p class="small text-muted mb-3">ترشيح إدارة القاعات ← اعتماد رئيس الأمن والسلامة · الفريق ليوم الفعالية</p>

@if($errors->any())<div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ route('app.places.team.event.save', [$place, $i]) }}" id="eventForm">@csrf
  <div class="card mb-3"><div class="card-body">
    <div class="row g-2">
      <div class="col-md-5"><label class="form-label small">اسم الفعالية <span class="text-danger">*</span></label><input class="form-control" name="name" required maxlength="160" placeholder="حفل التخرج" value="{{ old('name', $e['name'] ?? '') }}"></div>
      <div class="col-6 col-md-3"><label class="form-label small">تاريخها</label><input type="date" class="form-control" name="date" value="{{ old('date', $e['date'] ?? '') }}"></div>
      <div class="col-6 col-md-4"><label class="form-label small">المكلِّف</label><input class="form-control" name="by" maxlength="120" placeholder="إدارة القاعات" value="{{ old('by', $e['nom']['by'] ?? '') }}"></div>
    </div>
  </div></div>

  <div class="row g-2 mb-3">
    @foreach($ROLES as $j => $role)
      @php($m = $e['team'][$j] ?? [])
      <div class="col-md-6"><div class="card h-100"><div class="card-body" data-role-row="{{ $j }}">
        <h2 class="sec-h">{{ $role }}</h2>
        <label class="form-label small">الاسم</label>
        <input class="form-control mb-2" name="team[{{ $j }}][name]" maxlength="120" value="{{ old("team.$j.name", $m['name'] ?? '') }}">
        <label class="form-label small">الحساب (اسم الدخول)</label>
        <input class="form-control mb-2" name="team[{{ $j }}][user]" maxlength="120" list="teamAccts" autocomplete="off" autocapitalize="off" placeholder="اختياري" dir="ltr" value="{{ old("team.$j.user", $m['user'] ?? '') }}">
        <div class="row g-2">
          <div class="col-6"><label class="form-label small">الإدارة / القسم</label><input class="form-control" name="team[{{ $j }}][dept]" maxlength="120" value="{{ old("team.$j.dept", $m['dept'] ?? '') }}"></div>
          <div class="col-6"><label class="form-label small">الهاتف</label><input type="tel" inputmode="tel" class="form-control" name="team[{{ $j }}][phone]" maxlength="30" dir="ltr" value="{{ old("team.$j.phone", $m['phone'] ?? '') }}"></div>
        </div>
      </div></div></div>
    @endforeach
  </div>
  <datalist id="teamAccts"></datalist>

  <div class="d-flex flex-wrap align-items-center gap-2 mb-4">
    <button class="btn btn-g">حفظ</button>
    <a class="btn btn-o" href="{{ route('app.places.units.file', $place) }}#pfEvents">إلغاء</a>
    <span class="small text-muted">{{ !empty($e['appr']['date']) ? 'تعديل الأسماء يُلغي الاعتماد.' : 'بعد الحفظ يظهر لرئيس الأمن والسلامة لاعتماده.' }}</span>
  </div>
</form>
@endsection
@push('scripts')
@include('governance.places._team_accounts')
@endpush
