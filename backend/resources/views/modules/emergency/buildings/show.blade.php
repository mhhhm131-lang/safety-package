@extends('layouts.app')
@section('page_title', $building->name)
@section('content')
@php($role = auth()->user()->role())
@php($canManage = \App\Core\Permissions\PermissionRegistry::hasPermission($role, 'emergency.manage'))
<div class="d-flex align-items-center gap-2 flex-wrap mb-3">
  <h1 class="h4 m-0"><i class="bi bi-building"></i> {{ $building->name }} @if($building->code)<small class="text-muted">({{ $building->code }})</small>@endif</h1>
  <span class="badge text-bg-{{ $building->isInEmergency() ? 'danger' : 'success' }}">{{ $building->getEmergencyStatusLabel() }}</span>
  <span class="ms-auto d-flex gap-1">
    <a class="btn btn-sm btn-{{ $building->isInEmergency() ? 'danger' : 'warning' }}" href="{{ route('emergency.buildings.control', $building) }}"><i class="bi bi-joystick"></i> لوحة التحكم</a>
    @if($canManage)<a class="btn btn-sm btn-outline-primary" href="{{ route('emergency.buildings.edit', $building) }}"><i class="bi bi-pencil"></i> تعديل</a>@endif
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('emergency.dashboard') }}">مركز الطوارئ</a>
  </span>
</div>
@if($building->floors->isEmpty() || $building->assemblyPoints->isEmpty())
  <div class="alert alert-warning py-2 small">الطوابق والمخارج ونقاط التجمع تُملأ بالواقع (الفجوتان ٣ و٦ في BACKEND.md) — لا أرقام مفترضة. أدخلها هنا أو عبر مدير المرافق.</div>
@endif

