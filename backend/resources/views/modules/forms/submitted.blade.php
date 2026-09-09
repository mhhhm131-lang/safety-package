@extends('layouts.app')
@section('page_title', 'سُجّلت التعبئة')
@section('content')

<div class="row justify-content-center">
  <div class="col-lg-6">
    <div class="card text-center">
      <div class="card-body py-5">
        <i class="bi bi-check-circle-fill text-success" style="font-size:3rem"></i>
        <h1 class="h5 mt-3">سُجّلت تعبئتك</h1>
        <p class="text-muted mb-1">{{ $form->title }}</p>
        <p class="small text-muted" dir="ltr">{{ $submission->submitted_at?->format('Y-m-d H:i') }}</p>

        @if($form->requiresSignature())
          <div class="alert alert-light border small d-inline-block mt-2">
            <i class="bi bi-vector-pen"></i> حُفظ إقرارك الموقَّع في سجل السلامة.
          </div>
        @endif

        <div class="d-flex gap-2 justify-content-center mt-3">
          <a href="{{ route('forms.mine') }}" class="btn btn-g">نماذجي</a>
          <a href="/app" class="btn btn-outline-secondary">الرئيسية</a>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
