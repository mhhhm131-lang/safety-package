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

@if($hidden !== null)
  {{-- ٢٩-٢ (قرار ٨٨ §٤): تحت خمسة مجيبين لا نتائج — الإدارة الصغيرة تكشف صاحب «لا» بالاستبعاد --}}
  <div class="card" data-hidden-below-threshold="{{ $hidden }}">
    <div class="card-body text-center text-muted py-4">
      <i class="bi bi-incognito fs-3 d-block mb-2"></i>
      أجاب {{ $hidden }}. النتائج السرية تُعرض عند خمسة مجيبين فأكثر حتى لا يُعرف صاحب الإجابة.
    </div>
  </div>
@else

{{-- ملخّص لكل حقل --}}
<div class="card mb-3">
  <div class="card-header"><i class="bi bi-bar-chart"></i> ملخّص الإجابات</div>
  <div class="card-body p-0">
    @foreach($summary as $row)
      <div class="px-3 py-2 border-bottom" data-summary-field="{{ $row['field']->id }}">
        <div class="d-flex align-items-center gap-2 small">
          <span class="fw-bold flex-grow-1">{{ $row['field']->displayLabel() }}</span>
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
        {{-- ٢٩-١: القسم لا عمود له --}}
        @php $answerFields = $form->fields->filter->hasAnswer(); @endphp
        <tr>
          <th>#</th><th>المعبِّئ</th><th>الوقت</th>
          @foreach($answerFields as $field)<th title="{{ $field->displayLabel() }}">{{ \Illuminate\Support\Str::limit($field->displayLabel(), 36) }}</th>@endforeach
        </tr>
      </thead>
      <tbody>
        @forelse($submissions as $submission)
          @php
            $byField = $submission->answers->keyBy('field_id');
            $secret = $form->allows_anonymous && $submission->submitted_by_id === null; // ٢٩-٢: بلا رقم ولا اسم، والتاريخ باليوم
          @endphp
          <tr data-submission="{{ $secret ? 'secret' : $submission->id }}">
            <td class="text-muted">{{ $secret ? '—' : $submission->id }}</td>
            <td class="fw-bold small">{{ $submission->submittedBy?->name ?? ($form->allows_anonymous ? 'سري' : '—') }}</td>
            <td class="small text-muted text-nowrap" dir="ltr">{{ $submission->submitted_at?->format($secret ? 'Y-m-d' : 'Y-m-d H:i') }}</td>
            @foreach($answerFields as $field)
              @php $answer = $byField[$field->id] ?? null; @endphp
              <td class="small">
                @if(!$answer)
                  <span class="text-muted">—</span>
                @elseif($field->isClaim() && ($c = $answer->claimValue()))
                  {{-- قرار ٨٨: الادعاء — نعم بدليله ووثيقته، أو لا --}}
                  <span class="badge {{ $c['claim'] === 'yes' ? 'bg-success' : ($c['claim'] === 'no' ? 'bg-secondary' : 'bg-light text-muted border') }}" data-claim="{{ $c['claim'] }}">{{ $c['claim'] === 'yes' ? 'نعم' : ($c['claim'] === 'no' ? 'لا' : '—') }}</span>
                  @if($c['evidence'] !== '')<div class="text-muted">{{ $c['evidence'] }}</div>@endif
                  @if($answer->hasFile())<a href="{{ route('forms.answers.file', [$form, $answer]) }}" target="_blank"><i class="bi bi-paperclip"></i> {{ $answer->file_name ?: 'الوثيقة' }}</a>@endif
                @elseif($answer->hasFile())
                  <a href="{{ route('forms.answers.file', [$form, $answer]) }}" target="_blank">
                    <i class="bi bi-{{ $field->field_type === 'signature' ? 'vector-pen' : 'image' }}"></i>
                    {{ $field->field_type === 'signature' ? 'التوقيع' : 'الصورة' }}
                  </a>
                @elseif($field->isTable() && ($rows = $answer->tableRows()))
                  {{-- ٢٩-١: الجدول صفوفاً كما أُدخلت --}}
                  <details>
                    <summary class="text-primary" style="cursor:pointer">{{ count($rows) }} {{ count($rows) === 1 ? 'صف' : 'صفوف' }}</summary>
                    <table class="table table-sm table-bordered mb-0 mt-1" style="min-width:260px">
                      <thead><tr>@foreach($field->tableDef()['columns'] as $col)<th class="small fw-normal text-muted">{{ $col['label'] }}</th>@endforeach</tr></thead>
                      <tbody>@foreach($rows as $row)<tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tbody>
                    </table>
                  </details>
                @elseif($field->isLadder() && ($lv = $answer->ladderValue()))
                  {{-- ٢٩-٥: السُّلّم — أربع شارات، و«لا» على «أطبّقه» حمراء، والواقع تحتها؛ أو «لا ينطبق» --}}
                  @if($lv['na'])
                    <span class="badge bg-light text-muted border" data-ladder-na>{{ \App\Modules\Form\Support\NonMaterialPackage::NA_LABEL }}</span>
                  @else
                    <div class="d-flex gap-1 flex-wrap">
                      @foreach(\App\Modules\Form\Support\NonMaterialPackage::STAGES as $st)
                        @php $lvv = $lv[$st['key']]; @endphp
                        <span class="badge {{ $lvv === 'yes' ? 'bg-success' : ($lvv === 'no' ? ($st['key'] === 'app' ? 'bg-danger' : 'bg-secondary') : 'bg-light text-muted border') }}" data-ladder="{{ $st['key'] }}">{{ $st['name'] }}: {{ $lvv === 'yes' ? 'نعم' : ($lvv === 'no' ? 'لا' : '—') }}</span>
                      @endforeach
                    </div>
                    @if($lv['note'] !== '')<div class="text-muted mt-1"><i class="bi bi-chat-left-text"></i> {{ $lv['note'] }}</div>@endif
                  @endif
                @elseif($field->isAckItem() && ($ack = $answer->ackValue()))
                  {{-- ٢٩-١: بند الإقرار — ثلاث إجابات، و«لا» حمراء، والواقع تحتها --}}
                  <div class="d-flex gap-1 flex-wrap">
                    @foreach(\App\Modules\Form\Models\FormField::ACK_QUESTIONS as $k => $q)
                      <span class="badge {{ $ack[$k] === 'لا' ? 'bg-danger' : ($ack[$k] === 'نعم' ? 'bg-success' : 'bg-light text-muted border') }}" data-ack="{{ $k }}">{{ rtrim($q, '؟') }}: {{ $ack[$k] !== '' ? $ack[$k] : '—' }}</span>
                    @endforeach
                  </div>
                  @if($ack['note'] !== '')<div class="text-muted mt-1"><i class="bi bi-chat-left-text"></i> {{ $ack['note'] }}</div>@endif
                @else
                  {{ \Illuminate\Support\Str::limit($answer->display(), 40) }}
                @endif
              </td>
            @endforeach
          </tr>
        @empty
          <tr><td colspan="{{ 3 + $answerFields->count() }}" class="text-center text-muted py-4">لا تعبئات بعد.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>
@endif
@endsection
