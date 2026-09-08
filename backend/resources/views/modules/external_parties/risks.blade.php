@extends('layouts.app')
@section('page_title', 'مخاطر '.$externalParty->name)
@section('content')
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h4 m-0"><i class="bi bi-shield-exclamation me-2"></i>مخاطر الطرف: {{ $externalParty->name }} <span class="badge bg-secondary">{{ $risks->total() }}</span></h1>
  <a href="{{ route('external-parties.show', $externalParty) }}" class="btn btn-sm btn-outline-secondary ms-auto">رجوع</a>
</div>
@if(auth()->user()->can_('external_party.edit'))
<form method="POST" action="{{ route('external-parties.risks.store', $externalParty) }}" class="card mb-3"><div class="card-body d-flex gap-2 align-items-end flex-wrap">
  @csrf
  <div class="flex-grow-1"><label class="form-label small">ربط خطر من السجل العام</label>
    <select name="risk_id" class="form-select form-select-sm" required><option value="">— اختر —</option>@foreach($registry as $r)<option value="{{ $r->id }}">{{ $r->code }} — {{ $r->title }}</option>@endforeach</select></div>
  <button class="btn btn-sm btn-g">ربط</button>
</div></form>
@endif
@include('modules.risks.partials._risk_table', ['risks' => $risks, 'registry_type' => 'reference', 'can_edit' => false])
@endsection
