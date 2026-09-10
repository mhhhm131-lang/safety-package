@extends('layouts.app')
@section('page_title', 'التتبع المباشر — '.$incident->incident_code)
@section('content')
@php($open = $incident->isOpen())
@php($alertClass = $incident->status === 'active' ? 'danger' : ($incident->status === 'contained' ? 'warning' : 'secondary'))
<div class="alert alert-{{ $alertClass }} mb-3">
  <div class="d-flex align-items-center gap-3 flex-wrap">
    <i class="bi bi-broadcast fs-3"></i>
    <div class="flex-grow-1">
      <div class="h5 m-0">{{ $incident->incident_code }} — {{ $incident->getTypeLabel() }} — {{ $incident->place?->name ?? $incident->building->name }}
        @if($incident->is_drill)<span class="badge text-bg-light border">تمرين</span>@endif
        <span class="badge text-bg-{{ $incident->getStatusColor() }}" id="statusBadge">{{ $incident->getStatusLabel() }}</span>
      </div>
      <div class="small">بدأت {{ $incident->triggered_at->format('Y-m-d H:i:s') }} · المدة <strong id="duration">{{ $incident->getDurationFormatted() }}</strong>
        · الخطورة {{ $incident->getSeverityLabel() }} · فعّلها {{ $incident->triggeredBy?->name ?? '—' }}
        · الإقرار: {{ $incident->acknowledged_at ? ($incident->acknowledgedBy?->name ?? '') .' '.$incident->acknowledged_at->format('H:i') : 'لم يُقرّ أحد بعد' }}
        @if($incident->escalation_level > 1) · <span class="badge text-bg-dark">تصعيد مستوى {{ $incident->escalation_level }}</span>@endif
      </div>
      @if($incident->description)<div class="small mt-1">{{ $incident->description }}</div>@endif
    </div>
    <div class="d-flex gap-1 flex-wrap">
      @if($open)
        @can('respond', $incident)
          @if(!$incident->acknowledged_at)<form method="post" action="{{ route('emergency.incidents.acknowledge', $incident) }}">@csrf<button class="btn btn-light btn-sm"><i class="bi bi-check2"></i> أقرّ بالاستلام</button></form>@endif
        @endcan
        @can('contain', $incident)@if($incident->status === 'active')<button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#containModal"><i class="bi bi-shield-check"></i> تمت السيطرة</button>@endif @endcan
        @can('reactivate', $incident)@if($incident->status === 'contained')<form method="post" action="{{ route('emergency.incidents.reactivate', $incident) }}">@csrf<button class="btn btn-danger btn-sm"><i class="bi bi-arrow-counterclockwise"></i> عادت الحالة</button></form>@endif @endcan
        @can('end', $incident)<button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#endModal"><i class="bi bi-check-circle"></i> انتهاء الخطر</button>@endcan
        @can('cancel', $incident)<button class="btn btn-outline-dark btn-sm" data-bs-toggle="modal" data-bs-target="#cancelModal">إلغاء</button>@endcan
      @else
        <a class="btn btn-light btn-sm" href="{{ route('emergency.incidents.report', $incident) }}"><i class="bi bi-file-text"></i> التقرير</a>
      @endif
      <a class="btn btn-outline-light btn-sm" href="{{ route('emergency.buildings.control', $incident->building) }}"><i class="bi bi-joystick"></i></a>
    </div>
  </div>
</div>

<div class="row g-2 mb-3">
  @foreach([['الإجمالي', 'total', ''], ['آمنون', 'safe', 'success'], ['قيد الإخلاء', 'evacuating', 'warning'], ['مفقودون', 'missing', 'danger'], ['مصابون', 'injured', 'danger'], ['يحتاجون مساعدة', 'needs_help', 'info']] as [$l, $k, $c])
    <div class="col-4 col-md-2"><div class="card text-center h-100 {{ $c ? 'border-'.$c : '' }}"><div class="card-body py-2"><div class="fs-4 fw-bold {{ $c ? 'text-'.$c : '' }}" id="stat-{{ str_replace('_', '-', $k) }}">{{ $stats[$k] }}</div><div class="small text-muted">{{ $l }}</div></div></div></div>
  @endforeach
