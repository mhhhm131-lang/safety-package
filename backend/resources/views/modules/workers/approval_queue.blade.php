@extends('layouts.app')
@section('page_title', 'طلبات الموافقة')
@section('content')
<div class="container-fluid">
    <h4 class="mb-4" style="color: var(--text-main);">طلبات موافقة العمال</h4>
    <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color);">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="color: var(--text-main);">
                    <thead><tr>
                        <th style="background:var(--bg-dark);color:var(--text-muted);">الاسم</th>
                        <th style="background:var(--bg-dark);color:var(--text-muted);">المهنة</th>
                        <th style="background:var(--bg-dark);color:var(--text-muted);">الطرف الخارجي</th>
                        <th style="background:var(--bg-dark);color:var(--text-muted);">التاريخ</th>
                        <th style="background:var(--bg-dark);color:var(--text-muted);">الإجراء</th>
                    </tr></thead>
                    <tbody>
                        @forelse($queue ?? [] as $worker)
                        <tr>
                            <td><a href="{{ route('workers.show', $worker) }}">{{ $worker->full_name }}</a></td>
                            <td>{{ $worker->trade->name ?? '-' }}</td>
                            <td>{{ $worker->externalParty->name ?? '-' }}</td>
                            <td style="color:var(--text-muted);">{{ $worker->created_at->format('Y-m-d') }}</td>
                            <td>
                                <form method="POST" action="{{ route('workers.transition', $worker) }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="status" value="induction">
                                    <button class="btn btn-sm btn-success" title="قبول وبدء التعريف"><i class="bi bi-check-lg"></i> قبول → تعريف</button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">لا توجد طلبات</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
