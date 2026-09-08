@extends('layouts.app')
@section('page_title', 'الأطراف الخارجية')
@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 style="color: var(--text-main);">الأطراف الخارجية</h4>
        <a href="{{ route('external-parties.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> إضافة</a>
    </div>
    <div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color);">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="color: var(--text-main);">
                    <thead><tr>
                        <th style="background:var(--bg-dark);color:var(--text-muted);">الاسم</th>
                        <th style="background:var(--bg-dark);color:var(--text-muted);">النوع</th>
                        <th style="background:var(--bg-dark);color:var(--text-muted);">الحالة</th>
                        <th style="background:var(--bg-dark);color:var(--text-muted);">جهة الاتصال</th>
                        <th style="background:var(--bg-dark);color:var(--text-muted);">الهاتف</th>
                    </tr></thead>
                    <tbody>
                        @forelse($parties ?? [] as $party)
                        <tr>
                            <td><a href="{{ route('external-parties.show', $party) }}" style="color:var(--accent);">{{ $party->name }}</a></td>
                            <td>{{ $party->getTypeLabel() }}</td>
                            <td><span class="badge {{ $party->status === 'active' ? 'bg-success' : ($party->status === 'blocked' ? 'bg-danger' : 'bg-secondary') }}">{{ $party->getStatusLabel() }}</span> @if($party->profile?->trust_score !== null)<span class="badge bg-light text-dark border">ثقة {{ $party->profile->trust_score }}</span>@endif</td>
                            <td>{{ $party->contact_person ?? '-' }}</td>
                            <td>{{ $party->phone ?? '-' }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">لا توجد أطراف خارجية</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
