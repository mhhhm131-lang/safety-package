@extends('layouts.app')
@section('page_title', 'إضافة مشروع')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 m-0"><i class="bi bi-kanban me-2"></i> إضافة مشروع</h1>
  <a href="{{ route('projects.index') }}" class="btn btn-outline-secondary btn-sm">رجوع</a>
</div>
@if($errors->any())<div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>@endif
<div class="card"><div class="card-body">
  <form method="POST" action="{{ route('projects.store') }}">
    @csrf
    @include('modules.projects._form')
    <div class="d-flex gap-2"><button type="submit" class="btn btn-g px-4">حفظ المشروع</button><a href="{{ route('projects.index') }}" class="btn btn-outline-secondary px-4">إلغاء</a></div>
  </form>
</div></div>
@endsection