<div class="row g-3">
  <div class="col-lg-4">
    <div class="card mb-3"><div class="card-header"><strong>معلومات المبنى</strong></div><div class="card-body small">
      <dl class="row mb-0">
        <dt class="col-5">النوع</dt><dd class="col-7">{{ $building->getTypeLabel() }}</dd>
        <dt class="col-5">مستوى الخطر</dt><dd class="col-7">{{ $building->getRiskLevelLabel() }}</dd>
        <dt class="col-5">الطوابق</dt><dd class="col-7">{{ $building->floors->count() }}</dd>
        <dt class="col-5">السعة</dt><dd class="col-7">{{ $building->total_capacity ?? '—' }}</dd>
        @if($building->address)<dt class="col-5">العنوان</dt><dd class="col-7">{{ $building->address }}</dd>@endif
        <dt class="col-5">الحالة</dt><dd class="col-7">{{ ['active' => 'نشط', 'inactive' => 'غير نشط', 'under_maintenance' => 'تحت الصيانة'][$building->status] ?? $building->status }}</dd>
      </dl>
    </div></div>

    <div class="card mb-3">
      <div class="card-header d-flex align-items-center"><strong>نقاط التجمع</strong>@if($canManage)<button class="btn btn-sm btn-outline-primary ms-auto" data-bs-toggle="modal" data-bs-target="#addPointModal"><i class="bi bi-plus"></i></button>@endif</div>
      <ul class="list-group list-group-flush small">
        @forelse($building->assemblyPoints as $point)
          <li class="list-group-item">
            <div class="d-flex justify-content-between align-items-center">
              <div><span class="badge text-bg-{{ $point->is_primary ? 'success' : 'secondary' }}">{{ $point->code }}</span> {{ $point->name }}@if($point->is_primary) <span class="badge text-bg-success">رئيسية</span>@endif
                @if($point->place)<br><small class="text-muted">تخدم: {{ $point->place->name }}</small>@endif
                @if($point->directions)<br><small class="text-muted">{{ $point->directions }}</small>@endif</div>
              <div class="text-nowrap">@if($point->capacity)<small class="text-muted">{{ $point->capacity }} شخص</small>@endif
                @if($canManage)<form method="post" action="{{ route('emergency.buildings.assembly-points.destroy', [$building, $point]) }}" class="d-inline" onsubmit="return confirm('حذف نقطة التجمع؟')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button></form>@endif</div>
            </div>
          </li>
        @empty
          <li class="list-group-item text-muted">لا نقاط تجمع بعد</li>
        @endforelse
      </ul>
    </div>

    <div class="card">
      <div class="card-header"><strong>جهات الاتصال</strong> <a class="small ms-2" href="{{ route('emergency.contacts.index') }}">الكل</a></div>
      <ul class="list-group list-group-flush small">
        @forelse($building->contacts->sortBy('priority')->take(6) as $contact)
          <li class="list-group-item d-flex justify-content-between align-items-center"><span><strong>{{ $contact->name }}</strong><br><small class="text-muted">{{ $contact->role }}</small></span><a href="tel:{{ $contact->phone }}" class="btn btn-sm btn-outline-success py-0"><i class="bi bi-telephone"></i> {{ $contact->phone }}</a></li>
        @empty
          <li class="list-group-item text-muted">لا جهات اتصال</li>
        @endforelse
      </ul>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center"><strong>الطوابق</strong>@if($canManage)<button class="btn btn-sm btn-outline-primary ms-auto" data-bs-toggle="modal" data-bs-target="#addFloorModal"><i class="bi bi-plus"></i> طابق</button>@endif</div>
      <div class="table-responsive"><table class="table table-sm table-hover m-0 small">
        <thead><tr><th>الطابق</th><th>الاسم/المنطقة</th><th>المسؤول</th><th>السعة</th><th>ترتيب الإخلاء</th><th>المخارج</th><th>الحالة</th>@if($canManage)<th></th>@endif</tr></thead>
        <tbody>
        @forelse($building->floors->sortBy('floor_number') as $floor)
          <tr>
            <td><span class="badge text-bg-secondary">{{ $floor->getDisplayName() }}</span></td>
            <td>{{ $floor->name ?? '—' }}{{ $floor->zone ? ' · '.$floor->zone : '' }}</td>
            <td>{{ $floor->responsible?->name ?? '—' }}</td>
            <td>{{ $floor->capacity ?? '—' }}</td>
            <td>{{ $floor->evacuation_order ?? '—' }}</td>
            <td>{{ $floor->exits->count() }}</td>
            <td><span class="badge text-bg-{{ ['normal' => 'success', 'evacuating' => 'danger', 'cleared' => 'info', 'blocked' => 'dark'][$floor->status] ?? 'secondary' }}">{{ $floor->getStatusLabel() }}</span></td>
            @if($canManage)<td class="text-nowrap"><form method="post" action="{{ route('emergency.buildings.floors.destroy', [$building, $floor]) }}" class="d-inline" onsubmit="return confirm('حذف الطابق ومخارجه؟')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button></form></td>@endif
          </tr>
        @empty
          <tr><td colspan="8" class="text-center text-muted py-3">لا طوابق مسجّلة بعد — أدخلها بالواقع</td></tr>
        @endforelse
        </tbody></table></div>
    </div>

    <div class="card mb-3">
      <div class="card-header d-flex align-items-center"><strong>المخارج</strong>@if($canManage && $building->floors->isNotEmpty())<button class="btn btn-sm btn-outline-primary ms-auto" data-bs-toggle="modal" data-bs-target="#addExitModal"><i class="bi bi-plus"></i> مخرج</button>@endif</div>
      <div class="table-responsive"><table class="table table-sm table-hover m-0 small">
        <thead><tr><th>الرمز</th><th>الاسم</th><th>الطابق</th><th>النوع</th><th>يؤدي إلى</th><th>الحالة</th>@if($canManage)<th></th>@endif</tr></thead>
        <tbody>
        @forelse($building->exits as $exit)
          <tr>
            <td><strong>{{ $exit->code }}</strong></td><td>{{ $exit->name ?? '—' }}{{ $exit->direction ? ' · '.$exit->direction : '' }}</td>
            <td>{{ $exit->floor?->getDisplayName() }}</td><td>{{ $exit->getTypeLabel() }}</td><td>{{ $exit->assemblyPoint?->name ?? '—' }}</td>
            <td>
              @if($canManage)
                <form method="post" action="{{ route('emergency.buildings.exits.update', [$building, $exit]) }}" class="d-inline">@csrf @method('PUT')
                  <select name="status" class="form-select form-select-sm d-inline w-auto py-0" onchange="this.form.submit()"><option value="available" @selected($exit->status === 'available')>متاح</option><option value="blocked" @selected($exit->status === 'blocked')>مغلق</option><option value="maintenance" @selected($exit->status === 'maintenance')>صيانة</option></select>
                </form>
              @else<span class="badge text-bg-{{ $exit->status === 'available' ? 'success' : 'danger' }}">{{ $exit->getStatusLabel() }}</span>@endif
            </td>
            @if($canManage)<td><form method="post" action="{{ route('emergency.buildings.exits.destroy', [$building, $exit]) }}" onsubmit="return confirm('حذف المخرج؟')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger py-0"><i class="bi bi-trash"></i></button></form></td>@endif
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-muted py-3">لا مخارج مسجّلة بعد</td></tr>
        @endforelse
        </tbody></table></div>
    </div>

    <div class="card">
      <div class="card-header d-flex align-items-center"><strong>الفرق</strong><a class="btn btn-sm btn-outline-secondary ms-auto" href="{{ route('emergency.teams.index') }}">إدارة الفرق</a></div>
      <div class="table-responsive"><table class="table table-sm table-hover m-0 small">
        <thead><tr><th>الفريق</th><th>النوع</th><th>المكان</th><th>الأعضاء</th><th>الجاهزية</th></tr></thead>
        <tbody>
        @forelse($building->teams as $team)
          <tr><td><a href="{{ route('emergency.teams.show', $team) }}">{{ $team->name }}</a></td><td>{{ $team->getTypeLabel() }}</td><td>{{ $team->place?->code ?? 'عام' }}</td><td>{{ $team->members->count() }}</td>
            <td>@if($team->isDerived())<span class="badge text-bg-{{ in_array($team->readiness, ['approved', 'referred']) ? 'success' : ($team->readiness === 'nominated' ? 'warning' : 'danger') }}">{{ $team->getReadinessLabel() }}</span>@else<span class="badge text-bg-{{ $team->is_active ? 'success' : 'secondary' }}">{{ $team->is_active ? 'نشط' : 'غير نشط' }}</span>@endif</td></tr>
        @empty
          <tr><td colspan="5" class="text-center text-muted py-3">لا فرق — الفريق الأولي يُشتق من ملف المكان في اللوحة</td></tr>
        @endforelse
        </tbody></table></div>
    </div>
  </div>
