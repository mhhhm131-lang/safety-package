@extends('layouts.app')

@section('page_title', 'ملف المقاول — ' . $externalParty->name)

@section('content')
<div class="container-fluid">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="small" style="color: var(--text-muted);">
                <a href="{{ route('external-parties.show', $externalParty) }}" class="text-decoration-none" style="color: var(--text-muted);">
                    <i class="bi bi-arrow-right"></i> {{ $externalParty->name }}
                </a>
            </div>
            <h4 class="fw-bold mb-1" style="color: var(--text-main);">
                ملف التأهيل — الفحوص الثمانية ودرجة الثقة
            </h4>
        </div>

        <div class="d-flex gap-2">
            @if(auth()->user()->can_('external_party.edit'))
            {{-- Enrich button --}}
            <form method="POST" action="{{ route('external-parties.enrich', $externalParty) }}">
                @csrf
                <button class="btn btn-sm btn-primary">
                    <i class="bi bi-arrow-repeat"></i> تحديث من المصادر
                </button>
            </form>

            {{-- Portal link button --}}
            <form method="POST" action="{{ route('external-parties.portal-link', $externalParty) }}">
                @csrf
                <button class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-link-45deg"></i> رابط تعبئة للمقاول
                </button>
            </form>
            @endif
        </div>
    </div>

    @if(session('portal_link'))
        <div class="alert alert-info d-flex align-items-center gap-2">
            <i class="bi bi-link-45deg fs-5"></i>
            <div>
                <strong>رابط البوابة:</strong>
                <code class="ms-2 user-select-all">{{ session('portal_link') }}</code>
                <small class="text-muted ms-2">(صالح 7 أيام)</small>
            </div>
        </div>
    @endif

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="row g-3">

        {{-- Trust Score Card --}}
        <div class="col-md-3">
            <div class="card h-100 text-center">
                <div class="card-body py-4">
                    <div class="fs-1 fw-bold
                        @if($evaluation['label'] === 'high') text-success
                        @elseif($evaluation['label'] === 'medium') text-warning
                        @else text-danger @endif">
                        {{ $evaluation['score'] }}
                    </div>
                    <div class="small text-muted">/ 100</div>
                    <div class="mt-2">
                        <span class="badge fs-6
                            @if($evaluation['label'] === 'high') bg-success
                            @elseif($evaluation['label'] === 'medium') bg-warning text-dark
                            @else bg-danger @endif">
                            @if($evaluation['label'] === 'high') ثقة عالية
                            @elseif($evaluation['label'] === 'medium') ثقة متوسطة
                            @else ثقة منخفضة @endif
                        </span>
                    </div>
                    @if($profile->trust_score_computed_at)
                        <div class="mt-2 small text-muted">
                            آخر حساب: {{ $profile->trust_score_computed_at->diffForHumans() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Checks Breakdown --}}
        <div class="col-md-5">
            <div class="card h-100">
                <div class="card-header fw-semibold">نتائج الفحوصات التلقائية</div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tbody>
                        @foreach($evaluation['checks'] as $key => $check)
                            <tr>
                                <td class="ps-3">
                                    @if($check['passed'])
                                        <i class="bi bi-check-circle-fill text-success"></i>
                                    @elseif($check['points'] > 0)
                                        <i class="bi bi-exclamation-circle-fill text-warning"></i>
                                    @else
                                        <i class="bi bi-x-circle-fill text-danger"></i>
                                    @endif
                                </td>
                                @php
                                    // ملاحظة: الصيغة الكتلية لا المختصرة — الملف فيه كتل @php…@endphp لاحقة والمختصرة قبلها تُبتلع
                                    $checkLabels = ['cr_active' => 'السجل التجاري ساري', 'insurance_active' => 'التأمين ساري', 'iso_cert' => 'شهادة ISO', 'gosi_registered' => 'التأمينات الاجتماعية', 'etimad_active' => 'منصة اعتماد', 'docs_complete' => 'المستندات الإلزامية: سجل وتأمين', 'no_critical_incidents' => 'لا بلاغات عاجلة مفتوحة', 'trade_coverage' => 'عمال مسجّلون'];
                                @endphp
                                <td class="small"><strong>{{ $checkLabels[$key] ?? $key }}</strong> <span class="text-muted" data-check="{{ $key }}" data-passed="{{ $check['passed'] ? 1 : 0 }}">— {{ $check['reason'] }}</span></td>
                                <td class="text-end pe-3 small fw-semibold">
                                    {{ $check['points'] }}/{{ $check['max'] }}
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Profile Data --}}
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header fw-semibold">بيانات الملف الموثّقة</div>
                <div class="card-body">
                    @php
                        $fields = [
                            'تاريخ انتهاء السجل التجاري' => $profile->cr_expiry_date?->toDateString(),
                            'مزود التأمين'               => $profile->insurance_provider,
                            'انتهاء التأمين'              => $profile->insurance_expiry_date?->toDateString(),
                            'شهادة ISO'                  => $profile->iso_cert_number,
                            'حساب التأمينات الاجتماعية'  => $profile->gosi_account_number,
                            'رقم اعتماد'                 => $profile->etimad_entity_number,
                            'تصنيف مقاول'                => $profile->muqawil_classification,
                            'آخر مصدر'                   => $profile->last_enriched_channel,
                        ];
                    @endphp
                    @foreach($fields as $label => $value)
                        @if($value)
                            <div class="d-flex justify-content-between border-bottom py-1 small">
                                <span class="text-muted">{{ $label }}</span>
                                <span class="fw-semibold">{{ $value }}</span>
                            </div>
                        @endif
                    @endforeach
                    @if(!array_filter($fields))
                        <p class="text-muted small text-center py-3">لا بيانات بعد — تُدخل يدوياً أدناه أو من المستندات الموثّقة.</p>
                    @endif
                </div>
            </div>
        </div>

        @if(auth()->user()->can_('external_party.edit'))
        {{-- المعهد: إدخال يدوي لبيانات الملف (OHSMS كان يملؤها من القنوات فقط) --}}
        <div class="col-12">
            <form method="POST" action="{{ route('external-parties.profile.update', $externalParty) }}" class="card">
                @csrf @method('PUT')
                <div class="card-header fw-semibold">تحديث بيانات الملف يدوياً</div>
                <div class="card-body row g-2">
                    <div class="col-md-3"><label class="form-label small">انتهاء السجل التجاري</label><input type="date" name="cr_expiry_date" class="form-control form-control-sm" value="{{ old('cr_expiry_date', $profile->cr_expiry_date?->toDateString()) }}"></div>
                    <div class="col-md-3"><label class="form-label small">مزود التأمين</label><input name="insurance_provider" class="form-control form-control-sm" value="{{ old('insurance_provider', $profile->insurance_provider) }}"></div>
                    <div class="col-md-3"><label class="form-label small">رقم وثيقة التأمين</label><input name="insurance_policy_number" class="form-control form-control-sm" value="{{ old('insurance_policy_number', $profile->insurance_policy_number) }}"></div>
                    <div class="col-md-3"><label class="form-label small">انتهاء التأمين</label><input type="date" name="insurance_expiry_date" class="form-control form-control-sm" value="{{ old('insurance_expiry_date', $profile->insurance_expiry_date?->toDateString()) }}"></div>
                    <div class="col-md-3"><label class="form-label small">شهادة ISO</label><input name="iso_cert_number" class="form-control form-control-sm" value="{{ old('iso_cert_number', $profile->iso_cert_number) }}"></div>
                    <div class="col-md-3"><label class="form-label small">انتهاء ISO</label><input type="date" name="iso_cert_expiry_date" class="form-control form-control-sm" value="{{ old('iso_cert_expiry_date', $profile->iso_cert_expiry_date?->toDateString()) }}"></div>
                    <div class="col-md-2"><label class="form-label small">التأمينات الاجتماعية</label><input name="gosi_account_number" class="form-control form-control-sm" value="{{ old('gosi_account_number', $profile->gosi_account_number) }}"></div>
                    <div class="col-md-2"><label class="form-label small">رقم اعتماد</label><input name="etimad_entity_number" class="form-control form-control-sm" value="{{ old('etimad_entity_number', $profile->etimad_entity_number) }}"></div>
                    <div class="col-md-2"><label class="form-label small">تصنيف المقاولين</label><input name="muqawil_classification" class="form-control form-control-sm" value="{{ old('muqawil_classification', $profile->muqawil_classification) }}"></div>
                    <div class="col-12"><button class="btn btn-sm btn-g">حفظ الملف</button> <span class="small text-muted">درجة الثقة تُعاد بعد الحفظ.</span></div>
                </div>
            </form>
        </div>
        @endif

        {{-- Enabled Channels --}}
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold">قنوات البيانات المُفعَّلة</span>
                    @if(auth()->user()->can_('integration.manage'))<a href="{{ route('settings.contractor-channels') }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-gear"></i> إعداد القنوات
                    </a>@endif
                </div>
                <div class="card-body">
                    <div class="row g-2">
                        @php
                            $channelLabels = [
                                'pdf_upload'     => ['label' => 'رفع PDF', 'icon' => 'bi-file-pdf'],
                                'portal_link'    => ['label' => 'بوابة المقاول', 'icon' => 'bi-link-45deg'],
                                'etimad'         => ['label' => 'منصة اعتماد', 'icon' => 'bi-bank'],
                                'gosi'           => ['label' => 'التأمينات الاجتماعية', 'icon' => 'bi-shield-check'],
                                'muqawil'        => ['label' => 'منصة مقاول', 'icon' => 'bi-building'],
                                'contractor_api' => ['label' => 'API المقاول', 'icon' => 'bi-plug'],
                            ];
                        @endphp
                        @foreach($channelConfig as $type => $config)
                            <div class="col-md-2 col-4">
                                <div class="border rounded p-2 text-center small
                                    {{ ($config['enabled'] ?? false) ? 'border-success bg-success bg-opacity-10' : 'border-secondary opacity-50' }}">
                                    <i class="bi {{ $channelLabels[$type]['icon'] }} fs-5 d-block mb-1"></i>
                                    {{ $channelLabels[$type]['label'] }}
                                    @if($config['enabled'] ?? false)
                                        <span class="badge bg-success ms-1">مُفعَّل</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- Verification History --}}
        @if($verifications->isNotEmpty())
        <div class="col-12">
            <div class="card">
                <div class="card-header fw-semibold">سجل التحققات</div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>الحقل</th>
                                <th>القيمة</th>
                                <th>المصدر</th>
                                <th>الثقة</th>
                                <th>تاريخ التحقق</th>
                                <th>ينتهي</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($verifications as $v)
                            <tr class="{{ $v->isExpired() ? 'table-warning' : '' }}">
                                <td class="small fw-semibold">{{ $v->field_name }}</td>
                                <td class="small">{{ Str::limit($v->field_value, 30) }}</td>
                                <td>
                                    <span class="badge bg-secondary">{{ $v->source_channel }}</span>
                                </td>
                                <td>
                                    <div class="progress" style="height:8px;width:60px;">
                                        <div class="progress-bar
                                            @if($v->confidence_score >= 70) bg-success
                                            @elseif($v->confidence_score >= 40) bg-warning
                                            @else bg-danger @endif"
                                            style="width:{{ $v->confidence_score }}%"></div>
                                    </div>
                                    <small>{{ $v->confidence_score }}%</small>
                                </td>
                                <td class="small">{{ $v->verified_at->format('Y-m-d') }}</td>
                                <td class="small">
                                    @if($v->expires_at)
                                        <span class="{{ $v->isExpired() ? 'text-danger' : 'text-muted' }}">
                                            {{ $v->expires_at->toDateString() }}
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @endif

    </div>
</div>
@endsection
