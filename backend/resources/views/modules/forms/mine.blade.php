@extends('layouts.app')
@section('page_title', 'نماذجي')
@section('content')

<h1 class="h4 mb-3"><i class="bi bi-inbox me-2"></i>النماذج المكلَّف بها</h1>

@php
  $open = $assignments->filter(fn($a) => $a->isOpen());
  $done = $assignments->reject(fn($a) => $a->isOpen());
@endphp

@if($open->isEmpty() && $done->isEmpty())
  <div class="card"><div class="card-body text-center text-muted py-5">
    <i class="bi bi-check-circle fs-1 d-block mb-2 text-success"></i>
    لا نماذج مكلَّف بها.
  </div></div>
@endif

@if($open->isNotEmpty())
  <h2 class="h6 mb-2">بانتظار تعبئتك <span class="badge bg-warning text-dark" data-open="{{ $open->count() }}">{{ $open->count() }}</span></h2>
  <div class="row g-2 mb-4">
    @foreach($open as $a)
      <div class="col-md-6">
        <div class="card h-100 {{ $a->status === 'overdue' ? 'border-danger' : '' }}" data-assignment="{{ $a->id }}">
          <div class="card-body">
            <div class="d-flex align-items-start gap-2 mb-2">
              <div class="flex-grow-1">
                <div class="fw-bold">{{ $a->form?->title }}</div>
                @include('modules.forms._type', ['type' => $a->form?->form_type ?? 'custom'])
              </div>
              <span class="badge bg-{{ $a->status === 'overdue' ? 'danger' : 'warning text-dark' }}">
                {{ $a->getStatusLabel() }}
              </span>
            </div>
            @if($a->due_date)
              <div class="small {{ $a->due_date->isPast() ? 'text-danger fw-bold' : 'text-muted' }}">
                <i class="bi bi-calendar-event"></i> المهلة: {{ $a->due_date->format('Y-m-d') }}
                @if($a->due_date->isPast())(تجاوزتها)@endif
              </div>
            @endif
            @if($a->form?->is_active)
              <a href="{{ route('forms.fill', $a->form) }}" class="btn btn-sm btn-g mt-2 w-100">
                <i class="bi bi-pencil-square"></i> عبّئ الآن
              </a>
            @else
              <div class="small text-muted mt-2">النموذج موقوف مؤقتاً.</div>
            @endif
          </div>
        </div>
      </div>
    @endforeach
  </div>
@endif

@if($done->isNotEmpty())
  <h2 class="h6 mb-2 text-muted">عبّأتها</h2>
  <div class="card">
    <div class="card-body p-0">
      @foreach($done as $a)
        <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom small">
          <i class="bi bi-check-circle-fill text-success"></i>
          <span class="flex-grow-1">{{ $a->form?->title }}</span>
          <span class="text-muted">{{ $a->completed_at?->format('Y-m-d H:i') ?? '' }}</span>
        </div>
      @endforeach
    </div>
  </div>
@endif
@endsection
