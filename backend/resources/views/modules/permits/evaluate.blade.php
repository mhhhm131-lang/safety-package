@extends('layouts.app')
@section('page_title', 'تقييم ما بعد الإغلاق — ' . $permit->code)
@section('content')

<div class="d-flex align-items-center gap-3 mb-3">
  <a href="{{ route('permits.show', $permit) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-right"></i></a>
  <div>
    <h1 class="h5 m-0">تقييم ما بعد الإغلاق</h1>
    <div class="small text-muted"><span dir="ltr">{{ $permit->code }}</span> — {{ $permit->title }}</div>
  </div>
  @if($existing)
    <span class="badge bg-success ms-auto"><i class="bi bi-check-circle-fill"></i> قُيّم مسبقاً</span>
  @endif
</div>

<div class="alert alert-light border py-2 small">
  <i class="bi bi-info-circle"></i>
  التقييم يغذّي حلقة التعلّم: إن قُدِّر الخطر أقل من الواقع أو ذُكر إخفاق، تُعلَّم بنود تحكم هذا التصريح
  للمراجعة، وتُربط بلاغات فترة التصريح به تلقائياً.
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <form method="post" action="{{ route('permits.evaluate.save', $permit) }}">
      @csrf
      <div class="card mb-3">
        <div class="card-header">التقييم العام</div>
        <div class="card-body">
          <label class="form-label fw-bold">فعالية التصريح إجمالاً <span class="text-danger">*</span></label>
          <div class="d-flex gap-3 mb-4">
            @foreach([1 => 'ضعيف', 2 => 'مقبول', 3 => 'جيد', 4 => 'جيد جداً', 5 => 'ممتاز'] as $i => $label)
              <label class="text-center" style="cursor:pointer">
                <input type="radio" name="overall_rating" value="{{ $i }}" class="form-check-input d-block mx-auto"
                       @checked(($existing['overall_rating'] ?? 0) == $i) required>
                <small class="text-muted">{{ $label }}</small>
              </label>
            @endforeach
          </div>

          <label class="form-label fw-bold">هل قُدِّرت المخاطر بدقة؟ <span class="text-danger">*</span></label>
          <div class="d-flex gap-3 flex-wrap">
            @foreach(['underestimated' => 'قُدِّرت أقل من الواقع', 'accurate' => 'دقيقة', 'overestimated' => 'قُدِّرت أكثر من الواقع'] as $val => $label)
              <label class="border rounded px-3 py-2 small" style="cursor:pointer">
                <input type="radio" name="severity_match" value="{{ $val }}" @checked(($existing['severity_match'] ?? '') === $val) required>
                {{ $label }}
              </label>
            @endforeach
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header">تحليل الأداء</div>
        <div class="card-body row g-3">
          <div class="col-md-6">
            <label class="form-label small fw-bold text-success"><i class="bi bi-hand-thumbs-up"></i> ما الذي نجح؟</label>
            <textarea name="what_worked" class="form-control" rows="4" maxlength="2000">{{ $existing['what_worked'] ?? '' }}</textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-bold text-danger"><i class="bi bi-hand-thumbs-down"></i> ما الذي أخفق؟</label>
            <textarea name="what_failed" class="form-control" rows="4" maxlength="2000">{{ $existing['what_failed'] ?? '' }}</textarea>
            <div class="form-text">ذكر إخفاق هنا يُعلّم بنود التحكم للمراجعة.</div>
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header">الدروس المستخلصة</div>
        <div class="card-body">
          <label class="form-label small fw-bold"><i class="bi bi-lightbulb"></i> ماذا تعلّمنا؟</label>
          <textarea name="lessons_learned" class="form-control mb-3" rows="3" maxlength="2000">{{ $existing['lessons_learned'] ?? '' }}</textarea>
          <label class="form-label small fw-bold"><i class="bi bi-pencil-square"></i> تعديل مقترح على بنود التحكم</label>
          <input type="text" name="recommend_changes" class="form-control" maxlength="1000" value="{{ $existing['recommend_changes'] ?? '' }}"
                 placeholder="مثال: إضافة بند قياس الأكسجين قبل الدخول">
        </div>
      </div>

      <div class="d-flex justify-content-between">
        <a href="{{ route('permits.show', $permit) }}" class="btn btn-outline-secondary">العودة</a>
        <button class="btn btn-g fw-bold"><i class="bi bi-save"></i> {{ $existing ? 'تحديث التقييم' : 'حفظ التقييم' }}</button>
      </div>
    </form>
  </div>

  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header">ملخّص البنود المنفَّذة</div>
      <div class="card-body">
        @foreach(['preventive' => 'استباقي', 'operational' => 'تشغيلي', 'response' => 'استجابة'] as $phase => $label)
          @php($reqs = $groupedRequirements[$phase])
          <div class="d-flex justify-content-between align-items-center py-2 border-bottom small">
            <span>{{ $label }}</span>
            <span class="badge bg-light text-dark border">{{ $reqs->filter->isComplete()->count() }} / {{ $reqs->count() }}</span>
          </div>
        @endforeach
      </div>
    </div>

    <div class="card">
      <div class="card-header"><i class="bi bi-exclamation-triangle text-danger"></i> بلاغات فترة التصريح</div>
      <div class="card-body p-0">
        @forelse($linkedIncidents as $inc)
          <div class="px-3 py-2 border-bottom small">
            <a href="/app/incidents/{{ $inc->id }}" class="fw-bold">{{ $inc->code }}</a>
            <div class="text-muted">{{ \Illuminate\Support\Str::limit($inc->title, 45) }}</div>
            <div class="text-muted" style="font-size:.72rem">{{ $inc->created_at->format('Y-m-d') }}</div>
          </div>
        @empty
          <div class="text-center text-muted py-3 small">لا بلاغات مرتبطة. تُربط آلياً عند حفظ التقييم.</div>
        @endforelse
      </div>
    </div>
  </div>
</div>
@endsection
