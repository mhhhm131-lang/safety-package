@extends('layouts.app')
@section('page_title', 'فرق الطوارئ')
@section('content')
@php($role = auth()->user()->role())
@php($P = \App\Core\Permissions\PermissionRegistry::class)
<div class="d-flex align-items-center gap-2 flex-wrap mb-3">
  <h1 class="h4 m-0"><i class="bi bi-people-fill"></i> فرق الطوارئ</h1>
  <span class="small text-muted">الفريق الأولي (منسق، مسعف، منقذ، إطفائي) مشتق من ملف المكان في اللوحة — لا يُحرَّر هنا</span>
  <span class="ms-auto d-flex gap-1">
    @if($P::hasPermission($role, 'emergency.manage'))<form method="post" action="{{ route('emergency.teams.sync') }}">@csrf<button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat"></i> مزامنة من اللوحة</button></form>@endif
    @if($P::hasPermission($role, 'emergency.teams'))<a class="btn btn-sm btn-g" href="{{ route('emergency.teams.create') }}"><i class="bi bi-plus-lg"></i> فريق يدوي</a>@endif
  </span>
</div>
<form method="get" class="mb-3"><div class="input-group input-group-sm" style="max-width:420px"><select name="place" class="form-select"><option value="">كل الأماكن</option>@foreach($places as $p)<option value="{{ $p->code }}" @selected(request('place') === $p->code)>{{ $p->code }} — {{ $p->name }}</option>@endforeach</select><button class="btn btn-g">تصفية</button></div></form>
<div class="card"><div class="table-responsive"><table class="table table-hover m-0 small">
  <thead><tr><th>الفريق</th><th>النوع</th><th>المكان</th><th>الأعضاء</th><th>الجاهزية</th><th>المصدر</th></tr></thead>
  <tbody>
  @forelse($teams as $team)
    <tr class="{{ !$team->is_active ? 'text-muted' : '' }}">
      <td><a href="{{ route('emergency.teams.show', $team) }}" class="text-decoration-none"><strong>{{ $team->name }}</strong></a></td>
      <td><span class="badge text-bg-{{ $team->getTypeColor() }}"><i class="bi bi-{{ $team->getTypeIcon() }}"></i> {{ $team->getTypeLabel() }}</span></td>
      <td>{{ $team->place ? $team->place->code.' — '.$team->place->name : 'عام' }}</td>
      <td>{{ $team->members->count() }}@if($team->isDerived()) / 4 @endif
        @if($team->members->isNotEmpty())<br><small class="text-muted">@foreach($team->members as $m){{ $m->getRoleKeyLabel() ? $m->getRoleKeyLabel().': ' : '' }}{{ $m->displayName() }}{{ $loop->last ? '' : '، ' }}@endforeach</small>@endif</td>
      <td>@if($team->isDerived())<span class="badge text-bg-{{ in_array($team->readiness, ['approved', 'referred']) ? 'success' : ($team->readiness === 'nominated' ? 'warning' : 'danger') }}">{{ $team->getReadinessLabel() }}</span>@else<span class="badge text-bg-{{ $team->is_active ? 'success' : 'secondary' }}">{{ $team->is_active ? 'نشط' : 'غير نشط' }}</span>@endif</td>
      <td>@if($team->isDerived())<a class="small" href="/dashboard.html#place={{ $team->place?->code }}">ملف المكان</a>@else يدوي @endif</td>
    </tr>
  @empty
    <tr><td colspan="6" class="text-center text-muted py-4">لا فرق. رشّح الفريق الأولي من ملف المكان في اللوحة ثم اضغط «مزامنة».</td></tr>
  @endforelse
  </tbody></table></div></div>
@if($teams->hasPages())<div class="mt-3">{{ $teams->withQueryString()->links() }}</div>@endif
@endsection
