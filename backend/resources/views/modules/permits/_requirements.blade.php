@php
  // بنود التصريح بأطوارها الثلاثة + بنود بلا طور. $editable يفتح أزرار التوثيق.
  $phaseMeta = [
    'preventive'  => ['استباقي — قبل البدء', 'bi-shield-check', '#0d6efd'],
    'operational' => ['تشغيلي — أثناء العمل', 'bi-activity', '#fd7e14'],
    'response'    => ['استجابة — عند الطارئ', 'bi-alarm', '#c62828'],
  ];
  $evLabels = ['check' => 'تحقق', 'measurement' => 'قياس', 'photo' => 'صورة', 'signature' => 'توقيع', 'document' => 'وثيقة'];
  $roleLabels = ['requester' => 'الطالب', 'safety_engineer' => 'مسؤول السلامة', 'issuer' => 'مُصدر التصريح',
                 'worker' => 'العامل', 'fire_watch' => 'مراقب الحريق', 'attendant' => 'المراقب', 'any' => 'أي شخص'];
  $freqLabels = ['continuous' => 'مستمر', 'hourly' => 'كل ساعة', 'every_30_min' => 'كل ٣٠ دقيقة',
                 'daily' => 'يومياً', 'shift_change' => 'عند تغيّر الوردية'];
  $hasAny = collect($groupedRequirements)->flatten(1)->isNotEmpty();
@endphp

@if(!$hasAny)
  <div class="text-center text-muted py-4">
    <i class="bi bi-clipboard d-block fs-3 mb-2"></i>
    لا بنود بعد — تُولَّد من بنود تحكم نوع التصريح أو من فئات مخاطره.
  </div>
