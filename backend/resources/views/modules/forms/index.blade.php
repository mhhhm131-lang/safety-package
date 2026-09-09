@extends('layouts.app')
@section('page_title', 'النماذج الرقمية')
@section('content')
@php $me = auth()->user(); @endphp

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h4 m-0"><i class="bi bi-ui-checks me-2"></i>النماذج الرقمية</h1>
  <div class="ms-auto d-flex gap-2 flex-wrap">
    <a href="{{ route('forms.mine') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-inbox"></i> نماذجي</a>
    @if($me->can_('form.create'))
      <a href="{{ route('forms.generate') }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-magic"></i> توليد من المخاطر</a>
      <a href="{{ route('forms.create') }}" class="btn btn-sm btn-g"><i class="bi bi-plus-lg"></i> نموذج جديد</a>
    @endif
  </div>
</div>

<div class="alert alert-light border py-2 small">
  <i class="bi bi-info-circle"></i>
  هذه نماذج التوعية والإقرارات والاستبيانات التي تُرسل إلى أشخاص بأعيانهم.
  <strong>نماذج فحص الأماكن العشرة شيء آخر</strong> ولها مسارها في وثائق المنظومة.
</div>

{{-- مرشّحات الأنواع (بالأنواع الموجودة فعلاً في القاعدة) --}}
<div class="d-flex gap-2 flex-wrap mb-3">
  <a href="{{ route('forms.index') }}" class="btn btn-sm {{ !$activeType ? 'btn-g' : 'btn-outline-secondary' }}">
    الكل <span class="badge bg-light text-dark">{{ array_sum($facets) }}</span>
  </a>
  @foreach(\App\Modules\Form\Models\FormTemplate::TYPE_LABELS as $key => $label)
    @if(($facets[$key] ?? 0) > 0 || $activeType === $key)
      <a href="{{ route('forms.index', ['type' => $key]) }}"
         class="btn btn-sm {{ $activeType === $key ? 'btn-g' : 'btn-outline-secondary' }}">
        {{ $label }} <span class="badge bg-light text-dark">{{ $facets[$key] ?? 0 }}</span>
      </a>
    @endif
  @endforeach
  <a href="{{ route('forms.index', ['inactive' => 1]) }}"
     class="btn btn-sm {{ request()->boolean('inactive') ? 'btn-secondary' : 'btn-outline-secondary' }} ms-auto">
    الموقوفة
  </a>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><th>النموذج</th><th>النوع</th><th>المكان</th><th>الحقول</th><th>التكليف</th><th>التعبئة</th><th></th></tr>
      </thead>
      <tbody>
        @forelse($forms as $form)
          @php $stats = $form->assignmentStats(); @endphp
          <tr data-form="{{ $form->id }}">
            <td>
              <a href="{{ route('forms.show', $form) }}" class="fw-bold">{{ $form->title }}</a>
              @unless($form->is_active)<span class="badge bg-secondary">موقوف</span>@endunless
              @if($form->description)<div class="small text-muted">{{ \Illuminate\Support\Str::limit($form->description, 60) }}</div>@endif
            </td>
            <td>@include('modules.forms._type', ['type' => $form->form_type])</td>
            <td class="small">{{ $form->place?->name ?? '—' }}</td>
            <td class="small">{{ $form->fields_count }}</td>
            <td class="small">
              @if($stats['total'] > 0)
                <span class="text-success">{{ $stats['completed'] }}</span>/{{ $stats['total'] }}
                @if($stats['overdue'] > 0)<span class="badge bg-danger" data-overdue>{{ $stats['overdue'] }} متأخر</span>@endif
              @else — @endif
            </td>
            <td class="small">{{ $form->submissions_count }}</td>
            <td class="text-nowrap">
              <a href="{{ route('forms.show', $form) }}" class="btn btn-sm btn-outline-primary">عرض</a>
              @if($me->can_('form.results') && $form->submissions_count > 0)
                <a href="{{ route('forms.results', $form) }}" class="btn btn-sm btn-outline-secondary">النتائج</a>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="7" class="text-center text-muted py-4">لا نماذج. ابدأ بتوليد نموذج من المخاطر.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  @if($forms->hasPages())<div class="card-footer">{{ $forms->links() }}</div>@endif
</div>
@endsection
