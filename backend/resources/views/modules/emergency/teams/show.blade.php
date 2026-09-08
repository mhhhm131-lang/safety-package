@extends('layouts.app')
@section('page_title', $team->name)
@section('content')
@php($role = auth()->user()->role())
@php($canTeams = \App\Core\Permissions\PermissionRegistry::hasPermission($role, 'emergency.teams') && !$team->isDerived())
<div class="d-flex align-items-center gap-2 flex-wrap mb-3">
  <h1 class="h4 m-0"><span class="badge text-bg-{{ $team->getTypeColor() }}"><i class="bi bi-{{ $team->getTypeIcon() }}"></i></span> {{ $team->name }}</h1>
  <span class="ms-auto d-flex gap-1">
    @if($canTeams)<a class="btn btn-sm btn-outline-primary" href="{{ route('emergency.teams.edit', $team) }}"><i class="bi bi-pencil"></i> تعديل</a>@endif
    @if($team->isDerived())<a class="btn btn-sm btn-outline-primary" href="/dashboard.html#place={{ $team->place?->code }}"><i class="bi bi-folder2-open"></i> ملف المكان في اللوحة</a>@endif
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('emergency.teams.index') }}">الفرق</a>
  </span>
</div>
@if($team->isDerived())<div class="alert alert-info py-2 small">هذا الفريق الأولي مشتق من ملف المكان في اللوحة (ترشيح مدير الإدارة ← اعتماد مدير الشؤون الإدارية والهندسية ← إحالة للموارد البشرية). التحرير هناك، وتُزامَن هنا آلياً عند الحفظ.</div>@endif
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card"><div class="card-header"><strong>معلومات الفريق</strong></div><div class="card-body small">
      <dl class="row mb-0">
        <dt class="col-5">النوع</dt><dd class="col-7">{{ $team->getTypeLabel() }}</dd>
        <dt class="col-5">المكان</dt><dd class="col-7">{{ $team->place ? $team->place->code.' — '.$team->place->name : 'عام' }}</dd>
        @if($team->organizationUnit)<dt class="col-5">الإدارة</dt><dd class="col-7">{{ $team->organizationUnit->name }}</dd>@endif
        <dt class="col-5">المبنى</dt><dd class="col-7">{{ $team->building?->name ?? '—' }}</dd>
        <dt class="col-5">الفترة</dt><dd class="col-7">{{ $team->getShiftLabel() }}</dd>
        @if($team->isDerived())<dt class="col-5">الجاهزية</dt><dd class="col-7"><span class="badge text-bg-{{ in_array($team->readiness, ['approved', 'referred']) ? 'success' : ($team->readiness === 'nominated' ? 'warning' : 'danger') }}">{{ $team->getReadinessLabel() }}</span></dd>
        <dt class="col-5">آخر مزامنة</dt><dd class="col-7">{{ $team->synced_at?->format('Y-m-d H:i') ?? '—' }}</dd>@endif
        <dt class="col-5">الحالة</dt><dd class="col-7"><span class="badge text-bg-{{ $team->is_active ? 'success' : 'secondary' }}">{{ $team->is_active ? 'نشط' : 'غير نشط' }}</span></dd>
      </dl>
      @if($team->description)<hr><p class="text-muted mb-0">{{ $team->description }}</p>@endif
    </div></div>
  </div>
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex align-items-center"><strong>الأعضاء</strong> <span class="badge text-bg-primary ms-2">{{ $team->members->count() }}</span>@if($canTeams)<button class="btn btn-sm btn-g ms-auto" data-bs-toggle="modal" data-bs-target="#addMemberModal"><i class="bi bi-person-plus"></i> إضافة عضو</button>@endif</div>
      <div class="table-responsive"><table class="table table-sm m-0 small">
        <thead><tr><th>الدور</th><th>الاسم</th><th>الإدارة</th><th>الهاتف</th><th>التدريب</th><th>الحساب</th>@if($canTeams)<th></th>@endif</tr></thead>
        <tbody>
        @forelse($team->members->sortByDesc(fn ($m) => $m->role === 'leader') as $member)
          <tr>
            <td>{{ $member->getRoleKeyLabel() ?? $member->getRoleLabel() }}@if($member->role === 'leader' && $member->role_key) <span class="badge text-bg-primary">قائد</span>@endif</td>
            <td><strong>{{ $member->displayName() }}</strong>@if($member->specialization)<br><small class="text-muted">{{ $member->specialization }}</small>@endif</td>
            <td>{{ $member->department ?? '—' }}</td>
            <td>@if($member->getDisplayPhone())<a href="tel:{{ $member->getDisplayPhone() }}">{{ $member->getDisplayPhone() }}</a>@else<span class="text-danger">لا رقم</span>@endif</td>
            <td>@if($member->trained_at){{ $member->trained_at->format('Y-m-d') }}{{ $member->trainer ? ' · '.$member->trainer : '' }}@if($member->trained_at->lt(now()->subYears(2)))<br><small class="text-warning">يحتاج تجديداً</small>@endif @else<span class="text-warning">لم يُدرَّب</span>@endif</td>
            <td>{{ $member->user ? $member->user->username : 'بلا حساب (نداء هاتفي)' }}</td>
            @if($canTeams)<td><form method="post" action="{{ route('emergency.teams.members.destroy', [$team, $member]) }}" onsubmit="return confirm('إزالة العضو؟')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-person-x"></i></button></form></td>@endif
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-muted py-3">لا أعضاء</td></tr>
        @endforelse
        </tbody></table></div>
    </div>
    @if($canTeams)
    <div class="card border-danger mt-3"><div class="card-body d-flex align-items-center"><div><strong>حذف الفريق</strong><div class="small text-muted">يُحذف الفريق وأعضاؤه</div></div><form class="ms-auto" method="post" action="{{ route('emergency.teams.destroy', $team) }}" onsubmit="return confirm('حذف الفريق نهائياً؟')">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">حذف</button></form></div></div>
    @endif
  </div>
</div>
@if($canTeams)
<div class="modal fade" id="addMemberModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post" action="{{ route('emergency.teams.members.store', $team) }}">@csrf
  <div class="modal-header"><h5 class="modal-title">إضافة عضو</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body row g-2">
    <div class="col-12"><label class="form-label">حساب (اختياري)</label><select name="user_id" class="form-select"><option value="">— بلا حساب (يُنادى هاتفياً) —</option>@foreach($availableUsers as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></div>
    <div class="col-12"><label class="form-label">الاسم (إن كان بلا حساب)</label><input name="name" class="form-control" maxlength="120"></div>
    <div class="col-6"><label class="form-label">الدور</label><select name="role" class="form-select"><option value="member">عضو</option><option value="deputy">نائب</option><option value="leader">قائد</option></select></div>
    <div class="col-6"><label class="form-label">الهاتف</label><input name="phone" class="form-control" maxlength="20" placeholder="05XXXXXXXX"></div>
    <div class="col-12"><label class="form-label">التخصص</label><input name="specialization" class="form-control" maxlength="100"></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-g">إضافة</button></div>
</form></div></div></div>
@endif
@endsection
