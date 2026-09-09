{{-- شريط المرشّحات: المدة والمكان. المدة الافتراضية الشهر الجاري (من التقويم لا رقماً مخترعاً). --}}
<form method="get" class="card mb-3">
  <div class="card-body py-2 d-flex flex-wrap align-items-end gap-2">
    <div>
      <label class="form-label small mb-0 text-muted">من</label>
      <input type="date" name="from" class="form-control form-control-sm" value="{{ $scope->from->format('Y-m-d') }}">
    </div>
    <div>
      <label class="form-label small mb-0 text-muted">إلى</label>
      <input type="date" name="to" class="form-control form-control-sm" value="{{ $scope->to->format('Y-m-d') }}">
    </div>
    <div>
      <label class="form-label small mb-0 text-muted">المكان</label>
      <select name="place_id" class="form-select form-select-sm">
        <option value="">كل الأماكن</option>
        @foreach($places as $place)
          <option value="{{ $place->id }}" @selected($scope->placeId === $place->id)>{{ $place->code }} — {{ $place->name }}</option>
        @endforeach
      </select>
    </div>
    <button class="btn btn-sm btn-g"><i class="bi bi-funnel"></i> اعرض</button>
    <span class="small text-muted ms-auto" data-scope-days="{{ $scope->days() }}">
      المدة: {{ $scope->label() }} ({{ $scope->days() }} يوماً)
    </span>
  </div>
</form>
