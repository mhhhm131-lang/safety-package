@extends('layouts.app')
@section('page_title', 'الأرقام — '.$n['unit'])
@section('content')
@php
  $stages = \App\Modules\Form\Support\NonMaterialPackage::STAGES;
  $kinds = \App\Modules\Form\Services\RoundNumbersService::KINDS;
@endphp

<div class="d-flex align-items-start gap-2 mb-3 flex-wrap">
  <div>
    <div class="small"><a href="{{ route('forms.rounds.show', $round) }}" class="text-muted text-decoration-none"><i class="bi bi-arrow-right"></i> الجولة</a></div>
    <h1 class="h4 m-0">الأرقام — {{ $n['unit'] }} — الجولة {{ $n['round_no'] }}</h1>
    <div class="small text-muted mt-1">ما قالته الإدارة، وما قاله موظفوها سرياً على السُّلّم: وصلتني ← أُعلن لي ← أفهمه ← أطبّقه. النسب على من أجاب. النظام يوضّح الواقع، والقرار لكم.</div>
  </div>
  <span class="badge bg-{{ $round->stateColor() }} ms-auto fs-6" data-round-state="{{ $round->state }}">{{ $round->getStateLabel() }}</span>
</div>

{{-- ٢٩-٦ (قرار ٨٨ §٢): سطر الإدارة — المستوى من ٤، أكبر فجوة، الاستجابة؛ لا بطاقات أبعاد --}}
<div class="card mb-3" data-unit-line>
  <div class="card-body py-2 d-flex flex-wrap gap-4 align-items-center">
    <strong class="fs-6">{{ $n['unit'] }}</strong>
    @if($n['hidden'])
      <span data-numbers-hidden="{{ $n['answered'] }}"><i class="bi bi-incognito"></i> أجاب {{ $n['answered'] }} من {{ $n['assigned'] }} — تُعرض الأرقام عند {{ $n['min'] }} مجيبين فأكثر حتى لا يُعرف صاحب الإجابة.</span>
    @else
      <span>المستوى: <strong class="text-g" data-level="{{ number_format((float) $n['level'], 1) }}">{{ number_format((float) $n['level'], 1) }} من ٤</strong></span>
      <span>أكبر فجوة:
        @if($n['top_gap'])
          <strong class="text-danger" data-top-gap="{{ $n['top_gap']['item'] }}">{{ $n['top_gap']['title'] }} — {{ $n['top_gap']['label'] }}</strong>
        @else
          <strong class="text-success" data-top-gap="">لا فجوة</strong>
        @endif
      </span>
      <span>الاستجابة: <strong data-answered="{{ $n['answered'] }}">أجاب {{ $n['answered'] }} من {{ $n['assigned'] }}</strong></span>
    @endif
  </div>
</div>

@unless($n['hidden'])
  <div class="d-flex gap-3 flex-wrap small mb-2" data-legend>
    <span><i class="d-inline-block rounded align-middle" style="width:12px;height:12px;background:#fdecee;border:1px solid #f1b0b7"></i> مفهوم وغير مطبَّق — الأخطر</span>
    <span><i class="d-inline-block rounded align-middle" style="width:12px;height:12px;background:#fff8e1;border:1px solid #e8d48b"></i> معلن وغير مفهوم</span>
    <span><i class="d-inline-block rounded align-middle" style="width:12px;height:12px;background:#eef3f0;border:1px solid #c7d3cd"></i> قالت الإدارة «لا»</span>
  </div>

  <div class="card">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0" data-numbers-table>
        <thead class="table-light">
          <tr>
            <th rowspan="2" style="min-width:220px">البند</th>
            @foreach($stages as $st)<th colspan="2" class="text-center">{{ $st['name'] }}</th>@endforeach
            <th rowspan="2" style="min-width:200px">ما قاله الموظفون</th>
            <th rowspan="2" style="min-width:160px">وقائع النظام</th>
          </tr>
          <tr>
            @foreach($stages as $st)<th class="small fw-normal text-muted">الإدارة</th><th class="small fw-normal text-muted">الموظفون</th>@endforeach
          </tr>
        </thead>
        <tbody>
          @foreach($n['items'] as $r)
            @php $bg = match($r['kind']) { 'app' => '#fdecee', 'und' => '#fff8e1', 'none' => '#eef3f0', default => '' }; @endphp
            <tr data-item="{{ $r['key'] }}" data-kind="{{ $r['kind'] }}" @if($bg) style="background:{{ $bg }}" @endif>
              <td class="fw-bold">
                {{ $r['order'] }} · {{ $r['title'] }}
                @if(!in_array($r['kind'], ['none', 'ok', 'empty'], true))<div class="small fw-normal text-danger">{{ $kinds[$r['kind']] }}</div>@endif
                @if($r['score'] !== null)<div class="small fw-normal text-muted">رقم البند {{ number_format($r['score'], 1) }} من ٤ · ادّعت الإدارة {{ $r['claimed'] }} · الفجوة {{ number_format($r['gap'], 1) }}</div>@endif
              </td>
              @foreach($stages as $st)
                @php $c = $r['claims'][$st['key']]['claim']; @endphp
                <td class="small">
                  {{ $c === 'yes' ? 'نعم' : ($c === 'no' ? 'لا' : '—') }}
                  @if($r['from_register'] && $st['key'] === 'doc')<div class="text-muted">(من السجل)</div>@endif
                </td>
                <td class="small {{ $r['kind'] === $st['key'] ? 'fw-bold text-danger' : '' }}" data-share="{{ $r['key'] }}.{{ $st['key'] }}">
                  @if($r['answered'] > 0){{ $r['counts'][$st['key']] }} من {{ $r['answered'] }} ({{ $r['shares'][$st['key']] }}٪)@else —@endif
                </td>
              @endforeach
              <td class="small">
                @if($r['kind'] === 'none')
                  <span class="text-muted">{{ $kinds['none'] }}</span>
                @elseif($r['kind'] === 'empty')
                  <span class="text-muted">{{ $kinds['empty'] }}</span>
                @elseif($n['with_texts'])
                  @forelse($r['notes'] as $t)<div>«{{ $t }}»</div>@empty<span class="text-muted">—</span>@endforelse
                @else
                  <span class="text-muted">{{ $r['notes_count'] }} ملاحظة — بلا نصوص الموظفين</span>
                @endif
                @if($r['na'] > 0)<div class="text-muted">لا ينطبق على عملي: {{ $r['na'] }}</div>@endif
              </td>
              <td class="small text-muted">{{ $r['fact'] ?? '' }}</td>
            </tr>
          @endforeach
        </tbody>
        <tfoot>
          <tr><td colspan="11" class="small text-muted">
            المجيبون {{ $n['answered'] }} من {{ $n['assigned'] }} · أعلن اسمه {{ $n['named'] }} · «لا ينطبق على عملي» خارج النسب ·
            درجة الموظف في البند عدد «نعم» المتصلة من الأسفل، ورقم البند متوسطها من ٤، ورقم الإدارة متوسط بنودها التي أُجيبت.
          </td></tr>
        </tfoot>
      </table>
    </div>
  </div>
@endunless
@endsection
