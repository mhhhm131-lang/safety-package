@extends('layouts.app')

@section('page_title', 'إعدادات قنوات بيانات المقاولين')

@section('content')
<div class="container-fluid">

    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h4 class="fw-bold mb-1" style="color: var(--text-main);">قنوات بيانات المقاولين</h4>
            <p class="text-muted small mb-0">
                حدّد القنوات التي يستعملها المعهد للتحقق من بيانات المقاولين. رفع المستندات ورابط التعبئة بلا إعداد؛ اعتماد والتأمينات تحتاجان مفاتيح فعلية ولا تعمل بلا مفاتيح (لا محاكاة). الفحوص المرتبطة بقناة معطّلة تُمنح درجتها كاملة.
            </p>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <form method="POST" action="{{ route('settings.contractor-channels.save') }}">
        @csrf

        @php
            $channelInfo = [
                'pdf_upload'     => ['label' => 'رفع PDF يدوي',         'icon' => 'bi-file-pdf',       'desc' => 'مسؤول السلامة يرفع مستندات المقاول ويوثّقها. لا يحتاج إعداداً.', 'needs_key' => false],
                'portal_link'    => ['label' => 'بوابة المقاول الذاتية','icon' => 'bi-link-45deg',     'desc' => 'يُرسَل للمقاول رابط مؤقت ليرفع وثائقه بنفسه. لا يحتاج إعداداً.', 'needs_key' => false],
                'etimad'         => ['label' => 'منصة اعتماد',          'icon' => 'bi-bank',           'desc' => 'للجهات الحكومية — يتحقق من حالة المقاول على منصة اعتماد.', 'needs_key' => true],
                'gosi'           => ['label' => 'التأمينات الاجتماعية', 'icon' => 'bi-shield-check',   'desc' => 'يتحقق من تسجيل المقاول في التأمينات الاجتماعية.', 'needs_key' => true],
                'muqawil'        => ['label' => 'منصة مقاول',           'icon' => 'bi-building',       'desc' => 'يتحقق من تصنيف المقاول في منصة مقاول.', 'needs_key' => true],
                'contractor_api' => ['label' => 'API المقاول',          'icon' => 'bi-plug-fill',      'desc' => 'المقاول يوفّر API خاصاً ببياناته (مناسب للشركات الكبيرة).', 'needs_key' => true],
            ];
        @endphp

        <div class="row g-3">
            @foreach($channelInfo as $type => $info)
                @php $config = $channelConfig[$type] ?? []; @endphp
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 {{ ($config['enabled'] ?? false) ? 'border-primary' : '' }}">
                        <div class="card-header d-flex align-items-center gap-2">
                            <i class="bi {{ $info['icon'] }} fs-5"></i>
                            <span class="fw-semibold">{{ $info['label'] }}</span>
                            <div class="ms-auto form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox"
                                    name="channels[{{ $type }}][enabled]"
                                    value="1"
                                    id="ch_{{ $type }}"
                                    {{ ($config['enabled'] ?? false) ? 'checked' : '' }}>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted mb-3">{{ $info['desc'] }}</p>

                            <div class="mb-2">
                                <label class="form-label small">الأولوية (1 = الأعلى)</label>
                                <input type="number" class="form-control form-control-sm"
                                    name="channels[{{ $type }}][priority]"
                                    value="{{ $config['priority'] ?? 50 }}"
                                    min="1" max="99">
                            </div>

                            @if($info['needs_key'])
                                <div class="mb-2">
                                    <label class="form-label small">مفتاح API</label>
                                    <input type="password" class="form-control form-control-sm"
                                        name="channels[{{ $type }}][api_key]"
                                        placeholder="اتركه فارغاً لإبقاء المفتاح الحالي">
                                </div>
                                <div class="mb-0">
                                    <label class="form-label small">عنوان الخادم (Base URL)</label>
                                    <input type="url" class="form-control form-control-sm"
                                        name="channels[{{ $type }}][base_url]"
                                        placeholder="https://api.example.sa/v1">
                                </div>
                            @else
                                <p class="small text-success mb-0"><i class="bi bi-check-circle"></i> لا يحتاج إعداداً إضافياً</p>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-4">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-save"></i> حفظ الإعدادات
            </button>
            <a href="{{ url()->previous() }}" class="btn btn-outline-secondary ms-2">إلغاء</a>
        </div>

    </form>
</div>
@endsection
