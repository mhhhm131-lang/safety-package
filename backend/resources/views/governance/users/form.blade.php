@extends('layouts.app')
@section('title', $user ? 'تعديل حساب' : 'حساب جديد')
@section('content')
<h1 class="h4 mb-3">{{ $user ? 'تعديل حساب '.$user->username : ($own ? 'فني جديد' : 'حساب جديد') }}</h1>
@if($own)<p class="small text-muted">ما تسجله هنا يصل مسؤول السلامة لاعتماده، ولا يعمل الحساب حتى يعتمده.</p>@endif
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
    {{-- ٢٠-١/٢٠-٢ (قرار ٥١): الحساب يحمل صاحبه — المبنى، المسمى (مؤقت حتى بوابة المعهد)، والتغطية للتوجيه --}}
    <div class="col-md-6"><label class="form-label">المبنى</label>
      <select class="form-select" name="building_id">
        @foreach($buildings as $b)<option value="{{ $b->id }}" @selected((int)old('building_id', $user?->profile?->building_id ?? $buildings->first()?->id)===$b->id)>{{ $b->name }}{{ $b->branch ? ' · '.$b->branch : '' }}</option>@endforeach
      </select></div>
    <div class="col-md-6"><label class="form-label">المسمى الوظيفي</label><input class="form-control" name="job_title" maxlength="120" value="{{ old('job_title', $user?->profile?->job_title) }}" placeholder="مثل: فني كهرباء أول · نائب المدير العام">
      <div class="form-text">مؤقت حتى الربط ببوابة المعهد؛ عندها يأتي منها.</div></div>
    {{-- ٢٠-٦ (قرار ٥١): الدور ذو البطاقات المتعددة (فريق الإسناد) — أي بطاقة يحمل هذا الشخص --}}
    <div class="col-md-6" id="card-field" hidden><label class="form-label">بطاقته في السلامة</label>
      <select class="form-select" name="role_card_no" id="role_card_no"><option value="">—</option>
        @foreach($multiCards as $rk => $nos)@foreach($nos as $no)<option value="{{ $no }}" data-role="{{ $rk }}" @selected((int)old('role_card_no', $user?->profile?->role_card_no)===$no)>{{ $no }} · {{ \App\Modules\Emergency\Support\RoleCards::CARDS[$no]['name'] }}</option>@endforeach @endforeach
      </select><div class="form-text">للدور الذي له أكثر من بطاقة سلامة: الطبيب، الأمن، مراقب الحريق.</div></div>
    <div class="col-12"><label class="form-label">التغطية — الأماكن التي يخدمها (للفني والأمن والطبيب)</label>
      <div class="d-flex flex-wrap gap-2" id="coverage">
        @foreach($places as $p)
          <label class="border rounded px-2 py-1 small bg-white"><input type="checkbox" name="coverage[]" value="{{ $p->id }}" @checked(in_array($p->id, old('coverage', $coverage)))> {{ $p->code }} · {{ $p->name }}</label>
        @endforeach
      </div>
      <div class="form-text">«مكاني» يبقى المكان أعلاه؛ التغطية تحدد من يصله بلاغ أو جولة في هذه الأماكن.</div></div>
    <div class="col-md-6" id="party-field"><label class="form-label">الطرف الخارجي (لحساب المقاول / مشرف المقاول / المكتب الاستشاري)</label>
      <select class="form-select" name="external_party_id"><option value="">—</option>
        @foreach($parties as $pt)<option value="{{ $pt->id }}" @selected((int)old('external_party_id', $user?->external_party_id)===$pt->id)>{{ $pt->name }}</option>@endforeach
      </select>
      <div class="form-text">حساب الطرف الخارجي يرى بيانات طرفه فقط: مشاريعه وعماله ومستنداته وتأهيله.</div></div>
  </div>
  <div class="mt-3 d-flex gap-2"><button class="btn btn-g">حفظ</button><a class="btn btn-outline-secondary" href="{{ route('app.users.index') }}">إلغاء</a></div>
</form>
@endsection
@push('scripts')
<script>
(function(){
  var role=document.querySelector('select[name=role]'), f=document.getElementById('card-field'), sel=document.getElementById('role_card_no'); if(!role||!f||!sel) return;
  function show(){ var any=false; sel.querySelectorAll('option[data-role]').forEach(function(o){ var ok=o.dataset.role===role.value; o.hidden=!ok; if(ok) any=true; if(!ok&&o.selected) sel.value=''; }); f.hidden=!any; }
  role.addEventListener('change', show); show();
})();
</script>
@endpush
