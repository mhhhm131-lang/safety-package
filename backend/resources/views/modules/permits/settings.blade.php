@extends('layouts.app')
@section('page_title', 'سعة الأماكن وقواعد التعارض')
@section('content')

<div class="d-flex align-items-center gap-2 mb-3">
  <h1 class="h4 m-0"><i class="bi bi-sliders"></i> سعة الأماكن وقواعد التعارض</h1>
  <a href="{{ route('permits.dashboard') }}" class="btn btn-sm btn-outline-secondary ms-auto">اللوحة</a>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card">
      <div class="card-header"><i class="bi bi-geo-alt"></i> سعة الأماكن التسعة</div>
      <div class="card-body p-0">
        <div class="alert alert-light border m-3 py-2 small">
          <i class="bi bi-info-circle"></i>
          الحد الأقصى قرار المستخدم — لا قيم افتراضية. المكان بلا حد لا تُفحص سعته،
          والمكان بحدّ يمنع اعتماد تصريح يتجاوزه.
        </div>
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>المكان</th><th>حد العمال</th><th>حد المعدات</th><th></th></tr></thead>
          <tbody>
            @foreach($places as $p)
              <tr data-place="{{ $p->code }}">
                <form method="post" action="{{ route('permits.settings.capacity', $p) }}">
                  @csrf @method('PUT')
                  <td class="small"><span dir="ltr" class="text-muted">{{ $p->code }}</span> {{ $p->name }}</td>
                  <td style="width:110px">
                    <input type="number" name="max_workers" class="form-control form-control-sm" min="0" max="9999"
                           value="{{ $p->max_workers }}" placeholder="بلا حد">
                  </td>
                  <td style="width:110px">
                    <input type="number" name="max_equipment" class="form-control form-control-sm" min="0" max="9999"
                           value="{{ $p->max_equipment }}" placeholder="بلا حد">
                  </td>
                  <td><button class="btn btn-sm btn-outline-primary py-0"><i class="bi bi-save"></i></button></td>
                </form>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-plus-circle"></i> إضافة قاعدة تعارض</div>
      <div class="card-body">
        <form method="post" action="{{ route('permits.settings.rules.store') }}" class="row g-2">
          @csrf
          <div class="col-md-6">
            <label class="form-label small mb-1">النوع (أ)</label>
            <select name="permit_type_a_id" class="form-select form-select-sm" required>
              <option value="">— اختر —</option>
              @foreach($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-1">لا يتزامن مع (ب)</label>
            <select name="permit_type_b_id" class="form-select form-select-sm" required>
              <option value="">— اختر —</option>
              @foreach($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-1">المكان</label>
            <select name="place_id" class="form-select form-select-sm">
              <option value="">كل الأماكن (قاعدة عامة)</option>
              @foreach($places as $p)<option value="{{ $p->id }}">{{ $p->code }} — {{ $p->name }}</option>@endforeach
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small mb-1">الشدة</label>
            <select name="severity" class="form-select form-select-sm">
              <option value="block">مانع (يوقف الاعتماد والتفعيل)</option>
              <option value="warn">تنبيه (يظهر ولا يمنع)</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label small mb-1">السبب</label>
            <input type="text" name="reason" class="form-control form-control-sm" maxlength="500"
                   placeholder="لماذا لا يجوز التزامن؟ يظهر لمن يحاول الاعتماد.">
          </div>
          <div class="col-12"><button class="btn btn-sm btn-g">حفظ القاعدة</button></div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-shuffle"></i> القواعد المسجَّلة ({{ $rules->count() }})</div>
      <div class="card-body p-0">
        @forelse($rules as $rule)
          <div class="px-3 py-2 border-bottom small {{ $rule->is_active ? '' : 'opacity-50' }}" data-rule="{{ $rule->id }}">
            <div class="d-flex align-items-center gap-2">
              <span class="badge {{ $rule->severity === 'block' ? 'bg-danger' : 'bg-warning text-dark' }}">{{ $rule->getSeverityLabel() }}</span>
              <span class="flex-grow-1">
                {{ $rule->permitTypeA?->name }} <i class="bi bi-x text-danger"></i> {{ $rule->permitTypeB?->name }}
                <span class="text-muted">· {{ $rule->place?->name ?? 'كل الأماكن' }}</span>
              </span>
              <form method="post" action="{{ route('permits.settings.rules.toggle', $rule) }}">
                @csrf
                <button class="btn btn-sm py-0 {{ $rule->is_active ? 'btn-outline-secondary' : 'btn-outline-success' }}">
                  {{ $rule->is_active ? 'إيقاف' : 'تفعيل' }}
                </button>
              </form>
            </div>
            @if($rule->reason)<div class="text-muted mt-1">{{ $rule->reason }}</div>@endif
          </div>
        @empty
          <div class="text-center text-muted py-3 small">لا قواعد.</div>
        @endforelse
      </div>
    </div>
  </div>
</div>
@endsection
