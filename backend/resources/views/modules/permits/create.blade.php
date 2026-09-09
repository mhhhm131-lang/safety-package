@extends('layouts.app')
@section('page_title', 'تصريح جديد')
@section('content')

@php $steps = [1 => 'اختيار النوع', 2 => 'السياق والمخاطر', 3 => 'مراجعة البنود']; @endphp
<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
  @foreach($steps as $i => $label)
    <div class="d-flex align-items-center gap-1 {{ $step >= $i ? 'fw-bold' : 'text-muted' }}"
         style="{{ $step >= $i ? 'color:var(--g)' : '' }}">
      <i class="bi bi-{{ $i }}-circle{{ $step > $i ? '-fill' : '' }} fs-5"></i>{{ $label }}
    </div>
    @if(!$loop->last)<div class="flex-grow-1" style="max-width:60px;height:2px;background:{{ $step > $i ? 'var(--g)' : '#d9e2de' }}"></div>@endif
  @endforeach
</div>

@if($step === 1)
  {{-- ═══ خطوة ١: اختيار النوع ═══ --}}
  @php($byCat = $types->groupBy('category'))
  @foreach(\App\Modules\Permit\Models\PermitType::CATEGORIES as $cat => $catLabel)
    @if($byCat->has($cat))
      <h2 class="h6 mt-3 mb-2">{{ $catLabel }}</h2>
      <div class="row g-2">
        @foreach($byCat[$cat] as $t)
          <div class="col-md-4">
            <a href="{{ route('permits.create', ['step' => 2, 'permit_type_id' => $t->id]) }}" class="text-decoration-none">
              <div class="card h-100" data-type="{{ $t->code }}">
                <div class="card-body py-2">
                  <div class="fw-bold">{{ $t->name }}
                    @if($t->two_stage_approval)<span class="badge bg-primary" style="font-size:.6rem">اعتماد بمرحلتين</span>@endif
                  </div>
                  <small class="text-muted d-block">{{ \Illuminate\Support\Str::limit($t->description, 95) }}</small>
                  @if($t->default_validity_days)<small class="text-muted">الصلاحية: {{ $t->default_validity_days }} يوماً</small>@endif
                </div>
              </div>
            </a>
          </div>
        @endforeach
      </div>
    @endif
  @endforeach
@endif

