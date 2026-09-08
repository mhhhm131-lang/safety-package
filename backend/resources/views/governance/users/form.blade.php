@extends('layouts.app')
@section('title', $user ? 'تعديل حساب' : 'حساب جديد')
@section('content')
<h1 class="h4 mb-3">{{ $user ? 'تعديل حساب '.$user->username : 'حساب جديد' }}</h1>
<form method="post" action="{{ $user ? route('app.users.update', $user) : route('app.users.store') }}" class="card p-3" style="max-width:720px">
  @csrf @if($user) @method('PUT') @endif
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label">الاسم</label><input class="form-control" name="name" value="{{ old('name', $user?->name) }}" required></div>
    <div class="col-md-6"><label class="form-label">اسم الدخول</label><input class="form-control" dir="ltr" name="username" value="{{ old('username', $user?->username) }}" required autocapitalize="none"></div>
    <div class="col-md-6"><label class="form-label">البريد (اختياري)</label><input class="form-control" dir="ltr" type="email" name="email" value="{{ old('email', $user?->email) }}"></div>
    <div class="col-md-6"><label class="form-label">كلمة المرور {{ $user ? '(اتركها فارغة إن لم تتغير)' : '' }}</label><input class="form-control" dir="ltr" type="password" name="password" autocomplete="new-password" {{ $user ? '' : 'required' }}></div>
    <div class="col-md-6"><label class="form-label">الدور</label>
      <select class="form-select" name="role" required>
        @foreach($roles as $k=>$v)<option value="{{ $k }}" @selected(old('role', $user?->profile?->role)===$k)>{{ $v }}</option>@endforeach
      </select></div>
    <div class="col-md-6"><label class="form-label">الوحدة التنظيمية</label>
      <select class="form-select" name="organization_unit_id"><option value="">—</option>
        @foreach($units as $un)<option value="{{ $un->id }}" @selected((int)old('organization_unit_id', $user?->profile?->organization_unit_id)===$un->id)>{{ $un->name }}</option>@endforeach
      </select></div>
    <div class="col-md-6"><label class="form-label">المكان</label>
      <select class="form-select" name="place_id"><option value="">—</option>
        @foreach($places as $p)<option value="{{ $p->id }}" @selected((int)old('place_id', $user?->profile?->place_id)===$p->id)>{{ $p->code }} · {{ $p->name }}</option>@endforeach
      </select></div>
  </div>
  <div class="mt-3 d-flex gap-2"><button class="btn btn-g">حفظ</button><a class="btn btn-outline-secondary" href="{{ route('app.users.index') }}">إلغاء</a></div>
</form>
@endsection
