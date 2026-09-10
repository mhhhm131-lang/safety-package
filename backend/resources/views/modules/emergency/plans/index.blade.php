@extends('layouts.app')
@section('page_title', 'خطط الاستجابة')
@section('content')
@php($role = auth()->user()->role())
@php($P = \App\Core\Permissions\PermissionRegistry::class)
@php($RC = \App\Modules\Emergency\Support\RoleCards::class)
<div class="d-flex align-items-center gap-2 flex-wrap mb-3">
  <h1 class="h4 m-0"><i class="bi bi-list-ol"></i> خطط الاستجابة</h1>
  <span class="small text-muted">مشتقة من وثائق الأماكن الثمانية <code>HZ-0x/response-plan.html</code> — الوثيقة هي الحقيقة ولا تُحرَّر هنا</span>
  <span class="ms-auto d-flex gap-1">
    @if($P::hasPermission($role, 'emergency.manage'))<form method="post" action="{{ route('emergency.plans.sync') }}">@csrf<button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat"></i> مزامنة من الوثائق</button></form>@endif
  </span>
</div>

<div class="card mb-3"><div class="table-responsive"><table class="table table-hover m-0 small align-middle">
  <thead><tr><th>المكان</th><th>المسارات</th><th>خطوات المسارات</th><th>الكشف (٠)</th><th>المعلن في رأس الوثيقة</th><th>بلا بطاقة دور</th><th>البصمة</th><th>آخر مزامنة</th><th></th></tr></thead>
  <tbody>
  @foreach($places as $place)
    @php($plan = $plans[$place->code] ?? null)
    <tr>
      <td><strong>{{ $place->code }}</strong> — {{ $place->name }}</td>
      @if($plan)
        @php($byPath = $plan->steps->groupBy('path_key'))
        <td class="text-muted">
          @foreach(\App\Modules\Emergency\Models\ResponsePlan::LIVE_PATHS as $k)
            @if(isset($byPath[$k]))<span class="badge text-bg-light border me-1">{{ $byPath[$k]->first()->path_title }}: {{ $byPath[$k]->count() }}</span>@endif
          @endforeach
          @if(isset($byPath['scenario']))<span class="badge text-bg-light border">سيناريوهات: {{ $byPath['scenario']->count() }}</span>@endif
        </td>
        <td><strong>{{ $plan->steps_count }}</strong></td>
        <td>{{ $plan->detection_count }}</td>
        <td>
          @if($plan->declared_total === null)<span class="text-muted">—</span>
          @else{{ $plan->declared_total }}
            @if($plan->declared_total === $plan->steps_count + $plan->detection_count)<span class="badge text-bg-success">= المسارات + الكشف</span>
            @elseif($plan->declared_total === $plan->steps_count)<span class="badge text-bg-success">= المسارات</span>
            @else<span class="badge text-bg-warning" title="الرقم المعلن لا يساوي المسارات ({{ $plan->steps_count }}) ولا المسارات + الكشف ({{ $plan->steps_count + $plan->detection_count }})">يُحسب بطريقة أخرى</span>@endif
          @endif
        </td>
        <td>@if($plan->no_card_count)<span class="badge text-bg-danger">{{ $plan->no_card_count }}</span>@else<span class="badge text-bg-success">٠</span>@endif</td>
        <td><code>{{ $plan->shortFingerprint() }}</code></td>
        <td class="text-muted">{{ $plan->synced_at->format('Y-m-d H:i') }}</td>
        <td class="text-nowrap"><a class="btn btn-sm btn-g" href="{{ route('emergency.plans.show', $place->code) }}">الخطوات</a> <a class="btn btn-sm btn-outline-secondary" href="{{ $plan->documentUrl() }}" target="_blank">الوثيقة</a></td>
      @else
        <td colspan="7" class="text-danger">الوثيقة غير موجودة — {{ ($summary[$place->code]['status'] ?? '') === 'missing' ? 'لم يُعثر على response-plan.html' : 'لم تُزامَن بعد' }}</td>
        <td></td>
      @endif
    </tr>
  @endforeach
  </tbody></table></div></div>

<div class="alert alert-light border small mb-4">
  <strong>كيف تُقرأ الأرقام:</strong> «خطوات المسارات» هي الخطوات المرقّمة بزمن (المسار الطبي + مسار المكان + حالات أخرى) وهي ما يدخل القائمة الحية عند التفعيل؛ «الكشف (٠)» بنود ①②③ ونداء المركز وتسبق كل المسارات؛ «المعلن في رأس الوثيقة» يُعرض كما كُتب ولا يُحسب هنا.
  «بلا بطاقة دور» = خطوة «من» فيها جهة ليست من البطاقات الـ٢١ (مثل «اللجنة الفنية») — تحتاج قرار المستخدم لا تخميناً.
</div>

<h2 class="h5 mb-2"><i class="bi bi-person-badge"></i> بطاقات الأدوار الـ٢١ ومقابلها في النظام</h2>
<p class="small text-muted mb-3">«من» في كل خطوة يُطابَق بهذه البطاقات (SOURCE.md §٣). المطابقة بالأشخاص عمل مسؤول السلامة من شاشة المستخدمين والفرق.</p>
<div class="row g-3 mb-3">
  @foreach($cards as $cat => $list)
    <div class="col-md-6">
      <div class="card h-100"><div class="card-header py-2"><strong>{{ $RC::CATEGORIES[$cat] }}</strong> <span class="badge text-bg-secondary">{{ count($list) }}</span></div>
        <div class="table-responsive"><table class="table table-sm m-0 small align-middle">
          @foreach($list as $no => $c)
            <tr><td class="text-nowrap" style="width:3rem"><span class="badge text-bg-dark">{{ $no }}</span></td>
              <td><a href="{{ $c['url'] }}" target="_blank" class="text-decoration-none"><strong>{{ $c['name'] }}</strong></a><br><span class="text-muted">{{ $c['desc'] }}</span></td>
              <td class="text-muted">{{ $RC::systemLabel($no) }}</td></tr>
          @endforeach
        </table></div>
      </div>
    </div>
  @endforeach
</div>
@endsection