@if($step === 2 && $type)
  {{-- ═══ خطوة ٢: السياق ═══ --}}
  <div class="card">
    <div class="card-header d-flex align-items-center">
      <strong>{{ $type->name }}</strong>
      <span class="badge bg-light text-dark ms-2">{{ $type->getCategoryLabel() }}</span>
      <a href="{{ route('permits.create') }}" class="btn btn-sm btn-outline-secondary ms-auto">تغيير النوع</a>
    </div>
    <form method="post" action="{{ route('permits.store') }}">
      @csrf
      <input type="hidden" name="permit_type_id" value="{{ $type->id }}">
      <div class="card-body">
        @if($type->description)<div class="alert alert-light py-2 small">{{ $type->description }}</div>@endif
        <div class="row g-3">

          <div class="col-12">
            <label class="form-label">عنوان التصريح <span class="text-danger">*</span></label>
            <input type="text" name="title" class="form-control" required maxlength="200"
                   value="{{ old('title') }}" placeholder="مثال: لحام دعامة في غرفة الكهرباء">
          </div>

          <div class="col-md-6">
            <label class="form-label">المكان {!! $type->requires_place ? '<span class="text-danger">*</span>' : '' !!}</label>
            <select name="place_id" class="form-select" @required($type->requires_place)>
              <option value="">— اختر المكان —</option>
              @foreach($places as $p)
                <option value="{{ $p->id }}" @selected(old('place_id') == $p->id)>{{ $p->code }} — {{ $p->name }}</option>
              @endforeach
            </select>
            <div class="form-text">منطقة العمل هي أحد أماكن المعهد التسعة. مخاطر المكان الفعّالة تُربط تلقائياً.</div>
          </div>

          <div class="col-md-6">
            <label class="form-label">الموضع الدقيق داخل المكان</label>
            <input type="text" name="sub_location" class="form-control" maxlength="200"
                   value="{{ old('sub_location') }}" placeholder="مثال: اللوحة الرئيسية ٧، الخزان ب-٣">
          </div>

          @if($type->requires_project || $projects->isNotEmpty())
            <div class="col-md-6">
              <label class="form-label">المشروع {!! $type->requires_project ? '<span class="text-danger">*</span>' : '' !!}</label>
              <select name="project_id" class="form-select" @required($type->requires_project)>
                <option value="">— {{ $type->requires_project ? 'اختر' : 'اختياري' }} —</option>
                @foreach($projects as $pr)
                  <option value="{{ $pr->id }}" @selected(old('project_id') == $pr->id)>{{ $pr->name }}</option>
                @endforeach
              </select>
            </div>
          @endif

          @if($type->requires_contractor || $parties->isNotEmpty())
            <div class="col-md-6">
              <label class="form-label">المقاول / الطرف الخارجي {!! $type->requires_contractor ? '<span class="text-danger">*</span>' : '' !!}</label>
              <select name="external_party_id" class="form-select" @required($type->requires_contractor)>
                <option value="">— {{ $type->requires_contractor ? 'اختر' : 'اختياري' }} —</option>
                @foreach($parties as $party)
                  <option value="{{ $party->id }}" @selected(old('external_party_id') == $party->id)>{{ $party->name }}</option>
                @endforeach
              </select>
            </div>
          @endif

          @if($type->requires_worker)
            <div class="col-md-6">
              <label class="form-label">العامل <span class="text-danger">*</span></label>
              <input type="hidden" name="subject_type" value="Worker">
              <select name="subject_id" class="form-select" required>
                <option value="">— اختر العامل —</option>
                @foreach($workers as $w)
                  <option value="{{ $w->id }}" @selected(old('subject_id') == $w->id)>{{ $w->full_name }} — {{ $w->national_id }}</option>
                @endforeach
              </select>
            </div>
          @endif

          @if($type->requires_equipment)
            <div class="col-md-6">
              <label class="form-label">المعدة <span class="text-danger">*</span></label>
              <input type="hidden" name="subject_type" value="Equipment">
              <select name="subject_id" class="form-select" required>
                <option value="">— اختر المعدة —</option>
                @foreach($equipmentList as $eq)
                  <option value="{{ $eq->id }}" @selected(old('subject_id') == $eq->id)>{{ $eq->name }} @if($eq->code)({{ $eq->code }})@endif</option>
                @endforeach
              </select>
              @if($equipmentList->isEmpty())
                <div class="form-text text-danger">لا معدات مسجَّلة — سجّلها أولاً من شاشة المعدات.</div>
              @endif
            </div>
          @endif

          <div class="col-md-4">
            <label class="form-label">النطاق</label>
            <select name="scope" class="form-select">
              <option value="">— بلا نطاق —</option>
              @foreach(\App\Modules\Permit\Models\Permit::SCOPE_LABELS as $k => $v)
                <option value="{{ $k }}" @selected(old('scope', $scopeVal) === $k)>{{ $v }}</option>
              @endforeach
            </select>
            <div class="form-text">موضع التصريح في التسلسل: مشروع ← تأهيل ← تشغيلي ← فرد.</div>
          </div>

          @if($parentPermits->isNotEmpty())
            <div class="col-md-4">
              <label class="form-label">التصريح الأب</label>
              <select name="parent_permit_id" class="form-select">
                <option value="">— لا يوجد —</option>
                @foreach($parentPermits as $pp)
                  <option value="{{ $pp->id }}" @selected(old('parent_permit_id') == $pp->id)>{{ $pp->code }} — {{ \Illuminate\Support\Str::limit($pp->title, 45) }}</option>
                @endforeach
              </select>
              <div class="form-text">يرث الابن مخاطر أبيه تلقائياً.</div>
            </div>
          @endif

          <div class="col-md-4">
            <label class="form-label">الوحدة التنظيمية</label>
            <select name="organization_unit_id" class="form-select">
              <option value="">— اختياري —</option>
              @foreach($orgUnits as $u)
                <option value="{{ $u->id }}" @selected(old('organization_unit_id') == $u->id)>{{ $u->name }}</option>
              @endforeach
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">عدد العمال</label>
            <input type="number" name="workers_count" class="form-control" min="0" max="9999" value="{{ old('workers_count') }}">
          </div>
          <div class="col-md-3">
            <label class="form-label">عدد المعدات</label>
            <input type="number" name="equipment_count" class="form-control" min="0" max="9999" value="{{ old('equipment_count') }}">
          </div>
          <div class="col-md-3">
            <label class="form-label">يبدأ</label>
            <input type="datetime-local" name="starts_at" class="form-control" value="{{ old('starts_at') }}">
          </div>
          <div class="col-md-3">
            <label class="form-label">ينتهي</label>
            <input type="datetime-local" name="expires_at" class="form-control" value="{{ old('expires_at') }}">
            @if($type->default_validity_days)
              <div class="form-text">يُحسب من الصلاحية ({{ $type->default_validity_days }} يوماً) إن تُرك فارغاً.</div>
            @endif
          </div>

          @if($trades->isNotEmpty())
            <div class="col-12">
              <label class="form-label">المهن المعنية</label>
              <div class="border rounded p-2" style="max-height:180px;overflow:auto">
                <div class="row g-1">
                  @foreach($trades as $tr)
                    <div class="col-md-3 col-6">
                      <label class="d-flex align-items-center gap-1 small">
                        <input type="checkbox" name="trade_ids[]" value="{{ $tr->id }}" class="form-check-input mt-0"
                               @checked(in_array($tr->id, old('trade_ids', [])))>
                        {{ $tr->name }}
                      </label>
                    </div>
                  @endforeach
                </div>
              </div>
              <div class="form-text">اختياري — تضيّق بنود التحكم المقترحة على ما يخص هذه التخصصات.</div>
            </div>
          @endif

          <div class="col-12">
            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none"
                    onclick="document.getElementById('more').classList.toggle('d-none')">
              <i class="bi bi-chevron-down"></i> تفاصيل إضافية (وصف، احتياطات، مقدّم الطلب)
            </button>
          </div>
          <div id="more" class="col-12 d-none">
            <div class="row g-3">
              <div class="col-12">
                <label class="form-label">وصف العمل</label>
                <textarea name="description" class="form-control" rows="2" maxlength="2000">{{ old('description') }}</textarea>
              </div>
              <div class="col-md-6">
                <label class="form-label">وصف الموقع</label>
                <textarea name="location_description" class="form-control" rows="2" maxlength="1000">{{ old('location_description') }}</textarea>
              </div>
              <div class="col-md-6">
                <label class="form-label">الاحتياطات المطلوبة</label>
                <textarea name="precautions" class="form-control" rows="2" maxlength="2000">{{ old('precautions') }}</textarea>
              </div>
              <div class="col-md-6">
                <label class="form-label">اسم مقدّم الطلب</label>
                <input type="text" name="requester_name" class="form-control" maxlength="150" value="{{ old('requester_name') }}">
              </div>
              <div class="col-md-6">
                <label class="form-label">هاتف مقدّم الطلب</label>
                <input type="text" name="requester_phone" class="form-control" maxlength="30" value="{{ old('requester_phone') }}" dir="ltr">
              </div>
            </div>
          </div>

        </div>
      </div>
      <div class="card-footer d-flex justify-content-between">
        <a href="{{ route('permits.create') }}" class="btn btn-outline-secondary">رجوع</a>
        <button type="submit" class="btn btn-g">إنشاء المسودة ومراجعة البنود</button>
      </div>
    </form>
  </div>
@endif

@endsection
