@extends('layouts.app')
@section('page_title', 'جولات بطاقة الإدارة')
@section('content')

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h4 m-0"><i class="bi bi-ui-checks me-2"></i>النماذج الرقمية</h1>
  <a href="{{ route('forms.mine') }}" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-inbox"></i> نماذجي</a>
</div>

{{-- ٢٩-٧: التبويبان --}}
<ul class="nav nav-tabs mb-3" data-forms-tabs>
  <li class="nav-item"><a class="nav-link" href="{{ route('forms.index') }}" data-tab="forms"><i class="bi bi-ui-checks"></i> النماذج</a></li>
  <li class="nav-item"><a class="nav-link active" href="{{ route('forms.rounds.index') }}" data-tab="layer2"><i class="bi bi-layers"></i> الطبقة الثانية — بطاقة الإدارة</a></li>
</ul>

{{-- ٢٩-٧: البطاقة الواحدة (قرار ٨٨) --}}
<div class="card mb-3" data-card="{{ $card?->id }}">
  <div class="card-body d-flex flex-wrap gap-3 align-items-center">
    <div class="flex-grow-1">
      <div class="fw-bold"><i class="bi bi-card-checklist"></i> {{ $card?->title ?? 'بطاقة الإدارة' }}</div>
      <div class="small text-muted">سبعة بنود × أربع مراحل (موثّق، معلن، مفهوم، مطبَّق). يعبّئها مدير الإدارة في جولة، ويعتمدها، فيصل موظفيها الإقرار السري آلياً، ثم الأرقام. النظام يسأل ولا يبني ولا يحاسب.</div>
    </div>
    @if($card)<a href="{{ route('forms.show', $card) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> بنود البطاقة</a>@endif
  </div>
</div>

<h2 class="h6 mb-2"><i class="bi bi-arrow-repeat"></i> الجولات <span class="small text-muted fw-normal">— كل جولة: تكليف واحد لمدير الإدارة، ثم اعتماده، ثم الإقرار السري لموظفيها، ثم الأرقام.</span></h2>

@if($canOpen)
  {{-- ٢٩-٤: فتح جولة لإدارة — الوحدة من مبانيك (حارس الكتابة) --}}
  <div class="card mb-3" data-open-round>
    <form method="post" action="{{ route('forms.rounds.store') }}" class="card-body d-flex gap-2 flex-wrap align-items-end">
      @csrf
      <div style="min-width:260px">
        <label class="form-label small mb-1">فتح جولة لإدارة</label>
        <select name="unit_id" class="form-select @error('unit_id') is-invalid @enderror" required>
          <option value="">— اختر الإدارة —</option>
          @foreach($units as $u)
            <option value="{{ $u['id'] }}" @selected((string) old('unit_id') === (string) $u['id'])>{{ str_repeat('— ', $u['depth']) }}{{ $u['name'] }}</option>
          @endforeach
        </select>
        @error('unit_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
      </div>
      <button class="btn btn-g"><i class="bi bi-play-circle"></i> افتح الجولة وكلّف المدير</button>
      <div class="form-text w-100">الموظفون لا يُكلَّفون بشيء الآن؛ يصلهم الإقرار السري بعد اعتماد المدير.</div>
    </form>
  </div>
@endif

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><th>الإدارة</th><th>الجولة</th><th>الحالة</th><th>فُتحت</th><th>اعتمدها المدير</th><th></th></tr>
      </thead>
      <tbody>
        @forelse($rounds as $r)
          <tr data-round="{{ $r->id }}">
            <td class="fw-bold">{{ $r->organizationUnit?->name ?? '—' }}</td>
            <td>{{ $r->round_no }}</td>
            <td><span class="badge bg-{{ $r->stateColor() }}" data-round-state="{{ $r->state }}">{{ $r->getStateLabel() }}</span></td>
            <td class="small text-muted"><span dir="ltr">{{ $r->created_at?->format('Y-m-d') }}</span> · {{ $r->openedBy?->name ?? '—' }}</td>
            <td class="small text-muted">{{ $r->approved_at ? ($r->approvedBy?->name ?? '—').' · '.$r->approved_at->format('Y-m-d H:i') : '—' }}</td>
            <td class="text-nowrap">
              <a href="{{ route('forms.rounds.show', $r) }}" class="btn btn-sm btn-outline-primary">افتح</a>
              @if(in_array($r->state, ['ack', 'closed'], true))<a href="{{ route('forms.rounds.numbers', $r) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-graph-up"></i> الأرقام</a>@endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-center text-muted py-4">لا جولات بعد.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @if($rounds->hasPages())<div class="card-footer">{{ $rounds->links() }}</div>@endif
</div>
@endsection
