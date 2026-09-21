@extends('layouts.app')
@section('title', 'الملف الطبي — '.$person->name)

{{--
  ٢٢-٦ب (قرار ٦٠): ملف شخصٍ ما، للطبيب وحده. كل فتح لهذه الصفحة يُقيَّد في سجل التدقيق
  باسم الطبيب ووقته — ضمانة لصاحب الملف.
--}}

@section('content')
<div class="container-fluid px-0" style="max-width:860px">

  <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('emergency.medical.dashboard') }}">
      <i class="bi bi-arrow-right"></i> الملفات الطبية
    </a>
    <h1 class="h4 m-0">{{ $person->name }}</h1>
    @if($profile->is_verified)<span class="badge text-bg-success">موثَّق</span>@endif
  </div>

  <div class="alert alert-secondary py-2 small">
    <i class="bi bi-shield-lock"></i> هذه بيانات صحية. الاطّلاع عليها مقصور على طبيب العيادة، وقد سُجّل فتحك لهذه الصفحة.
  </div>

  <div class="row g-3">
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-header"><strong>الأساسيات</strong></div>
        <ul class="list-group list-group-flush">
          <li class="list-group-item d-flex justify-content-between"><span class="text-muted">فصيلة الدم</span><strong>{{ $profile->blood_type ?: '—' }}</strong></li>
          <li class="list-group-item d-flex justify-content-between"><span class="text-muted">مستوى الحركة</span><strong>{{ $profile->mobility_level ?: '—' }}</strong></li>
          <li class="list-group-item d-flex justify-content-between"><span class="text-muted">يحتاج مساعدة في الإخلاء</span><strong>{{ $profile->needs_evacuation_assistance ? 'نعم' : 'لا' }}</strong></li>
          <li class="list-group-item d-flex justify-content-between"><span class="text-muted">منظّم ضربات القلب</span><strong>{{ $profile->has_pacemaker ? 'نعم' : 'لا' }}</strong></li>
        </ul>
      </div>
    </div>

    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-header"><strong>الحالة الصحية</strong></div>
        <div class="card-body">
          @foreach([['الأمراض المزمنة', $profile->chronic_conditions], ['الحساسية', $profile->allergies], ['الأدوية الحالية', $profile->current_medications]] as [$label, $items])
            <div class="mb-2">
              <div class="text-muted small">{{ $label }}</div>
              @if(!empty($items))
                @foreach((array) $items as $i)<span class="badge text-bg-light border me-1">{{ $i }}</span>@endforeach
              @else
                <span class="text-muted">—</span>
              @endif
            </div>
          @endforeach
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="card">
        <div class="card-header"><strong>عند الحاجة</strong></div>
        <ul class="list-group list-group-flush">
          @foreach([[$profile->emergency_contact_1_name, $profile->emergency_contact_1_phone, $profile->emergency_contact_1_relation],
                    [$profile->emergency_contact_2_name, $profile->emergency_contact_2_phone, $profile->emergency_contact_2_relation]] as [$n, $ph, $rel])
            @if($n)
              <li class="list-group-item d-flex align-items-center gap-2">
                <strong>{{ $n }}</strong> <span class="text-muted small">{{ $rel }}</span>
                @if($ph)<a class="btn btn-sm btn-outline-danger ms-auto" href="tel:{{ $ph }}"><i class="bi bi-telephone-fill"></i> {{ $ph }}</a>@endif
              </li>
            @endif
          @endforeach
          <li class="list-group-item d-flex justify-content-between"><span class="text-muted">المستشفى المفضّل</span><strong>{{ $profile->preferred_hospital ?: '—' }}</strong></li>
        </ul>
      </div>
    </div>
  </div>

  @if(!$profile->is_verified)
    <form method="post" action="{{ route('api.emergency.medical.verify', $profile) }}" class="mt-3">
      @csrf<button class="btn btn-outline-success">أوثّق هذا الملف</button>
    </form>
  @endif
</div>
@endsection
