@extends('layouts.app')
@section('title', 'الأدوار والبطاقات')
@section('content')
{{-- المرحلة ٢٠-٦ (قرار ٥١): للقراءة — الدور يقول ماذا تفعل (في الكود)، والبطاقة مهمة سلامة تُسند إلى شخص. لا تعديل هنا. --}}
@php($AR = fn ($n) => strtr((string) $n, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']))
@php($UI = ['safety' => 'العمل اليومي كاملاً', 'exec' => 'صورة المبنى والقرار', 'adm' => 'الاعتماد والمستوى ٣', 'fm' => 'المستوى ٢ والوحدات', 'dept' => 'إدارته ومكانه', 'tech' => 'النماذج وجولاته', 'cons' => 'بنود الفحص', null => 'الوثائق والبلاغ ومكانه'])
<h1 class="h4 mb-1">الأدوار والبطاقات</h1>
<p class="small text-muted mb-3">الدور مفتاح صلاحيات ثابت في الكود ({{ $AR(count($roles)) }} دوراً، {{ $AR($total) }} صلاحية). البطاقة مهمة سلامة من الـ{{ $AR(count($cards)) }} تُسند إلى شخص. الصفحة للقراءة؛ التغيير قرار مسؤول السلامة ثم مطوّر.</p>

<h2 class="sec-h"><i class="bi bi-person-badge"></i> الأدوار الـ{{ $AR(count($roles)) }} — وموضع كلٍّ في البطاقات</h2>
<div class="card mb-4"><div class="table-responsive"><table class="table table-sm m-0 align-middle" style="min-width:720px">
<thead><tr><th>الدور</th><th>ما يفتحه</th><th>الصلاحيات</th><th>الحسابات</th><th>بطاقات السلامة</th></tr></thead>
<tbody>
@foreach($roles as $key => $r)
  <tr data-role="{{ $key }}">
    <td><b>{{ $r['name'] }}</b></td>
    <td class="small">{{ $UI[$r['ui']] ?? '—' }}</td>
    <td class="text-center">{{ $AR($r['perms']) }}</td>
    <td class="text-center">{{ $AR($r['accounts']) }}</td>
    <td class="small">@foreach($r['cards'] as $no)<a href="{{ \App\Modules\Emergency\Support\RoleCards::url($no) }}" class="badge text-bg-light border text-decoration-none">{{ $AR($no) }} {{ \App\Modules\Emergency\Support\RoleCards::CARDS[$no]['name'] }}</a> @endforeach
      <span class="text-muted">{{ $r['text'] }}</span></td>
  </tr>
@endforeach
</tbody></table></div></div>
@if(array_sum($legacyCounts))
  <div class="alert alert-warning py-2 small mb-4">حسابات على دور قديم لا يُسند بعد اليوم («الفني المنفّذ»): {{ $AR(array_sum($legacyCounts)) }} — تُنقل إلى تخصص من شاشة الحساب.</div>
@endif
<p class="small text-muted mb-4">لا «مالك النظام» عندنا: كان في OHSMS لأنه منصة لعدة جهات، ونحن جهة واحدة فمسؤول السلامة أعلى الأدوار.</p>

<h2 class="sec-h"><i class="bi bi-card-list"></i> البطاقات الـ{{ $AR(count($cards)) }} — ومن يحمل كلاً منها</h2>
<div class="card"><div class="table-responsive"><table class="table table-sm m-0 align-middle" style="min-width:640px">
<thead><tr><th>#</th><th>البطاقة</th><th>الفئة</th><th>من يحملها</th></tr></thead>
<tbody>
@php($CAT = ['leadership' => 'القيادة', 'support' => 'الإسناد', 'response-team' => 'الفريق الأولي', 'occupants' => 'الشاغلون'])
@foreach($cards as $no => $c)
  <tr data-card="{{ $no }}">
    <td>{{ $AR($no) }}</td>
    <td><a href="{{ $c['url'] }}" class="text-decoration-none"><b>{{ $c['name'] }}</b></a><div class="small text-muted">{{ $c['desc'] }}</div></td>
    <td class="small">{{ $CAT[$c['category']] ?? $c['category'] }}</td>
    <td class="small">{{ $c['holder'] }}@if($c['kind'] === 'named') <span class="text-muted">— يُحدَّد في شاشة الحساب (خانة «بطاقته»)</span>@endif</td>
  </tr>
@endforeach
</tbody></table></div></div>
@endsection
