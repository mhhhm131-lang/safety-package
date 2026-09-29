@extends('layouts.app')
@section('page_title', 'بلاغاتي')
@section('content')
{{-- ٢٦-٨ (قرار ٦٧): «أتابع بلاغاتي» — ما بلّغ عنه صاحب الحساب بنفسه وحالة كل بلاغ؛ كان لا يجده إلا من الإشعار --}}
@php($S = \App\Modules\Incident\Models\Incident::class)
<h1 class="h4 mb-1"><i class="bi bi-megaphone-fill me-2"></i>بلاغاتي <span class="badge text-bg-dark" id="myReportsCount">{{ $incidents->count() }}</span></h1>
<p class="small text-muted mb-3">ما بلّغتَ عنه بحسابك، وحالة كل بلاغ. البلاغ السري لا يُنسب إليك فلا يظهر هنا؛ تابعه برمز التتبع.</p>

@if($incidents->isEmpty())
  <div class="card" id="myReportsEmpty"><div class="card-body text-center text-muted py-5">
    <i class="bi bi-check-circle fs-1 d-block mb-2 text-success"></i>
    لا بلاغات باسمك. تبلّغ من صفحة الرؤية أو من رمز المكان.
  </div></div>
@else
  <div class="d-grid gap-2" id="myReportsList">
  @foreach($incidents as $i)
    <a class="card text-decoration-none text-reset" href="{{ route('incidents.show', $i) }}" data-incident="{{ $i->id }}">
      <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
        <span class="fw-bold" dir="ltr">{{ $i->code }}</span>
        <span class="flex-grow-1">{{ $i->title ?: \Illuminate\Support\Str::limit((string) $i->description, 80) }}</span>
        <span class="small text-muted">{{ $i->place?->name }}{{ $i->placeUnit ? ' · '.$i->placeUnit->name : '' }} · {{ $i->created_at?->format('Y-m-d') }}</span>
        <span class="badge text-bg-{{ $S::STATUS_COLORS[$i->status] ?? 'secondary' }}">{{ $S::STATUS_LABELS[$i->status] ?? $i->status }}</span>
        @if($i->status === 'resolved' && $i->pending_closure && !$i->reporter_approved_closure)<span class="badge text-bg-warning">ينتظر موافقتك على الإغلاق</span>@endif
      </div>
    </a>
  @endforeach
  </div>
@endif
@endsection
