@extends('layouts.public')
@section('page_title', 'أخطار المعهد')
@section('content')
{{-- المرحلة ١٢-٢ (قرار ٣٥): كتاب المعهد للتوعية — هذا خطر، إن رأيته بلّغ --}}
<div class="card p-4 mb-3">
  <div class="card-h mb-1"><i class="bi bi-book me-1"></i> أخطار المعهد — ما هي، وماذا تفعل إن رأيتها</div>
  <div class="text-muted small mb-3">{{ $total }} خطراً من كتاب المعهد. اقرأ، وإن رأيت خطراً اضغط «بلّغ» فيصل مركز السلامة والخطر محدد.</div>
  <form method="get" class="row g-2">
    <div class="col-md-7"><input name="q" class="form-control" value="{{ $q }}" placeholder="ابحث: حريق، سقوط، كهرباء، حرارة…"></div>
    <div class="col-md-3"><select name="place" class="form-select"><option value="">مكانك (اختياري)</option>@foreach($places as $p)<option value="{{ $p->code }}" @selected($place === $p->code)>{{ $p->code }} — {{ $p->name }}</option>@endforeach</select></div>
    <div class="col-md-2 d-grid"><button class="btn btn-g">ابحث</button></div>
  </form>
</div>
@forelse($tree as $cat => $subs)
  <div class="card mb-3" data-category="{{ $cat }}">
    <div class="card-header fw-bold">{{ $cat }} <span class="badge text-bg-light border">{{ $subs->flatten(1)->count() }}</span></div>
    <div class="card-body py-2">
      @foreach($subs as $sub => $risks)
        <div class="small text-muted fw-bold mt-2 mb-1">{{ $sub }}</div>
        <div class="list-group mb-2">
          @foreach($risks as $r)
            @php($op = $r->phases->first())
            <details class="list-group-item" data-risk="{{ $r->code }}">
              <summary class="d-flex align-items-center gap-2 flex-wrap" style="cursor:pointer;list-style:none">
                <span class="badge text-bg-secondary">{{ $r->code }}</span><span class="fw-bold flex-grow-1">{{ $r->title }}</span>
                <a class="btn btn-sm btn-red" href="{{ route('incident.form', 'normal') }}?risk={{ $r->id }}{{ $place ? '&place='.$place : '' }}"><i class="bi bi-megaphone-fill"></i> رأيت هذا؟ بلّغ</a>
              </summary>
              <div class="small mt-2">
                @if($r->description)<div class="mb-1">{{ $r->description }}</div>@endif
                @if($op?->preventive_action)<div><b>ما يقي منه:</b> <span style="white-space:pre-wrap">{{ $op->preventive_action }}</span></div>@endif
                @if($op?->corrective_action)<div><b>إن وقع:</b> <span style="white-space:pre-wrap">{{ $op->corrective_action }}</span></div>@endif
                @if(!$r->description && !$op?->preventive_action)<div class="text-muted">لا تفصيل بعد.</div>@endif
              </div>
            </details>
          @endforeach
        </div>
      @endforeach
    </div>
  </div>
@empty
  <div class="card p-4 text-center text-muted">لا خطر يطابق «{{ $q }}».</div>
@endforelse
@endsection
