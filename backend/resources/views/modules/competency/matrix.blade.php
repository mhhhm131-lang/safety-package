@extends('layouts.app')

@section('page_title', 'مصفوفة الكفاءة')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h5 class="fw-bold mb-0" style="color: var(--text-main);">مصفوفة الكفاءة</h5>
        <div class="d-flex gap-2">
            <button class="btn btn-primary" id="saveMatrix">
                <i class="bi bi-check-circle me-1"></i> حفظ التغييرات
            </button>
        </div>
    </div>

    <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color);">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-dark table-bordered mb-0" style="table-layout: auto;">
                    <thead>
                        <tr>
                            <th class="sticky-col" style="color: var(--text-muted); background: var(--bg-card); min-width: 150px;">المهنة \ موضوع التدريب</th>
                            @foreach($topics ?? [] as $topic)
                                <th class="text-center" style="color: var(--text-muted); min-width: 100px; font-size: 0.85rem;">
                                    {{ $topic->name }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($trades ?? [] as $trade)
                            <tr>
                                <td class="sticky-col fw-bold" style="color: var(--text-main); background: var(--bg-card);">
                                    {{ $trade->name }}
                                </td>
                                @foreach($topics ?? [] as $topic)
                                    @php
                                        $key = $trade->id . '_' . $topic->id;
                                        $isRequired = isset($matrix[$key]) && $matrix[$key]['required'];
                                        $completionStatus = $matrix[$key]['completion'] ?? 'none';
                                        // Colors: green = all completed, yellow = partially, red = required but not done, gray = not required
                                        if (!$isRequired) {
                                            $cellBg = 'transparent';
                                            $cellIcon = '';
                                        } elseif ($completionStatus === 'completed') {
                                            $cellBg = 'rgba(25,135,84,0.3)';
                                            $cellIcon = '<i class="bi bi-check-circle-fill text-success"></i>';
                                        } elseif ($completionStatus === 'partial') {
                                            $cellBg = 'rgba(255,193,7,0.3)';
                                            $cellIcon = '<i class="bi bi-dash-circle-fill text-warning"></i>';
                                        } else {
                                            $cellBg = 'rgba(220,53,69,0.2)';
                                            $cellIcon = '<i class="bi bi-x-circle-fill text-danger"></i>';
                                        }
                                    @endphp
                                    <td class="text-center" style="background: {{ $cellBg }};">
                                        <div class="form-check d-flex justify-content-center align-items-center gap-2">
                                            <input type="checkbox" class="form-check-input matrix-checkbox"
                                                   name="requirements[{{ $trade->id }}][{{ $topic->id }}]"
                                                   value="1"
                                                   @checked($isRequired)
                                                   data-trade="{{ $trade->id }}" data-topic="{{ $topic->id }}">
                                            {!! $cellIcon !!}
                                        </div>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Legend --}}
    <div class="d-flex gap-4 mt-3 justify-content-center">
        <span style="color: var(--text-muted);">
            <i class="bi bi-check-circle-fill text-success me-1"></i> مكتمل
        </span>
        <span style="color: var(--text-muted);">
            <i class="bi bi-dash-circle-fill text-warning me-1"></i> جزئي
        </span>
        <span style="color: var(--text-muted);">
            <i class="bi bi-x-circle-fill text-danger me-1"></i> مطلوب - غير مكتمل
        </span>
        <span style="color: var(--text-muted);">
            <i class="bi bi-square me-1"></i> غير مطلوب
        </span>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('saveMatrix').addEventListener('click', function() {
        const checkboxes = document.querySelectorAll('.matrix-checkbox');
        const requirements = {};

        checkboxes.forEach(cb => {
            const trade = cb.dataset.trade;
            const topic = cb.dataset.topic;
            if (!requirements[trade]) requirements[trade] = {};
            requirements[trade][topic] = cb.checked ? 1 : 0;
        });

        fetch('{{ route("competency.toggle-requirement") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ requirements })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert('تم حفظ التغييرات بنجاح');
                location.reload();
            }
        })
        .catch(() => alert('حدث خطأ أثناء الحفظ'));
    });
});
</script>
@endpush
