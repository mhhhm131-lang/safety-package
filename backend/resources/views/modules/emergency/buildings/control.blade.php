@extends('layouts.app')
@section('page_title', 'لوحة التحكم — '.$building->name)
@section('content')
@php($I = \App\Modules\Emergency\Models\EmergencyIncident::class)
<div class="d-flex align-items-center gap-2 mb-3">
  <h1 class="h4 m-0"><i class="bi bi-joystick"></i> لوحة التحكم: {{ $building->name }}</h1>
  @if($activeIncident)<span class="badge text-bg-{{ $activeIncident->getStatusColor() }}">{{ $activeIncident->is_drill ? 'تمرين ' : 'حالة ' }}{{ $activeIncident->getStatusLabel() }}</span>
  @else<span class="badge text-bg-success">الحالة طبيعية</span>@endif
  <a class="btn btn-sm btn-outline-secondary ms-auto" href="{{ route('emergency.buildings.show', $building) }}">المبنى</a>
</div>

@if($activeIncident)
  <div class="alert alert-{{ $activeIncident->status === 'contained' ? 'warning' : 'danger' }} d-flex align-items-center gap-3">
    <i class="bi bi-exclamation-triangle-fill fs-3"></i>
    <div class="flex-grow-1">
      <div class="fw-bold">{{ $activeIncident->incident_code }} — {{ $activeIncident->getTypeLabel() }} — {{ $activeIncident->place?->name ?? '' }}</div>
      <div class="small">بدأت {{ $activeIncident->triggered_at->format('H:i:s') }} · المدة <span id="duration">{{ $activeIncident->getDurationFormatted() }}</span></div>
    </div>
    <a class="btn btn-light" href="{{ route('emergency.incidents.live', $activeIncident) }}"><i class="bi bi-broadcast"></i> التتبع المباشر</a>
  </div>
  @if($stats)
  <div class="row g-2 mb-3">
    @foreach([['الإجمالي', $stats['total'], ''], ['آمنون', $stats['safe'], 'success'], ['قيد الإخلاء', $stats['evacuating'], 'warning'], ['مفقودون', $stats['missing'], 'danger'], ['مصابون', $stats['injured'], 'danger'], ['الفريق: وصل', $stats['team_arrived'].' / '.$stats['team_total'], 'info']] as [$l, $n, $c])
      <div class="col-4 col-md-2"><div class="card text-center {{ $c ? 'border-'.$c : '' }}"><div class="card-body py-2"><div class="fs-5 fw-bold {{ $c ? 'text-'.$c : '' }}">{{ $n }}</div><div class="small text-muted">{{ $l }}</div></div></div></div>
    @endforeach
  </div>
  @endif
  <div class="card mb-3"><div class="card-header"><strong>آخر الأحداث</strong></div>
    <ul class="list-group list-group-flush small" style="max-height:300px;overflow:auto">
      @foreach($activeIncident->eventLogs->sortByDesc('logged_at')->take(15) as $log)
        <li class="list-group-item d-flex justify-content-between"><span><span class="badge text-bg-{{ $log->severity === 'critical' ? 'danger' : ($log->severity === 'warning' ? 'warning' : 'info') }}">{{ $log->getTypeLabel() }}</span> {{ $log->message }}</span><span class="text-muted">{{ $log->logged_at?->format('H:i:s') }}</span></li>
      @endforeach
    </ul>
  </div>
  <script>
    (function(){const s=new Date('{{ $activeIncident->triggered_at->toIso8601String() }}'),el=document.getElementById('duration');
    setInterval(()=>{const d=Math.floor((Date.now()-s)/1000);el.textContent=[Math.floor(d/3600),Math.floor(d%3600/60),d%60].map(x=>String(x).padStart(2,'0')).join(':')},1000);})();
  </script>