</div>

{{-- خطوات خطة الاستجابة (المرحلة ١٠-٢): نسخة من خطة المكان لحظة التفعيل، كل خطوة بعدّاد من التفعيل، «تم» يسجّل بالثانية ومن --}}
@if($planSteps->isNotEmpty())
@php($RP = \App\Modules\Emergency\Models\ResponsePlan::class)
<div class="card mb-3 border-dark" id="planStepsCard">
  <div class="card-header bg-dark text-white d-flex align-items-center flex-wrap gap-2">
    <strong><i class="bi bi-list-ol"></i> خطوات الخطة — {{ $incident->place?->code }} {{ $incident->place?->name }}</strong>
    <span class="badge text-bg-light">تمت <span id="steps-done">{{ $planSteps->where('status', 'done')->count() }}</span> / {{ $planSteps->count() }}</span>
    <span class="badge text-bg-danger" id="steps-overdue-badge" @if(!$planSteps->filter->isOverdue()->count()) hidden @endif>متجاوزة <span id="steps-overdue">{{ $planSteps->filter->isOverdue()->count() }}</span></span>
    <span class="ms-auto small">العدّاد من لحظة التفعيل · النوافذ من وثيقة خطة الاستجابة · <a class="link-light" href="{{ route('emergency.plans.show', $incident->place?->code) }}">الخطة</a></span>
  </div>
  <div class="table-responsive"><table class="table table-sm m-0 small align-middle" id="planSteps">
    <thead><tr><th style="width:2.5rem">#</th><th>المسار</th><th>الخطوة</th><th style="width:20%">من → البطاقة</th><th>المستهدف</th><th style="width:16%">العدّاد / الحالة</th><th></th></tr></thead>
    <tbody>
    @foreach($planSteps as $s)
      <tr data-step="{{ $s->id }}" data-status="{{ $s->status }}" class="{{ $s->isDone() ? 'table-success' : ($s->status === 'skipped' ? 'table-secondary' : ($s->isOverdue() ? 'table-danger' : '')) }}">
        <td><strong>{{ $s->label }}</strong></td>
        <td class="text-muted text-nowrap">{{ $RP::PATHS[$s->path_key] ?? $s->path_key }}</td>
        <td><strong>{{ $s->title }}</strong>@if($s->where_text)<br><small class="text-muted">أين: {{ $s->where_text }}</small>@endif @if($s->how_text)<br><small class="text-muted">كيف: {{ $s->how_text }}</small>@endif</td>
        <td>@php($pc = $s->primaryCard())@if($pc)<a class="badge text-bg-dark text-decoration-none" href="{{ $pc['url'] }}" target="_blank">{{ $pc['no'] }} {{ $pc['name'] }}</a>@else<span class="badge text-bg-danger">بلا بطاقة</span>@endif<br><small class="text-muted">{{ $s->who_text }}</small></td>
        <td class="text-nowrap">{{ $s->when_text ?? '—' }}</td>
        <td class="text-nowrap step-state">
          @if($s->isDone())<span class="badge text-bg-success">تمت {{ $s->done_at->format('H:i:s') }}</span><br><small>{{ $s->deltaLabel() }}@if($s->auto_source) · آلياً ({{ \App\Modules\Emergency\Models\EmergencyIncidentStep::AUTO_LABELS[$s->auto_source] ?? $s->auto_source }})@endif @if($s->done_by_name) · {{ $s->done_by_name }}@endif</small>
          @elseif($s->status === 'skipped')<span class="badge text-bg-secondary">تُخطّيت</span>@if($s->note)<br><small>{{ $s->note }}</small>@endif
          @elseif($s->due_at)<span class="badge text-bg-{{ $s->isOverdue() ? 'danger' : 'warning' }} step-timer" data-due="{{ $s->due_at->getTimestamp() }}">…</span>
          @else<span class="badge text-bg-light border text-dark">شرطية — عند تحققها</span>@endif
        </td>
        <td class="text-end text-nowrap">
          @if($open && $s->isPending())@can('respond', $incident)
            <form method="post" action="{{ route('emergency.incidents.steps.done', [$incident, $s->id]) }}" class="d-inline">@csrf<button class="btn btn-sm btn-success py-0"><i class="bi bi-check2"></i> تم</button></form>
            <form method="post" action="{{ route('emergency.incidents.steps.skip', [$incident, $s->id]) }}" class="d-inline" onsubmit="const n=prompt('سبب التخطي');if(!n)return false;this.note.value=n;">@csrf<input type="hidden" name="note"><button class="btn btn-sm btn-outline-secondary py-0" title="تخطٍّ بسبب">تخطٍّ</button></form>
          @endcan @endif
        </td>
      </tr>
    @endforeach
    </tbody></table></div>
