@extends('layouts.app')

@section('page_title', 'تمارين الإخلاء')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0" style="color: var(--text-main);">
            <i class="bi bi-calendar-event me-2"></i>تمارين الإخلاء
        </h4>
        <div>
            <a href="{{ route('emergency.dashboard') }}" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-right me-1"></i>الرجوع
            </a>
            <a href="{{ route('emergency.drills.create') }}" class="btn btn-accent">
                <i class="bi bi-plus-lg me-1"></i>جدولة تمرين
            </a>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            @if($drills->isEmpty())
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-calendar-x fs-1 d-block mb-2"></i>
                    <p>لا توجد تمارين مجدولة</p>
                    <a href="{{ route('emergency.drills.create') }}" class="btn btn-accent">
                        <i class="bi bi-plus-lg me-1"></i>جدولة أول تمرين
                    </a>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>المكان</th>
                                <th>نوع التمرين</th>
                                <th>الموعد</th>
                                <th>الحالة</th>
                                <th>النتيجة</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($drills as $drill)
                            <tr>
                                <td>
                                    <strong>{{ $drill->place?->name ?? $drill->building->name }}</strong>
                                    <br><small class="text-muted">{{ $drill->drill_code }}</small>
                                </td>
                                <td>{{ $drill->getTypeLabel() }}</td>
                                <td>
                                    {{ $drill->scheduled_at->format('Y-m-d') }}
                                    <br><small class="text-muted">{{ $drill->scheduled_at->format('H:i') }}</small>
                                </td>
                                <td>
                                    @if($drill->status === 'scheduled')
                                        @if($drill->scheduled_at->isPast())
                                            <span class="badge bg-warning text-dark">متأخر</span>
                                        @else
                                            <span class="badge bg-info">مجدول</span>
                                        @endif
                                    @elseif($drill->status === 'in_progress')
                                        <span class="badge bg-primary">جاري</span>
                                    @elseif($drill->status === 'completed')
                                        <span class="badge bg-success">مكتمل</span>
                                    @else
                                        <span class="badge bg-secondary">ملغي</span>
                                    @endif
                                </td>
                                <td>
                                    @if($drill->status === 'completed')
                                        @if($drill->result === 'pass')
                                            <span class="badge bg-success">ناجح</span>
                                        @elseif($drill->result === 'fail')
                                            <span class="badge bg-danger">غير ناجح</span>
                                        @else
                                            <span class="badge bg-warning text-dark">يحتاج تحسين</span>
                                        @endif
                                        @if($drill->evacuation_time_sec)
                                            <br><small class="text-muted">{{ gmdate('i:s', $drill->evacuation_time_sec) }} · {{ $drill->score }}/100</small>
                                        @endif
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td>
                                    @php($I = \App\Modules\Emergency\Models\EmergencyIncident::class)
                                    @if($drill->status === 'scheduled')
                                        @can('trigger', $I)
                                        <form method="post" action="{{ route('emergency.drills.start', $drill) }}" class="d-inline" onsubmit="return confirm('بدء التمرين الآن؟ سيُنبَّه الفريق الأولي والقيادة كحالة معلَّمة تمريناً.')">@csrf<button class="btn btn-sm btn-primary" title="بدء التمرين"><i class="bi bi-play-fill"></i> بدء</button></form>
                                        @endcan
                                        @if(\App\Core\Permissions\PermissionRegistry::hasPermission(auth()->user()->role(), 'emergency.drill'))
                                        <form method="post" action="{{ route('emergency.drills.cancel', $drill) }}" class="d-inline" onsubmit="return confirm('إلغاء التمرين؟')">@csrf<button class="btn btn-sm btn-outline-secondary" title="إلغاء"><i class="bi bi-x"></i></button></form>
                                        @endif
                                    @elseif($drill->status === 'in_progress' && $drill->incident)
                                        <a href="{{ route('emergency.incidents.live', $drill->incident) }}" class="btn btn-sm btn-danger"><i class="bi bi-broadcast"></i> التتبع</a>
                                        @can('end', $drill->incident)<button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#endDrill{{ $drill->id }}"><i class="bi bi-check"></i> إنهاء</button>@endcan
                                    @elseif($drill->incident)
                                        <a href="{{ route('emergency.incidents.report', $drill->incident) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-text"></i></a>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="p-3">
                    {{ $drills->links() }}
                </div>
            @endif
        </div>
    </div>
    @if($stats['total_drills'])
    <div class="card mt-3"><div class="card-body small d-flex gap-4 flex-wrap">
        <span>تمارين مكتملة: <strong>{{ $stats['total_drills'] }}</strong></span>
        <span>متوسط الدرجة: <strong>{{ $stats['avg_score'] }}</strong>/100</span>
        <span>متوسط زمن الإخلاء: <strong>{{ gmdate('i:s', $stats['avg_evacuation_time']) }}</strong></span>
        <span>أفضل درجة: <strong>{{ $stats['best_score'] }}</strong></span>
    </div></div>
    @endif
</div>
@foreach($drills as $drill)
    @if($drill->status === 'in_progress' && $drill->incident)
    <div class="modal fade" id="endDrill{{ $drill->id }}" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post" action="{{ route('emergency.drills.end', $drill) }}">@csrf
        <div class="modal-header"><h5 class="modal-title">إنهاء التمرين {{ $drill->drill_code }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <label class="form-label">الملاحظات</label><textarea name="observations" class="form-control mb-2" rows="3"></textarea>
            <label class="form-label">التحسينات المقترحة</label><textarea name="improvements" class="form-control" rows="2"></textarea>
            <div class="form-text">تُحسب النتيجة من زمن الإخلاء مقابل المستهدف ونسبة من سُجّل وصولهم.</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-success">إنهاء وحساب النتيجة</button></div>
    </form></div></div></div>
    @endif
@endforeach
@endsection