</div>

@if($canManage)
<div class="modal fade" id="addPointModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post" action="{{ route('emergency.buildings.assembly-points.store', $building) }}">@csrf
  <div class="modal-header"><h5 class="modal-title">إضافة نقطة تجمع</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body row g-2">
    <div class="col-4"><label class="form-label">الرمز *</label><input name="code" class="form-control" required maxlength="10" placeholder="A1"></div>
    <div class="col-8"><label class="form-label">الاسم *</label><input name="name" class="form-control" required maxlength="100"></div>
    <div class="col-6"><label class="form-label">تخدم المكان</label><select name="place_id" class="form-select"><option value="">— عام —</option>@foreach($places as $p)<option value="{{ $p->id }}">{{ $p->code }} — {{ $p->name }}</option>@endforeach</select></div>
    <div class="col-3"><label class="form-label">السعة</label><input type="number" name="capacity" class="form-control" min="1"></div>
    <div class="col-3"><label class="form-label">المسؤول</label><select name="responsible_id" class="form-select"><option value="">—</option>@foreach($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></div>
    <div class="col-12"><label class="form-label">التوجيهات</label><textarea name="directions" class="form-control" rows="2"></textarea></div>
    <div class="col-12 form-check ms-2"><input type="checkbox" name="is_primary" value="1" class="form-check-input" id="isPrimary"><label class="form-check-label" for="isPrimary">نقطة رئيسية</label></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-g">إضافة</button></div>
</form></div></div></div>

<div class="modal fade" id="addFloorModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post" action="{{ route('emergency.buildings.floors.store', $building) }}">@csrf
  <div class="modal-header"><h5 class="modal-title">إضافة طابق</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body row g-2">
    <div class="col-4"><label class="form-label">رقم الطابق *</label><input type="number" name="floor_number" class="form-control" required min="-20" max="200" placeholder="0 = الأرضي، -1 = قبو"></div>
    <div class="col-8"><label class="form-label">الاسم</label><input name="name" class="form-control" maxlength="100"></div>
    <div class="col-6"><label class="form-label">المنطقة</label><input name="zone" class="form-control" maxlength="100"></div>
    <div class="col-3"><label class="form-label">السعة</label><input type="number" name="capacity" class="form-control" min="0"></div>
    <div class="col-3"><label class="form-label">ترتيب الإخلاء</label><input type="number" name="evacuation_order" class="form-control" min="0"></div>
    <div class="col-12"><label class="form-label">المسؤول</label><select name="responsible_id" class="form-select"><option value="">—</option>@foreach($users as $u)<option value="{{ $u->id }}">{{ $u->name }}</option>@endforeach</select></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-g">إضافة</button></div>
</form></div></div></div>

<div class="modal fade" id="addExitModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post" action="{{ route('emergency.buildings.exits.store', $building) }}">@csrf
  <div class="modal-header"><h5 class="modal-title">إضافة مخرج</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body row g-2">
    <div class="col-4"><label class="form-label">الرمز *</label><input name="code" class="form-control" required maxlength="10"></div>
    <div class="col-8"><label class="form-label">الاسم</label><input name="name" class="form-control" maxlength="100"></div>
    <div class="col-6"><label class="form-label">الطابق *</label><select name="floor_id" class="form-select" required>@foreach($building->floors as $f)<option value="{{ $f->id }}">{{ $f->getDisplayName() }}</option>@endforeach</select></div>
    <div class="col-6"><label class="form-label">النوع</label><select name="exit_type" class="form-select"><option value="main">رئيسي</option><option value="emergency" selected>طوارئ</option><option value="fire_escape">سلم حريق</option><option value="service">خدمة</option></select></div>
    <div class="col-6"><label class="form-label">يؤدي إلى نقطة</label><select name="leads_to_point_id" class="form-select"><option value="">—</option>@foreach($building->assemblyPoints as $p)<option value="{{ $p->id }}">{{ $p->code }} — {{ $p->name }}</option>@endforeach</select></div>
    <div class="col-6"><label class="form-label">الاتجاه</label><input name="direction" class="form-control" maxlength="50"></div>
    <div class="col-12 form-check ms-2"><input type="checkbox" name="is_accessible" value="1" class="form-check-input" id="isAcc"><label class="form-check-label" for="isAcc">مناسب لذوي الإعاقة</label></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-g">إضافة</button></div>
</form></div></div></div>
@endif
@endsection
