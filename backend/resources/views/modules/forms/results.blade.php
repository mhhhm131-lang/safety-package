@extends('layouts.app')
@section('page_title', 'نتائج نموذج')
@section('content')

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="{{ route('forms.show', $form) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-right"></i></a>
  <div>
    <h1 class="h5 m-0">النتائج</h1>
    <div class="small text-muted">{{ $form->title }}</div>
  </div>
  <div class="ms-auto d-flex gap-2">
    <a href="{{ route('forms.tracking', $form) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-list-check"></i> المتابعة</a>
    <a href="{{ route('forms.export', $form) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-download"></i> تصدير CSV</a>
  </div>
</div>

<div class="row g-2 mb-3">
  @foreach([
    ['تعبئة', $submissions->count(), 'primary'], ['مكلَّف', $stats['total'], 'secondary'],
    ['لم يعبّئ', $stats['pending'] + $stats['overdue'], 'warning'],
  ] as [$label, $value, $color])
    <div class="col-4">
      <div class="card"><div class="card-body text-center py-2">
        <div class="h4 m-0 text-{{ $color }}" data-stat="{{ $label }}">{{ $value }}</div>
        <small class="text-muted">{{ $label }}</small>
      </div></div>
    </div>
  @endforeach
</div>

{{-- ملخّص لكل حقل --}}
<div class="card mb-3">
  <div class="card-header"><i class="bi bi-bar-chart"></i> ملخّص الإجابات</div>
  <div class="card-body p-0">
    @foreach($summary as $row)
      <div class="px-3 py-2 border-bottom" data-summary-field="{{ $row['field']->id }}">
        <div class="d-flex align-items-center gap-2 small">
          <span class="fw-bold flex-grow-1">{{ $row['field']->label }}</span>
          <span class="badge bg-light text-dark border">{{ $row['field']->getTypeLabel() }}</span>
          <span class="text-muted">أجاب {{ $row['answered'] }}</span>
        </div>
        @if($row['distribution'])
          <div class="mt-2">
            @foreach($row['distribution'] as $option => $count)
              @php $pct = $row['answered'] ? (int) round($count / $row['answered'] * 100) : 0; @endphp
              <div class="d-flex align-items-center gap-2 small mb-1">
                <span style="min-width:170px">{{ $option }}</span>
                <div class="progress flex-grow-1" style="height:8px">
                  <div class="progress-bar" style="width:{{ $pct }}%"></div>
                </div>
                <span class="text-muted" style="min-width:60px">{{ $count }} ({{ $pct }}%)</span>
              </div>
            @endforeach
          </div>
        @endif
      </div>
    @endforeach
  </div>
</div>

{{-- التعبئات --}}
<div class="card">
  <div class="card-header"><i class="bi bi-table"></i> التعبئات ({{ $submissions->count() }})</div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>#</th><th>المعبِّئ</th><th>الوقت</th>
          @foreach($form->fields as $field)<th>{{ \Illuminate\Support\Str::limit($field->label, 28) }}</th>@endforeach
        </tr>
      </thead>
      <tbody>
        @forelse($submissions as $submission)
          @php $byField = $submission->answers->keyBy('field_id'); @endphp
          <tr data-submission="{{ $submission->id }}">
            <td class="text-muted">{{ $submission->id }}</td>
            <td class="fw-bold small">{{ $submission->submittedBy?->name ?? '—' }}</td>
            <td class="small text-muted text-nowrap" dir="ltr">{{ $submission->submitted_at?->format('Y-m-d H:i') }}</td>
            @foreach($form->fields as $field)
              @php $answer = $byField[$field->id] ?? null; @endphp
              <td class="small">
                @if(!$answer)
                  <span class="text-muted">—</span>
                @elseif($answer->hasFile())
                  <a href="{{ route('forms.answers.file', [$form, $answer]) }}" target="_blank">
                    <i class="bi bi-{{ $field->field_type === 'signature' ? 'vector-pen' : 'image' }}"></i>
                    {{ $field->field_type === 'signature' ? 'التوقيع' : 'الصورة' }}
                  </a>
                @else
                  {{ \Illuminate\Support\Str::limit($answer->display(), 40) }}
                @endif
              </td>
            @endforeach
          </tr>
        @empty
          <tr><td colspan="{{ 3 + $form->fields->count() }}" class="text-center text-muted py-4">لا تعبئات بعد.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
