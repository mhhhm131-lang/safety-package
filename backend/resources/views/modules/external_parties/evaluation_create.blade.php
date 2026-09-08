@extends('layouts.app')
@section('page_title', 'تقييم '.$externalParty->name)
@section('content')
<div class="d-flex align-items-center gap-2 mb-3">
  <h1 class="h4 m-0"><i class="bi bi-clipboard-check me-2"></i>تقييم أداء: {{ $externalParty->name }}</h1>
  <a href="{{ route('external-parties.show', $externalParty) }}" class="btn btn-sm btn-outline-secondary ms-auto">رجوع</a>
</div>
@if($errors->any())<div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>@endif
<form method="POST" action="{{ route('external-parties.evaluation.store', $externalParty) }}" class="card" style="max-width:760px"><div class="card-body row g-3">
  @csrf
  <div class="col-md-6"><label class="form-label">من</label><input type="date" name="period_from" class="form-control" value="{{ old('period_from') }}" required></div>
  <div class="col-md-6"><label class="form-label">إلى</label><input type="date" name="period_to" class="form-control" value="{{ old('period_to') }}" required></div>
  <div class="col-md-4"><label class="form-label">السلامة (٠–١٠٠)</label><input type="number" name="safety_score" class="form-control" min="0" max="100" value="{{ old('safety_score') }}" required></div>
  <div class="col-md-4"><label class="form-label">الجودة (٠–١٠٠)</label><input type="number" name="quality_score" class="form-control" min="0" max="100" value="{{ old('quality_score') }}" required></div>
  <div class="col-md-4"><label class="form-label">الامتثال (٠–١٠٠)</label><input type="number" name="compliance_score" class="form-control" min="0" max="100" value="{{ old('compliance_score') }}" required></div>
  <div class="col-md-6"><label class="form-label">المشروع (اختياري)</label><select name="project_id" class="form-select"><option value="">—</option>@foreach($projects as $pr)<option value="{{ $pr->id }}" @selected(old('project_id') == $pr->id)>{{ $pr->name }}</option>@endforeach</select></div>
  <div class="col-12"><label class="form-label">ملاحظات</label><textarea name="notes" class="form-control" rows="3">{{ old('notes') }}</textarea></div>
  <div class="col-12 d-flex gap-2"><button class="btn btn-g">حفظ التقييم</button><a class="btn btn-outline-secondary" href="{{ route('external-parties.show', $externalParty) }}">إلغاء</a></div>
</div></form>
@endsection
