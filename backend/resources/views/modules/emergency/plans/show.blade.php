@extends('layouts.app')
@section('page_title', 'خطة الاستجابة — '.$plan->place->code)
@section('content')
@php($RP = \App\Modules\Emergency\Models\ResponsePlan::class)
<div class="d-flex align-items-center gap-2 flex-wrap mb-1">
  <a href="{{ route('emergency.plans.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-right"></i> الخطط</a>
  <h1 class="h4 m-0"><i class="bi bi-list-ol"></i> {{ $plan->place->code }} — {{ $plan->title }}</h1>
  <span class="ms-auto"><a class="btn btn-sm btn-outline-secondary" href="{{ $plan->documentUrl() }}" target="_blank"><i class="bi bi-file-earmark-text"></i> الوثيقة</a></span>
</div>
<div class="small text-muted mb-3">
  خطوات المسارات: <strong>{{ $plan->steps_count }}</strong> · الكشف (٠): {{ $plan->detection_count }}@if($plan->scenario_count) · سيناريوهات: {{ $plan->scenario_count }}@endif
  · المعلن في رأس الوثيقة: {{ $plan->declared_total ?? '—' }} · بلا بطاقة: {{ $plan->no_card_count }}
  · البصمة <code>{{ $plan->shortFingerprint() }}</code> · زُومنت {{ $plan->synced_at->format('Y-m-d H:i') }}
</div>

@foreach(['detection', 'scenario', 'medical', 'fire', 'other'] as $key)
  @continue(empty($byPath[$key]))
  @php($steps = $byPath[$key])
  <div class="card mb-3">
    <div class="card-header py-2 d-flex align-items-center gap-2">
      <strong>{{ $steps[0]->path_title }}</strong>
      <span class="badge text-bg-{{ in_array($key, $RP::LIVE_PATHS) ? 'success' : 'secondary' }}">{{ $RP::PATHS[$key] }}</span>
      @if($steps[0]->path_declared_count !== null)<span class="small text-muted">· في رأس المسار: {{ $steps[0]->path_declared_count }} · المشتق: {{ count($steps) }} {{ $steps[0]->path_declared_count === count($steps) ? '✓' : '✗' }}</span>@endif
      @if(in_array($key, $RP::LIVE_PATHS))<span class="small text-muted ms-auto">يدخل القائمة الحية عند التفعيل</span>@endif
    </div>
    <div class="table-responsive"><table class="table table-sm table-hover m-0 small align-middle">
      <thead><tr><th style="width:2.5rem">#</th><th style="width:9rem">متى</th><th style="width:8rem">النافذة</th><th>الخطوة</th><th style="width:22%">من → البطاقة</th><th style="width:14%">أين</th><th style="width:22%">كيف</th></tr></thead>
      <tbody>
      @foreach($steps as $s)
        <tr class="{{ $s->isLive() && !$s->hasCard() ? 'table-danger' : '' }}">
          <td><strong>{{ $s->label }}</strong></td>
          <td>{{ $s->when_text ?? '—' }}</td>
          <td class="text-muted">{{ $s->windowLabel() }}</td>
          <td><strong>{{ $s->title }}</strong></td>
          <td>
            @if($s->who_text)<div class="text-muted">{{ $s->who_text }}</div>@endif
            @forelse($s->cards() as $no => $c)
              <a href="{{ $c['url'] }}" target="_blank" class="badge text-bg-{{ $no === $s->role_card_no ? 'dark' : 'light border text-dark' }} text-decoration-none" title="{{ $c['name'] }}">{{ $no }} {{ $c['name'] }}</a>
            @empty
              <span class="badge text-bg-danger">بلا بطاقة</span>
            @endforelse
          </td>
          <td class="text-muted">{{ $s->where_text ?? '—' }}</td>
          <td class="text-muted">{{ $s->how_text }}</td>
        </tr>
      @endforeach
      </tbody></table></div>
  </div>
@endforeach
@endsection
