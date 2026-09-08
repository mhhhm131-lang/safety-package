@extends('layouts.app')

@section('page_title', 'مخاطر المشروع — ' . $project->name)

@section('content')
<div class="container-fluid">

    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-1" style="color: var(--text-main);">
                <i class="bi bi-shield-exclamation me-2" style="color: var(--accent);"></i>
                مخاطر المشروع
            </h4>
            <p class="mb-0" style="color: var(--text-muted); font-size: 0.85rem;">
                <i class="bi bi-kanban"></i> {{ $project->name }}
                @if($project->code) · <code>{{ $project->code }}</code> @endif
                · <strong>{{ $risks->total() ?? $risks->count() }}</strong> خطر مرتبط
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('projects.show', $project) }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-right"></i> رجوع للمشروع
            </a>
            
        </div>
    </div>

    @include('modules.risks.partials._risk_table', [
        'risks' => $risks,
        'registry_type' => 'reference',
        'can_edit' => true,
    ])
</div>
@endsection
