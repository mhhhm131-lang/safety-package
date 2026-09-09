@extends('layouts.app')
@section('page_title', 'فحص جاهزية العامل')
@section('content')

<div class="d-flex align-items-center gap-2 mb-3">
  <h1 class="h4 m-0"><i class="bi bi-person-check"></i> فحص جاهزية العامل</h1>
  <a href="{{ route('permits.gate.logs') }}" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-list-ul"></i> السجل</a>
</div>

<div class="row g-3">
  <div class="col-md-5">
    <div class="card">
      <div class="card-header"><i class="bi bi-search"></i> ابحث عن العامل</div>
      <div class="card-body">
        <form id="gateForm">
          @csrf
          <div class="mb-3">
            <label class="form-label">رقم الهوية أو رقم العامل</label>
            <input type="text" id="wid" name="worker_identifier" class="form-control form-control-lg text-center"
                   dir="ltr" autofocus autocomplete="off" placeholder="1234567890">
          </div>
          <div class="mb-3">
            <label class="form-label">المكان (اختياري)</label>
            <select id="placeId" name="place_id" class="form-select">
              <option value="">— كل الأماكن —</option>
              @foreach($places as $p)<option value="{{ $p->id }}">{{ $p->code }} — {{ $p->name }}</option>@endforeach
            </select>
            <div class="form-text">تحديد المكان يفحص أن التصريح يغطيه.</div>
          </div>
          <button class="btn btn-g btn-lg w-100"><i class="bi bi-shield-check"></i> افحص</button>
        </form>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-header">فحوص اليوم</div>
      <div class="card-body">
        <div class="row text-center">
          <div class="col-4"><div class="h4 m-0 text-success" id="sAllowed">{{ $stats['allowed_today'] }}</div><small class="text-muted">سُمح</small></div>
          <div class="col-4"><div class="h4 m-0 text-danger" id="sDenied">{{ $stats['denied_today'] }}</div><small class="text-muted">مُنع</small></div>
          <div class="col-4"><div class="h4 m-0" id="sTotal">{{ $stats['total_today'] }}</div><small class="text-muted">الإجمالي</small></div>
        </div>
      </div>
    </div>
  </div>

  <div class="col-md-7">
    <div id="placeholder" class="text-center text-muted py-5">
      <i class="bi bi-person-badge" style="font-size:3rem;opacity:.3"></i>
      <div class="mt-2">أدخل رقم الهوية لعرض نتيجة الفحص.</div>
    </div>

    <div id="results" style="display:none">
      <div class="card mb-3" id="workerCard">
        <div class="card-body d-flex align-items-center gap-3">
          <i class="bi bi-person-circle" style="font-size:3rem;color:var(--g)"></i>
          <div>
            <div class="h5 m-0" id="wName">—</div>
            <div class="small text-muted">
              الهوية: <span id="wNid" dir="ltr">—</span> ·
              المهنة: <span id="wTrade">—</span> ·
              المقاول: <span id="wParty">—</span> ·
              الحالة: <span id="wStatus">—</span>
            </div>
          </div>
          <div class="ms-auto text-center">
            <div id="verdict"></div>
            <div id="permitBadge" class="mt-1 small"></div>
          </div>
        </div>
      </div>

      <div class="alert alert-danger py-2" id="reasonsBox" style="display:none">
        <strong><i class="bi bi-exclamation-triangle"></i> أسباب المنع:</strong>
        <ul class="mb-0 mt-1" id="reasons"></ul>
      </div>

      <div class="row g-2" id="checks"></div>
    </div>
  </div>
</div>

@push('scripts')
<script>
// أسماء الفحوص الستة بالترتيب الذي يراه المستخدم.
var CHECKS = [
  ['permit', 'تصريح نشط يغطيه', 'bi-file-earmark-check'],
  ['status', 'حالة العامل', 'bi-person-badge'],
  ['training', 'التدريب الإلزامي', 'bi-mortarboard'],
  ['certificates', 'الشهادات', 'bi-file-earmark-text'],
  ['medical', 'الفحص الطبي', 'bi-heart-pulse'],
  ['competency_gaps', 'كفاءات المهنة', 'bi-award'],
];

