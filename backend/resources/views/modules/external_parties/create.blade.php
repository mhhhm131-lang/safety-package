@extends('layouts.app')
@section('page_title', 'إضافة طرف خارجي')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 m-0"><i class="bi bi-building-add me-2"></i> إضافة طرف خارجي</h1>
  <a href="{{ route('external-parties.index') }}" class="btn btn-outline-secondary btn-sm">رجوع</a>
</div>
@if($errors->any())<div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>@endif
<div class="card"><div class="card-body">
  <form method="POST" action="{{ route('external-parties.store') }}">
    @csrf
    @include('modules.external_parties._form')
    <div class="d-flex gap-2"><button type="submit" class="btn btn-g px-4">حفظ</button><a href="{{ route('external-parties.index') }}" class="btn btn-outline-secondary px-4">إلغاء</a></div>
  </form>
</div></div>
@endsection