</div>
@endif

<div class="row g-3">
  <div class="col-lg-6">
    {{-- الفريق الأولي: تنبيه ← وصول --}}
    <div class="card mb-3 border-success">
      <div class="card-header bg-success text-white d-flex align-items-center"><strong><i class="bi bi-people-fill"></i> الفريق الأولي — الوصول</strong><span class="ms-auto badge text-bg-light">وصل <span id="stat-team-arrived">{{ $stats['team_arrived'] }}</span> / <span id="stat-team-total">{{ $stats['team_total'] }}</span></span></div>
      <div class="card-body p-0">
        @if($teamCheckIns->isEmpty())
          <div class="p-3 text-muted small">لا فريق أولي مرشَّح لهذا المكان في ملف المكان (اللوحة) — يُنادى الإسناد والقيادة.</div>
        @else
          <table class="table table-sm m-0 small">
            <thead><tr><th>الدور</th><th>الاسم</th><th>الهاتف</th><th>الحالة</th><th></th></tr></thead>
            <tbody>
            @foreach($teamCheckIns as $c)
              <tr class="{{ $c->isSafe() ? 'table-success' : '' }}" data-member="{{ $c->teamMember?->role_key }}" data-checkin="{{ $c->id }}">
                <td>{{ $c->teamMember?->getRoleKeyLabel() ?? $c->teamMember?->getRoleLabel() }}<br><small class="text-muted">{{ $c->teamMember?->team?->name }}</small></td>
                <td><strong>{{ $c->getPersonName() }}</strong>@if($c->teamMember?->department)<br><small class="text-muted">{{ $c->teamMember->department }}</small>@endif</td>
                <td>@if($c->getPersonPhone())<a href="tel:{{ $c->getPersonPhone() }}" class="btn btn-sm btn-outline-success py-0"><i class="bi bi-telephone"></i> {{ $c->getPersonPhone() }}</a>@else<span class="text-danger small">لا رقم</span>@endif</td>
                <td>@if($c->isSafe())<span class="badge text-bg-success">وصل {{ $c->checked_in_at?->format('H:i:s') }}</span>@else<span class="badge text-bg-warning">نُبّه — لم يصل</span>@endif</td>
                <td class="text-end">
                  @if($open && !$c->isSafe())@can('respond', $incident)
                    <form method="post" action="{{ route('emergency.incidents.checkin', $incident) }}" class="d-inline">@csrf<input type="hidden" name="check_in_id" value="{{ $c->id }}"><button class="btn btn-sm btn-success py-0"><i class="bi bi-check2"></i> وصل</button></form>
                  @endcan @endif
                </td>
              </tr>
            @endforeach
            </tbody>
          </table>
        @endif
      </div>
    </div>

    {{-- نداءات هاتفية يدوية --}}
    @if($manualCalls->isNotEmpty())
    <div class="card mb-3 border-warning">
      <div class="card-header bg-warning"><strong><i class="bi bi-telephone-outbound"></i> يُنادى هاتفياً من المركز</strong> <span class="small">(بلا حساب أو بريد)</span></div>
      <ul class="list-group list-group-flush small">
        @foreach($manualCalls as $n)
          @php($mySteps = $n->recipient_type === 'team_member' ? ($stepsByTeamKey[$memberKeys[$n->recipient_id] ?? ''] ?? []) : [])
          <li class="list-group-item d-flex justify-content-between align-items-center"><span>{{ $n->recipient_name }} <span class="text-muted">· {{ $n->recipient_type === 'contact' ? 'جهة اتصال' : 'عضو فريق' }}</span>
            @if($mySteps)<br><small class="text-muted">خطوته: @foreach($mySteps as $ms){{ $ms->label }} {{ $ms->title }} ({{ $ms->when_text }}){{ $loop->last ? '' : ' · ' }}@endforeach</small>@endif</span>@if($n->recipient_contact)<a href="tel:{{ $n->recipient_contact }}" class="btn btn-sm btn-outline-success py-0"><i class="bi bi-telephone"></i> {{ $n->recipient_contact }}</a>@endif</li>
        @endforeach
      </ul>
    </div>
    @endif

    {{-- المفقودون وطلبات المساعدة --}}
    <div class="card mb-3 {{ $missingPeople->isNotEmpty() ? 'border-danger' : '' }}">
      <div class="card-header bg-danger text-white d-flex align-items-center"><strong><i class="bi bi-person-x"></i> قيد الإخلاء / مفقودون</strong><span class="badge text-bg-light ms-auto">{{ $missingPeople->count() }}</span></div>
      <div class="card-body p-0" style="max-height:320px;overflow:auto">
        @if($missingPeople->isEmpty())<div class="text-center py-3 text-success"><i class="bi bi-check-circle"></i> الكل محصور</div>
        @else
          <table class="table table-sm m-0 small"><tbody>
          @foreach($missingPeople as $c)
            <tr class="{{ $c->status === 'missing' ? 'table-danger' : '' }}">
              <td><strong>{{ $c->getPersonName() }}</strong> <span class="text-muted">· {{ $c->getPersonTypeLabel() }}</span>@if($c->last_known_location)<br><small class="text-muted">{{ $c->last_known_location }}</small>@endif</td>
              <td><span class="badge text-bg-{{ $c->status === 'missing' ? 'danger' : 'warning' }}">{{ $c->getStatusLabel() }}</span></td>
              <td class="text-end text-nowrap">
                @if($open)@can('respond', $incident)
                  <form method="post" action="{{ route('emergency.incidents.checkin', $incident) }}" class="d-inline">@csrf<input type="hidden" name="check_in_id" value="{{ $c->id }}">
                    @if($incident->building->assemblyPoints->isNotEmpty())<select name="assembly_point_id" class="form-select form-select-sm d-inline w-auto py-0"><option value="">نقطة</option>@foreach($incident->building->assemblyPoints as $p)<option value="{{ $p->id }}">{{ $p->code }}</option>@endforeach</select>@endif
                    <button class="btn btn-sm btn-success py-0" title="وصل بأمان"><i class="bi bi-check2"></i></button></form>
                  @if($c->status !== 'missing')<form method="post" action="{{ route('emergency.incidents.markMissing', $incident) }}" class="d-inline">@csrf<input type="hidden" name="check_in_id" value="{{ $c->id }}"><button class="btn btn-sm btn-outline-danger py-0" title="مفقود"><i class="bi bi-question-lg"></i></button></form>@endif
                @endcan @endif
              </td>
            </tr>
          @endforeach
          </tbody></table>
        @endif
      </div>
      @if($open)@can('respond', $incident)
      <div class="card-footer">
        <form method="post" action="{{ route('emergency.incidents.reportMissing', $incident) }}" class="row g-1">@csrf
          <div class="col-4"><input name="person_name" class="form-control form-control-sm" placeholder="اسم مفقود (زائر/شاغل)" required></div>
          <div class="col-3"><input name="person_phone" class="form-control form-control-sm" placeholder="هاتف"></div>
          <div class="col-3"><input name="last_known_location" class="form-control form-control-sm" placeholder="آخر موقع"></div>
          <div class="col-2"><button class="btn btn-sm btn-outline-danger w-100">إبلاغ</button></div>
        </form>
      </div>
      @endcan @endif
    </div>

    @if($needHelp->isNotEmpty())
    <div class="card mb-3 border-warning">
      <div class="card-header bg-warning"><strong><i class="bi bi-exclamation-triangle"></i> يحتاجون مساعدة</strong> <span class="badge text-bg-dark">{{ $needHelp->count() }}</span></div>
      <ul class="list-group list-group-flush small">
        @foreach($needHelp as $c)<li class="list-group-item"><strong>{{ $c->getPersonName() }}</strong> — <span class="text-danger">{{ $c->getAssistanceTypeLabel() }}</span>@if($c->last_known_location) · {{ $c->last_known_location }}@endif @if($c->notes)<br><small class="text-muted">{{ $c->notes }}</small>@endif</li>@endforeach
      </ul>
    </div>
    @endif
  </div>

  <div class="col-lg-6">
    {{-- مسح رمز الوصول --}}
    @if($open)@can('respond', $incident)
    <div class="card mb-3">
      <div class="card-header"><strong><i class="bi bi-qr-code-scan"></i> تسجيل وصول برمز</strong> <span class="small text-muted">امسح رمز الشخص أو الصق نصه</span></div>
      <div class="card-body">
        <div class="input-group input-group-sm">
          <input id="qrToken" class="form-control" placeholder="نص الرمز أو رابطه" value="{{ session('scan_token') }}">
          @if($incident->building->assemblyPoints->isNotEmpty())<select id="qrPoint" class="form-select" style="max-width:120px"><option value="">النقطة</option>@foreach($incident->building->assemblyPoints as $p)<option value="{{ $p->id }}">{{ $p->code }}</option>@endforeach</select>@endif
          <button class="btn btn-g" type="button" id="qrBtn">تسجيل</button>
        </div>
        <div id="qrMsg" class="small mt-2"></div>
      </div>
    </div>
    @endcan @endif

    {{-- نقاط التجمع --}}
    <div class="card mb-3">
      <div class="card-header"><strong><i class="bi bi-geo-alt"></i> نقاط التجمع</strong></div>
      <ul class="list-group list-group-flush small">
        @forelse($incident->building->assemblyPoints as $point)
          @php($n = $byAssemblyPoint->firstWhere('assembly_point_id', $point->id)?->count ?? 0)
          <li class="list-group-item d-flex justify-content-between"><span><span class="badge text-bg-{{ $point->is_primary ? 'success' : 'secondary' }}">{{ $point->code }}</span> {{ $point->name }}</span><span class="badge text-bg-success">{{ $n }}</span></li>
        @empty
          <li class="list-group-item text-muted">لا نقاط تجمع مسجّلة (تُدخل من شاشة المبنى)</li>
        @endforelse
      </ul>
    </div>

    {{-- السجل الزمني --}}
    <div class="card">
      <div class="card-header d-flex align-items-center"><strong><i class="bi bi-list-ul"></i> السجل الزمني</strong><span class="ms-auto small text-muted" id="pollNote">يتحدث كل ٥ ثوانٍ</span></div>
      @if($open)@can('respond', $incident)
      <div class="card-body py-2 border-bottom">
        <form method="post" action="{{ route('emergency.incidents.note', $incident) }}" class="input-group input-group-sm">@csrf
          <input name="note" class="form-control" placeholder="ملاحظة في السجل (ما يحدث الآن)" required maxlength="1000">
          <select name="severity" class="form-select" style="max-width:110px"><option value="info">معلومة</option><option value="warning">تحذير</option><option value="critical">حرج</option></select>
          <button class="btn btn-g">أضف</button>
        </form>
      </div>
      @endcan @endif
      <ul class="list-group list-group-flush small" id="eventLog" style="max-height:420px;overflow:auto">
        @foreach($incident->eventLogs->sortByDesc('id') as $log)
          <li class="list-group-item py-2" data-id="{{ $log->id }}"><div class="d-flex justify-content-between"><div><span class="badge text-bg-{{ $log->severity === 'critical' ? 'danger' : ($log->severity === 'warning' ? 'warning' : 'info') }}">{{ $log->getTypeLabel() }}</span> {{ $log->message }}@if($log->user) <span class="text-muted">— {{ $log->user->name }}</span>@endif</div><small class="text-muted text-nowrap">{{ $log->logged_at?->format('H:i:s') }}</small></div></li>
        @endforeach
      </ul>
    </div>
  </div>
