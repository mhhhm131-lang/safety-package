@extends('layouts.app')
@section('title', 'الفريق الأولي — '.$place->name)
@section('content')
{{-- المرحلة ١٩-٥ (قرار ٤٨): نموذج ترشيح الفريق الأولي — ما كان في نافذة openUnitEdit باللوحة (dashboard.html:970-999) --}}
@php($ROLES = \App\Modules\Emergency\Services\PlaceProfile::TEAM)
<div class="d-flex flex-wrap align-items-center gap-2 mb-1">
  <a class="small" href="{{ route('app.places.units.file', $place) }}#pfTeams">{{ $place->name }}</a><span class="text-muted">›</span>
  <h1 class="page-h m-0">الفريق الأولي{{ $n > 1 ? ' '.($k + 1).' من '.$n : '' }}</h1>
</div>
<p class="small text-muted mb-3">{{ $un['label'] }} · ترشيح مدير الإدارة ← اعتماد مدير الشؤون الإدارية والهندسية ← إحالة للموارد البشرية</p>

@if($errors->any())<div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ route('app.places.team.save', [$place, $un['uid'], $k]) }}" id="teamForm">@csrf
  <div class="card mb-3"><div class="card-body">
    <h2 class="sec-h">الترشيح</h2>
    <div class="row g-2">
      <div class="col-md-5"><label class="form-label small">المدير المرشِّح</label><input class="form-control" name="nom_by" maxlength="120" placeholder="مدير الإدارة" value="{{ old('nom_by', ($t['nom']['by'] ?? '') ?: $un['mgr']) }}"></div>
      <div class="col-6 col-md-4"><label class="form-label small">تاريخ الترشيح</label><input type="date" class="form-control" name="nom_date" value="{{ old('nom_date', ($t['nom']['date'] ?? '') ?: now()->toDateString()) }}"></div>
      <div class="col-6 col-md-3"><label class="form-label small">عدد الموظفين في المكان</label><input type="number" min="0" inputmode="numeric" class="form-control" name="staff" placeholder="فريق لكل ٢٥" value="{{ old('staff', $unit['staff'] ?? '') }}"></div>
    </div>
  </div></div>

  <div class="row g-2 mb-3">
    @foreach($ROLES as $i => $role)
      @php($m = $t['team'][$i] ?? [])
      <div class="col-md-6"><div class="card h-100"><div class="card-body" data-role-row="{{ $i }}">
        <h2 class="sec-h">{{ $role }}</h2>
        <label class="form-label small">الاسم</label>
        <input class="form-control mb-2" name="team[{{ $i }}][name]" maxlength="120" value="{{ old("team.$i.name", $m['name'] ?? '') }}">
        <label class="form-label small">الحساب (اسم الدخول) — لتصله التنبيهات</label>
        <input class="form-control mb-2" name="team[{{ $i }}][user]" maxlength="120" list="teamAccts" autocomplete="off" autocapitalize="off" placeholder="اختياري" dir="ltr" value="{{ old("team.$i.user", $m['user'] ?? '') }}">
        <div class="row g-2">
          <div class="col-6"><label class="form-label small">الإدارة / القسم</label><input class="form-control" name="team[{{ $i }}][dept]" maxlength="120" value="{{ old("team.$i.dept", ($m['dept'] ?? '') ?: ($place->code === 'HZ-06' ? $un['label'] : '')) }}"></div>
          <div class="col-6"><label class="form-label small">الهاتف</label><input type="tel" inputmode="tel" class="form-control" name="team[{{ $i }}][phone]" maxlength="30" dir="ltr" value="{{ old("team.$i.phone", $m['phone'] ?? '') }}"></div>
          <div class="col-6"><label class="form-label small">تاريخ التدريب</label><input type="date" class="form-control" name="team[{{ $i }}][trained]" value="{{ old("team.$i.trained", $m['trained'] ?? '') }}"></div>
          <div class="col-6"><label class="form-label small">جهة التدريب</label><input class="form-control" name="team[{{ $i }}][trainer]" maxlength="120" placeholder="الدفاع المدني" value="{{ old("team.$i.trainer", $m['trainer'] ?? '') }}"></div>
        </div>
      </div></div></div>
    @endforeach
  </div>
  <datalist id="teamAccts"></datalist>

  <div class="d-flex flex-wrap align-items-center gap-2 mb-4">
    <button class="btn btn-g">حفظ</button>
    <a class="btn btn-o" href="{{ route('app.places.units.file', $place) }}#pfTeams">إلغاء</a>
    <span class="small text-muted">{{ !empty($t['appr']['date']) ? 'تعديل الأسماء بعد الاعتماد يُلغي الاعتماد والإحالة ويعيد المسار إلى الترشيح.' : 'بعد الحفظ يظهر الترشيح لمدير الشؤون الإدارية والهندسية لاعتماده.' }}</span>
  </div>
</form>
@endsection
@push('scripts')
@include('governance.places._team_accounts')
@endpush