@else
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card border-danger">
        <div class="card-header bg-danger text-white"><strong><i class="bi bi-bell-fill"></i> تفعيل حالة طارئة</strong></div>
        <div class="card-body">
          @can('trigger', $I)
          <form method="post" action="{{ route('emergency.buildings.trigger', $building) }}" id="triggerForm">
            @csrf
            <div class="mb-3">
              <label class="form-label">المكان <span class="text-danger">*</span></label>
              <select name="place_id" class="form-select" required>
                <option value="">اختر المكان…</option>
                @foreach($places as $p)<option value="{{ $p->id }}" @selected(old('place_id', request('place') === $p->code ? $p->id : null) == $p->id)>{{ $p->code }} — {{ $p->name }}</option>@endforeach
              </select>
              <div class="form-text">يُنبَّه الفريق الأولي لهذا المكان (من ملف المكان في اللوحة) مع الإسناد والقيادة.</div>
            </div>
            <div class="mb-3">
              <label class="form-label">نوع الحالة <span class="text-danger">*</span></label>
              <select name="incident_type" class="form-select" required>
                @foreach($I::TYPES as $k => $v)@if($k !== 'lockdown')<option value="{{ $k }}" @selected(old('incident_type', 'fire') === $k)>{{ $v }}</option>@endif @endforeach
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">الخطورة <span class="text-danger">*</span></label>
              <div class="btn-group w-100" role="group">
                @foreach($I::SEVERITIES as $k => $v)
                  <input type="radio" class="btn-check" name="severity" value="{{ $k }}" id="sev-{{ $k }}" @checked(old('severity', 'high') === $k)>
                  <label class="btn btn-outline-{{ ['low' => 'success', 'medium' => 'warning', 'high' => 'danger', 'critical' => 'dark'][$k] }}" for="sev-{{ $k }}">{{ $v }}</label>
                @endforeach
              </div>
            </div>
            <div class="mb-3"><label class="form-label">الوصف</label><textarea name="description" class="form-control" rows="2" placeholder="ما الذي حدث وأين بالضبط؟">{{ old('description') }}</textarea></div>
            <div class="form-check mb-3"><input type="checkbox" name="is_drill" value="1" class="form-check-input" id="isDrill"><label class="form-check-label" for="isDrill">هذا تمرين (يُنبَّه الفريق ولا تُبلَّغ الجهات الخارجية)</label></div>
            <button type="submit" class="btn btn-danger btn-lg w-100"><i class="bi bi-bell-fill"></i> تشغيل الإنذار</button>
          </form>
          <script>document.getElementById('triggerForm').addEventListener('submit',function(e){if(!document.getElementById('isDrill').checked&&!confirm('تفعيل حالة طارئة حقيقية: سيُنبَّه الفريق الأولي والقيادة وكل الحسابات، وتُسجَّل جهات الاتصال الخارجية للنداء. متأكد؟'))e.preventDefault();});</script>
          @else
            <div class="text-muted">التفعيل لمن يملك صلاحية الطوارئ (مسؤول السلامة، المناوب، المنسق، مديرو الإدارات، القيادة).</div>
          @endcan
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card mb-3">
        <div class="card-header"><strong>الإغلاق الأمني</strong> <span class="small text-muted">حالة طارئة من نوع «إغلاق» — أفعال الأبواب والمصاعد والشاشات تُوصل في المرحلة ٥</span></div>
        <div class="card-body">
          @if($lockdown)
            <div class="alert alert-warning py-2">إغلاق ساري: {{ $lockdown->getLevelLabel() }} منذ {{ $lockdown->initiated_at->format('H:i') }}</div>
            @can('trigger', $I)<form method="post" action="{{ route('emergency.lockdowns.lift', $lockdown) }}">@csrf<div class="input-group"><input name="reason" class="form-control" placeholder="سبب الرفع"><button class="btn btn-success">رفع الإغلاق</button></div></form>@endcan
          @else
            @can('trigger', $I)
            <form method="post" action="{{ route('emergency.buildings.lockdown', $building) }}" onsubmit="return confirm('بدء إغلاق أمني؟ سيُنبَّه الجميع.')">
              @csrf
              <div class="row g-2">
                <div class="col-md-5"><select name="level" class="form-select">@foreach(\App\Modules\Emergency\Models\Lockdown::LEVELS as $k => $v)@if($k !== 'zone')<option value="{{ $k }}">{{ $v }}</option>@endif @endforeach</select></div>
                <div class="col-md-4"><select name="place_id" class="form-select"><option value="">المكان (اختياري)</option>@foreach($places as $p)<option value="{{ $p->id }}">{{ $p->code }} — {{ $p->name }}</option>@endforeach</select></div>
                <div class="col-md-3"><button class="btn btn-dark w-100"><i class="bi bi-lock-fill"></i> إغلاق</button></div>
                <div class="col-12"><input name="reason" class="form-control" placeholder="السبب"></div>
              </div>
            </form>
            @else<div class="text-muted">لمن يملك صلاحية التفعيل.</div>@endcan
          @endif
        </div>
      </div>
      <div class="card">
        <div class="card-header"><strong>المبنى</strong></div>
        <div class="card-body">
          <div class="row g-2 text-center">
            <div class="col-3"><div class="fs-5 fw-bold">{{ $building->floors->count() }}</div><small class="text-muted">طوابق</small></div>
            <div class="col-3"><div class="fs-5 fw-bold">{{ $building->assemblyPoints->count() }}</div><small class="text-muted">نقاط تجمع</small></div>
            <div class="col-3"><div class="fs-5 fw-bold">{{ $building->total_capacity ?? '—' }}</div><small class="text-muted">السعة</small></div>
            <div class="col-3"><div class="fs-5 fw-bold">{{ $building->teams->count() }}</div><small class="text-muted">فرق</small></div>
          </div>
          @if($building->assemblyPoints->isNotEmpty())
            <ul class="list-group list-group-flush small mt-2">
              @foreach($building->assemblyPoints as $point)<li class="list-group-item px-0"><span class="badge text-bg-{{ $point->is_primary ? 'success' : 'secondary' }}">{{ $point->code }}</span> {{ $point->name }}@if($point->place) <span class="text-muted">· {{ $point->place->name }}</span>@endif</li>@endforeach
            </ul>
          @else
            <div class="alert alert-warning py-2 mt-2 mb-0 small">لا نقاط تجمع مسجّلة بعد — تُدخل من شاشة المبنى.</div>
          @endif
        </div>
      </div>
    </div>
  </div>
@endif
@endsection