</div>

@if($open)
<div class="modal fade" id="containModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post" action="{{ route('emergency.incidents.contain', $incident) }}">@csrf
  <div class="modal-header"><h5 class="modal-title">تمت السيطرة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><p class="small text-muted">السيطرة لا تُنهي الحالة: يبقى الحصر مفتوحاً حتى «انتهاء الخطر».</p><textarea name="note" class="form-control" rows="2" placeholder="ما الذي تمت السيطرة عليه وكيف"></textarea></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-warning">تسجيل السيطرة</button></div>
</form></div></div></div>
<div class="modal fade" id="endModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post" action="{{ route('emergency.incidents.end', $incident) }}">@csrf
  <div class="modal-header"><h5 class="modal-title">انتهاء الخطر</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    @if($stats['missing'] > 0)<div class="alert alert-danger py-2">يوجد {{ $stats['missing'] }} مفقود. تأكد قبل الإنهاء.</div>@endif
    <label class="form-label">التقرير النهائي</label><textarea name="final_report" class="form-control" rows="4" placeholder="ما حدث، ما فُعل، النتيجة، ما يُصحَّح"></textarea>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-success">إنهاء وإعلان الأمان</button></div>
</form></div></div></div>
<div class="modal fade" id="cancelModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post" action="{{ route('emergency.incidents.cancel', $incident) }}">@csrf
  <div class="modal-header"><h5 class="modal-title">إلغاء الحالة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><label class="form-label">السبب (إنذار كاذب، تفعيل بالخطأ…) <span class="text-danger">*</span></label><input name="reason" class="form-control" required maxlength="500"></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">رجوع</button><button class="btn btn-dark">إلغاء الحالة</button></div>
