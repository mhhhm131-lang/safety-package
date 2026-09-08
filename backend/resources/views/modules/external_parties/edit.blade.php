@extends('layouts.app')
@section('page_title', 'تعديل طرف خارجي')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 m-0"><i class="bi bi-pencil-square me-2"></i> تعديل: {{ $externalParty->name }}</h1>
  <a href="{{ route('external-parties.show', $externalParty) }}" class="btn btn-outline-secondary btn-sm">رجوع</a>
</div>
@if($errors->any())<div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>@endif
<div class="card"><div class="card-body">
  <form method="POST" action="{{ route('external-parties.update', $externalParty) }}">
    @csrf @method('PUT')
    @include('modules.external_parties._form')
    <div class="d-flex gap-2"><button type="submit" class="btn btn-g px-4">حفظ</button><a href="{{ route('external-parties.show', $externalParty) }}" class="btn btn-outline-secondary px-4">إلغاء</a></div>
  </form>
</div></div>
@endsection