document.getElementById('gateForm').addEventListener('submit', function (e) {
  e.preventDefault();
  var id = document.getElementById('wid').value.trim();
  if (!id) return;

  fetch('{{ route('permits.gate.check') }}', {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
    },
    body: JSON.stringify({
      worker_identifier: id,
      place_id: document.getElementById('placeId').value || null
    })
  })
  .then(function (r) { return r.json(); })
  .then(function (d) {
    document.getElementById('placeholder').style.display = 'none';
    document.getElementById('results').style.display = 'block';

    var w = d.worker || {};
    document.getElementById('wName').textContent = w.name || 'عامل غير مسجَّل';
    document.getElementById('wNid').textContent = w.national_id || '—';
    document.getElementById('wTrade').textContent = w.trade || '—';
    document.getElementById('wParty').textContent = w.external_party || '—';
    document.getElementById('wStatus').textContent = w.status || '—';

    var card = document.getElementById('workerCard');
    document.getElementById('verdict').innerHTML = d.allowed
      ? '<span class="badge bg-success fs-6 p-2" data-verdict="allowed"><i class="bi bi-check-circle"></i> يُسمح بالعمل</span>'
      : '<span class="badge bg-danger fs-6 p-2" data-verdict="denied"><i class="bi bi-x-circle"></i> لا يُسمح</span>';
    card.className = 'card mb-3 border-2 border-' + (d.allowed ? 'success' : 'danger');

    var pb = document.getElementById('permitBadge');
    pb.innerHTML = d.permit_code
      ? (d.auto_permit
          ? '<span class="badge bg-info text-dark" data-auto="1"><i class="bi bi-magic"></i> تصريح فردي أُنشئ آلياً: ' + d.permit_code + '</span>'
          : '<span class="badge bg-light text-dark border" dir="ltr">' + d.permit_code + '</span>')
      : '';

    var box = document.getElementById('reasonsBox'), ul = document.getElementById('reasons');
    if (!d.allowed && d.denial_reasons.length) {
      ul.innerHTML = d.denial_reasons.map(function (r) { return '<li data-reason="' + r.code + '">' + r.label + '</li>'; }).join('');
      box.style.display = 'block';
    } else { box.style.display = 'none'; }

    document.getElementById('checks').innerHTML = CHECKS.map(function (c) {
      var chk = (d.checks || {})[c[0]];
      if (!chk) return '';
      var ok = chk.pass === true;
      return '<div class="col-md-6"><div class="card h-100 border-' + (ok ? 'success' : 'danger') + '" data-check="' + c[0] + '" data-pass="' + (ok ? 1 : 0) + '">'
        + '<div class="card-body py-2 d-flex align-items-center gap-2">'
        + '<i class="bi ' + c[2] + ' fs-4 text-' + (ok ? 'success' : 'danger') + '"></i>'
        + '<div class="small"><strong>' + c[1] + '</strong><div class="text-muted">' + (chk.detail || '') + '</div></div>'
        + '<i class="bi bi-' + (ok ? 'check-circle-fill text-success' : 'x-circle-fill text-danger') + ' ms-auto fs-5"></i>'
        + '</div></div></div>';
    }).join('');

    if (d.stats) {
      document.getElementById('sAllowed').textContent = d.stats.allowed_today;
      document.getElementById('sDenied').textContent = d.stats.denied_today;
      document.getElementById('sTotal').textContent = d.stats.total_today;
    }

    document.getElementById('wid').value = '';
    document.getElementById('wid').focus();
  })
  .catch(function () { alert('تعذّر الاتصال بالخادم.'); });
});
</script>
@endpush
@endsection