</form></div></div></div>
@endif
@endsection

@push('scripts')
<script>
(function(){
  const incidentId={{ $incident->id }}, open={{ $open ? 'true' : 'false' }}, csrf=document.querySelector('meta[name=csrf-token]').content;
  const start=new Date('{{ $incident->triggered_at->toIso8601String() }}'), dur=document.getElementById('duration');
  @if($open)
  setInterval(()=>{const d=Math.floor((Date.now()-start)/1000);dur.textContent=[Math.floor(d/3600),Math.floor(d%3600/60),d%60].map(x=>String(x).padStart(2,'0')).join(':')},1000);
  @endif
  const sevClass={critical:'danger',warning:'warning',info:'info'};
  let lastId=Math.max(0,...[...document.querySelectorAll('#eventLog li')].map(li=>+li.dataset.id));
  async function poll(){
    try{
      const r=await fetch(`/api/emergency/incidents/${incidentId}/stats`,{headers:{'Accept':'application/json'},credentials:'same-origin'});
      if(r.ok){const j=await r.json();const s=j.data;['total','safe','evacuating','missing','injured','needs_help','team_arrived','team_total'].forEach(k=>{const el=document.getElementById('stat-'+k.replace(/_/g,'-'));if(el&&el.textContent!=String(s[k]))el.textContent=s[k];});
        if(open&&j.status!=='{{ $incident->status }}'){location.reload();}}
      const e=await fetch(`/api/emergency/incidents/${incidentId}/events?after=${lastId}`,{headers:{'Accept':'application/json'},credentials:'same-origin'});
      if(e.ok){const j=await e.json();const ul=document.getElementById('eventLog');(j.data||[]).forEach(ev=>{if(ev.id<=lastId)return;lastId=ev.id;const li=document.createElement('li');li.className='list-group-item py-2';li.dataset.id=ev.id;li.innerHTML=`<div class="d-flex justify-content-between"><div><span class="badge text-bg-${sevClass[ev.severity]||'info'}">${ev.type_label}</span> ${ev.message}${ev.user?' <span class="text-muted">— '+ev.user+'</span>':''}</div><small class="text-muted text-nowrap">${ev.at||''}</small></div>`;ul.prepend(li);});}
    }catch(err){}
  }
  if(open){setInterval(poll,5000);}
  // خطوات الخطة (١٠-٢): عدّاد لكل خطوة معلّقة من لحظة التفعيل؛ ما تجاوز نافذته يحمرّ؛ وتغيّر الحالة من جهاز آخر يعيد التحميل
  const fmt=s=>[Math.floor(s/3600),Math.floor(s%3600/60),s%60].map(x=>String(x).padStart(2,'0')).join(':');
  function tickSteps(){let over=0;document.querySelectorAll('.step-timer[data-due]').forEach(el=>{const left=Math.round(+el.dataset.due-Date.now()/1000);const tr=el.closest('tr');if(left>=0){el.textContent='متبقٍ '+fmt(left);el.className='badge text-bg-warning step-timer';}else{over++;el.textContent='متأخر +'+fmt(-left);el.className='badge text-bg-danger step-timer';if(tr)tr.classList.add('table-danger');}});
    const b=document.getElementById('steps-overdue-badge');if(b){b.hidden=over===0;document.getElementById('steps-overdue').textContent=over;}}
  if(document.getElementById('planSteps')){tickSteps();if(open)setInterval(tickSteps,1000);
    if(open)setInterval(async()=>{try{const r=await fetch(`/api/emergency/incidents/${incidentId}/steps`,{headers:{'Accept':'application/json'},credentials:'same-origin'});if(!r.ok)return;const j=await r.json();let changed=false;(j.data||[]).forEach(s=>{const tr=document.querySelector(`#planSteps tr[data-step="${s.id}"]`);if(tr&&tr.dataset.status!==s.status)changed=true;});if(changed)location.reload();}catch(e){}},5000);}
  const btn=document.getElementById('qrBtn');
  if(btn){btn.addEventListener('click',async()=>{
    let token=(document.getElementById('qrToken').value||'').trim();const m=token.match(/checkin\/([a-f0-9]{64})/);if(m)token=m[1];
    const point=document.getElementById('qrPoint')?document.getElementById('qrPoint').value:'';const msg=document.getElementById('qrMsg');
    if(!token){msg.innerHTML='<span class="text-danger">أدخل الرمز</span>';return;}
    const r=await fetch('/api/emergency/verify-qr',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify({qr_token:token,assembly_point_id:point||null})});
    const j=await r.json().catch(()=>({}));msg.innerHTML=`<span class="${r.ok?'text-success':'text-danger'}">${j.message||''}${j.data&&j.data.person_name?' — '+j.data.person_name:''}</span>`;
    if(r.ok){document.getElementById('qrToken').value='';setTimeout(()=>location.reload(),800);}
  });}
})();
</script>
@endpush
