@extends('layouts.app')
@section('title', 'الأماكن')
@section('content')
{{-- المرحلة ١٨-٣ ثم ١٩-١ ثم ١٩-٤ (قرار ٤٨): صفحة «الأماكن» = ما في اللوحة: «صورة المبنى» لأدوار القرار + فسيفساء الأماكن ٨+١؛ البلاطة تفتح ملف المكان --}}
@php($R = \App\Modules\Store\Services\InspectionDocReader::class)
<style>
  .pl-tile{display:block;text-decoration:none;color:inherit;border:1px solid var(--bs-border-color);border-bottom:5px solid #adb5bd;border-radius:12px;padding:12px 14px;background:#fff;height:100%}
  .pl-tile.calm{border-bottom-color:#0f4c3a}.pl-tile.busy{border-bottom-color:#d9b25a}.pl-tile.late{border-bottom-color:#c62828}.pl-tile.none{background:#f8f9fa}
  .pl-tile .nm{font-weight:700}.pl-tile .lk a{font-size:.8rem;margin-inline-end:8px}
  .kp{display:block;text-decoration:none;color:inherit;border:1px solid var(--bs-border-color);border-radius:12px;padding:10px 12px;background:#fff;height:100%}
  .kp.on{border:2px solid #0f4c3a}.kp .v{font-size:1.7rem;font-weight:700;line-height:1.1}.kp.zero .v{color:#adb5bd}
  .kp .d{font-size:.78rem;color:#6b7a74}
</style>
<h1 class="page-h">الأماكن</h1>
<p class="small text-muted mb-3">اضغط المكان لملفه: وحداته، أنظمته وآخر فحص، بلاغاته المفتوحة، فريقه الأولي بهواتفه، وخطتاه. الشريط السفلي: أخضر لا شيء مفتوح · ذهبي مفتوح · أحمر متجاوز.</p>

@if($kpis !== null)
  {{-- صورة المبنى: أربعة أرقام، النقر يعرض بلاغاتها --}}
  <h2 class="sec-h"><i class="bi bi-building"></i> صورة المبنى <span class="small text-muted fw-normal">اضغط رقماً لعرض بلاغاته</span></h2>
  <div class="row g-2 mb-3" id="kpis">
    @foreach($R::KPI as $key => [$label, $desc])
      @php($v = $kpis[$key])
      <div class="col-6 col-lg-3"><a class="kp {{ $v ? '' : 'zero' }} {{ $k === $key ? 'on' : '' }}" data-k="{{ $key }}" data-v="{{ $v }}" href="{{ route('app.places.units.hub') }}{{ $k === $key ? '' : '?k='.$key }}#kpis">
        <div class="v {{ $v && in_array($key, ['od', 'a']) ? 'text-danger' : '' }}">{{ $v }}</div><div class="fw-bold small">{{ $label }}</div><div class="d">{{ $desc }}</div></a></div>
    @endforeach
  </div>
  @if($k)
    <div class="d-grid gap-2 mb-3" id="kList">
      @forelse($kList as $r)
        @php($over = $R::overdueHours($r))
        <div class="card task {{ $over !== null && $over > 0 ? 'task-late' : '' }}"><div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
          <div class="flex-grow-1"><b>{{ $r['id'] ?? $r['row'] ?? '' }}</b> · {{ $r['_form']['name'] }}@if(!empty($r['unit'])) · {{ $r['unit'] }}@endif · {{ $r['sys'] ?? '' }}<div class="small">{{ $r['item'] ?? '' }}</div>
            <div class="small text-muted">@if($over !== null && $over > 0)<span class="badge st-late">متأخر</span> @endif المهلة: {{ $r['due'] ?? '—' }} · عند {{ $R::LEVEL_NAMES[$R::holder($r)] ?? '—' }}@if(!empty($r['path']) && $r['path'] !== 'إداري') · <span class="badge text-bg-dark">{{ $r['path'] }}</span>@endif</div></div>
          <a class="btn btn-o btn-sm" href="/{{ $r['_form']['file'] }}#open={{ rawurlencode((string) ($r['row'] ?? '')) }}">افتحه</a>
        </div></div>
      @empty
        <div class="card"><div class="card-body small text-muted py-2">لا بلاغات في هذا التصنيف.</div></div>
      @endforelse
    </div>
  @endif
@endif

<h2 class="sec-h"><i class="bi bi-grid-3x3-gap"></i> الأماكن (٨+١)</h2>
<div class="row g-2" id="unitsHub">
  @foreach($tiles as $hz => $t)
    @php($p = $byCode[$hz] ?? null)
    @continue(!$p)
    <div class="col-md-6 col-xl-4">
      <a class="pl-tile {{ $t['cls'] }}" href="{{ route('app.places.units.file', $p) }}" data-place="{{ $hz }}" data-cls="{{ $t['cls'] }}" data-open="{{ $t['open'] }}" data-od="{{ $t['od'] }}" data-a="{{ $t['a'] }}" title="{{ $counts[$p->id] ?? 0 }} وحدة">
        <div class="d-flex justify-content-between"><span class="small text-muted" dir="ltr">{{ $hz }}</span><span class="small text-muted">{{ ($counts[$p->id] ?? 0) ? ($counts[$p->id]).' وحدة' : '' }}</span></div>
        <div class="nm">{{ $p->name }}</div>
        <div class="small mt-1">
          @if(!$t['has'])<span class="text-muted">لم تُفتح جولة بعد</span>
          @elseif($t['open'])<b>{{ $t['open'] }}</b> مفتوح@if($t['od']) · <b class="text-danger">{{ $t['od'] }} متجاوز</b>@endif @if($t['a']) · <span style="color:#b8860b">{{ $t['a'] }} فئة أ</span>@endif
          @else<b class="text-success">لا بلاغات مفتوحة</b>@endif
        </div>
      </a>
    </div>
  @endforeach
</div>
@endsection