@else
  @foreach(['preventive', 'operational', 'response'] as $phase)
    @php($reqs = $groupedRequirements[$phase])
    @if($reqs->isNotEmpty())
      @php([$phaseLabel, $icon, $color] = $phaseMeta[$phase])
      @php($done = $reqs->filter->isComplete()->count())
      <div class="border-bottom">
        <div class="px-3 py-2 d-flex align-items-center gap-2" style="background:#f8faf9">
          <span class="badge" style="background:{{ $color }}1a;color:{{ $color }};border:1px solid {{ $color }}40">
            <i class="bi {{ $icon }}"></i> {{ $phaseLabel }}
          </span>
          <span class="small text-muted ms-auto" data-phase-count="{{ $phase }}">{{ $done }}/{{ $reqs->count() }}</span>
        </div>

        @foreach($reqs as $req)
          @php($complete = $req->isComplete())
          @php($failed = $req->status === 'failed')
          <div class="d-flex align-items-start gap-2 px-3 py-2 border-top"
               data-req="{{ $req->id }}" data-req-status="{{ $req->status }}"
               style="{{ $complete ? 'background:#f6faf7' : ($failed ? 'background:#fdf3f3' : '') }}">
            <div style="width:20px" class="pt-1">
              @if($req->status === 'completed')<i class="bi bi-check-circle-fill text-success"></i>
              @elseif($req->status === 'waived')<i class="bi bi-dash-circle text-info"></i>
              @elseif($failed)<i class="bi bi-x-circle-fill text-danger"></i>
              @else<i class="bi bi-circle text-muted"></i>@endif
            </div>

            <div class="flex-grow-1 small">
              <div>{{ $req->label() }}</div>
              <div class="d-flex flex-wrap gap-1 mt-1">
                @if($req->severity === 'mandatory')
                  <span class="badge bg-danger-subtle text-danger border border-danger-subtle" style="font-size:.65rem">إلزامي</span>
                @else
                  <span class="badge bg-light text-muted border" style="font-size:.65rem">موصى به</span>
                @endif
                @if($req->evidence_type)
                  <span class="badge bg-light text-dark border" style="font-size:.65rem">
                    {{ $evLabels[$req->evidence_type] ?? $req->evidence_type }}
                    @if($req->riskControl?->measurement_threshold)
                      — الحد {{ $req->riskControl->measurement_threshold }} {{ $req->riskControl->measurement_unit }}
                    @endif
                  </span>
                @endif
                @if($req->responsible_role)
                  <span class="badge bg-light text-muted border" style="font-size:.65rem">
                    <i class="bi bi-person-fill"></i> {{ $roleLabels[$req->responsible_role] ?? $req->responsible_role }}
                  </span>
                @endif
                @if($req->frequency)
                  <span class="badge bg-light text-muted border" style="font-size:.65rem">
                    <i class="bi bi-clock"></i> {{ $freqLabels[$req->frequency] ?? $req->frequency }}
                  </span>
                @endif
                @if($req->riskControl?->standard_reference)
                  <span class="badge bg-white text-muted border border-dashed" style="font-size:.65rem">{{ $req->riskControl->standard_reference }}</span>
                @endif
              </div>

              @if($req->evidence_value || $req->hasEvidenceFile())
                <div class="mt-1">
                  @if($req->evidence_type === 'measurement' && $req->evidence_value !== null)
                    @if($req->measurement_passed === true)
                      <span class="text-success fw-bold"><i class="bi bi-check-circle-fill"></i> {{ $req->evidence_value }} — اجتاز الحد</span>
                    @elseif($req->measurement_passed === false)
                      <span class="text-danger fw-bold"><i class="bi bi-x-circle-fill"></i> {{ $req->evidence_value }} — لم يجتز الحد</span>
                    @else
                      <span class="text-muted"><i class="bi bi-rulers"></i> {{ $req->evidence_value }}</span>
                    @endif
                  @elseif($req->evidence_value)
                    <span class="text-muted"><i class="bi bi-check2"></i> {{ $req->evidence_value }}</span>
                  @endif
                  @if($req->hasEvidenceFile())
                    <a href="{{ route('permits.requirements.evidence', [$permit, $req]) }}" target="_blank" class="ms-2">
                      <i class="bi bi-paperclip"></i> ملف الدليل
                    </a>
                  @endif
                  @if($req->completedBy)
                    <span class="text-muted">— {{ $req->completedBy->name }} {{ $req->completed_at?->diffForHumans() }}</span>
                  @endif
                </div>
              @endif

              @if($req->notes)<div class="text-muted mt-1" style="font-size:.75rem">{{ $req->notes }}</div>@endif
            </div>

            @if($editable)
              <div class="d-flex gap-1">
                @unless($complete)
                  <button class="btn btn-sm btn-outline-secondary py-0" data-bs-toggle="collapse"
                          data-bs-target="#ev{{ $req->id }}" title="توثيق الإتمام"><i class="bi bi-pencil-square"></i></button>
                @endunless
                <form method="post" action="{{ route('permits.requirements.toggle', [$permit, $req]) }}">
                  @csrf
                  <button class="btn btn-sm py-0 {{ $complete ? 'btn-success' : 'btn-outline-secondary' }}"
                          title="{{ $complete ? 'إعادة الفتح' : 'تعليم مكتملاً' }}">
                    <i class="bi bi-{{ $complete ? 'check-circle-fill' : 'circle' }}"></i>
                  </button>
                </form>
              </div>
            @endif
          </div>

          @if($editable && !$complete)
            <div class="collapse px-4 py-2 border-top" id="ev{{ $req->id }}" style="background:#f8faf9">
              <form method="post" action="{{ route('permits.requirements.complete', [$permit, $req]) }}"
                    enctype="multipart/form-data" class="row g-2 align-items-end">
                @csrf
                @if($req->evidence_type === 'measurement')
                  <div class="col-md-3">
                    <label class="form-label small mb-1">القيمة المقاسة
                      @if($req->riskControl?->measurement_unit)({{ $req->riskControl->measurement_unit }})@endif
                    </label>
                    <input type="text" name="evidence_value" class="form-control form-control-sm"
                           placeholder="{{ $req->riskControl?->measurement_threshold }}" required>
                  </div>
                @elseif(in_array($req->evidence_type, ['document', 'photo']))
                  <div class="col-md-3">
                    <label class="form-label small mb-1">المرجع أو الرقم</label>
                    <input type="text" name="evidence_value" class="form-control form-control-sm" placeholder="اختياري">
                  </div>
                  <div class="col-md-3">
                    <label class="form-label small mb-1">الملف (٥ م.ب حداً أقصى)</label>
                    <input type="file" name="evidence_file" class="form-control form-control-sm" accept="image/*,.pdf">
                  </div>
                @else
                  <input type="hidden" name="evidence_value" value="تم التحقق">
                @endif
                <div class="col-md-3">
                  <label class="form-label small mb-1">ملاحظة</label>
                  <input type="text" name="notes" class="form-control form-control-sm">
                </div>
                <div class="col-md-3 d-flex gap-1">
                  <button class="btn btn-sm btn-g flex-grow-1"><i class="bi bi-check-lg"></i> توثيق</button>
                  <button type="submit" class="btn btn-sm btn-outline-secondary"
                          formaction="{{ route('permits.requirements.waive', [$permit, $req]) }}"
                          title="إعفاء بمبرر (تُكتب الملاحظة)">إعفاء</button>
                </div>
              </form>
            </div>
          @endif
        @endforeach
      </div>
    @endif
  @endforeach

  @if($groupedRequirements['other']->isNotEmpty())
    <div class="px-3 py-2 small fw-bold text-muted border-bottom" style="background:#f8faf9">
      <i class="bi bi-folder2"></i> بنود أخرى (وثائق وتدريب)
    </div>
    @foreach($groupedRequirements['other'] as $req)
      <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom" data-req="{{ $req->id }}">
        <div style="width:20px">
          @if($req->status === 'completed')<i class="bi bi-check-circle-fill text-success"></i>
          @elseif($req->status === 'waived')<i class="bi bi-dash-circle text-info"></i>
          @else<i class="bi bi-circle text-muted"></i>@endif
        </div>
        <div class="flex-grow-1 small">{{ $req->label() }}</div>
        @if($req->severity === 'mandatory')
          <span class="badge bg-danger-subtle text-danger border border-danger-subtle" style="font-size:.65rem">إلزامي</span>
        @endif
        @if($editable)
          <form method="post" action="{{ route('permits.requirements.toggle', [$permit, $req]) }}">
            @csrf
            <button class="btn btn-sm py-0 {{ $req->isComplete() ? 'btn-success' : 'btn-outline-secondary' }}">
              <i class="bi bi-{{ $req->isComplete() ? 'check-circle-fill' : 'circle' }}"></i>
            </button>
          </form>
        @endif
      </div>
    @endforeach
  @endif
@endif
