@extends('layouts.app')

@section('title', 'كاميرات المراقبة')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">
            <i class="bi bi-camera-video me-2"></i>كاميرات المراقبة
        </h1>
        <a href="{{ route('emergency.iot.cameras.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>إضافة كاميرا
        </a>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="card bg-primary text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-0">إجمالي الكاميرات</h6>
                            <h2 class="mb-0">{{ $stats['total'] }}</h2>
                        </div>
                        <i class="bi bi-camera-video-fill fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card bg-success text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-0">متصلة</h6>
                            <h2 class="mb-0">{{ $stats['online'] }}</h2>
                        </div>
                        <i class="bi bi-check-circle-fill fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card bg-danger text-white h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-0">غير متصلة</h6>
                            <h2 class="mb-0">{{ $stats['offline'] }}</h2>
                        </div>
                        <i class="bi bi-x-circle-fill fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="card bg-warning text-dark h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title mb-0">أولوية طوارئ</h6>
                            <h2 class="mb-0">{{ $stats['priority'] }}</h2>
                        </div>
                        <i class="bi bi-star-fill fs-1 opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter -->
    <div class="card mb-4">
        <div class="card-body py-2">
            <div class="row align-items-center">
                <div class="col-md-4">
                    <select class="form-select form-select-sm" id="filterBuilding">
                        <option value="">كل المباني</option>
                        @foreach($buildings as $building)
                            <option value="{{ $building->id }}">{{ $building->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <select class="form-select form-select-sm" id="filterStatus">
                        <option value="">كل الحالات</option>
                        <option value="online">متصلة</option>
                        <option value="offline">غير متصلة</option>
                        <option value="maintenance">صيانة</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" id="searchCamera" placeholder="بحث...">
                        <button class="btn btn-outline-secondary" type="button">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Cameras Grid -->
    <div class="row g-3" id="camerasGrid">
        @forelse($cameras as $camera)
            <div class="col-lg-3 col-md-4 col-sm-6 camera-card"
                 data-building="{{ $camera->building_id }}"
                 data-status="{{ $camera->status }}"
                 data-name="{{ strtolower($camera->name) }}">
                <div class="card h-100 {{ $camera->is_emergency_priority ? 'border-warning border-2' : '' }}">
                    <!-- Camera Preview -->
                    <div class="card-img-top bg-dark position-relative" style="height: 150px;">
                        @if($camera->snapshot_url)
                            <img src="{{ $camera->snapshot_url }}" alt="{{ $camera->name }}"
                                 class="w-100 h-100 object-fit-cover"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        @endif
                        <div class="w-100 h-100 d-flex align-items-center justify-content-center text-white"
                             style="{{ $camera->snapshot_url ? 'display: none;' : '' }}">
                            <i class="bi bi-camera-video fs-1 opacity-50"></i>
                        </div>

                        <!-- Status Badge -->
                        <span class="position-absolute top-0 end-0 m-2 badge bg-{{ $camera->status === 'online' ? 'success' : ($camera->status === 'offline' ? 'danger' : 'warning') }}">
                            <i class="bi bi-{{ $camera->status === 'online' ? 'broadcast' : 'x-circle' }} me-1"></i>
                            {{ $camera->status === 'online' ? 'متصلة' : ($camera->status === 'offline' ? 'غير متصلة' : 'صيانة') }}
                        </span>

                        @if($camera->is_emergency_priority)
                            <span class="position-absolute top-0 start-0 m-2 badge bg-warning">
                                <i class="bi bi-star-fill"></i>
                            </span>
                        @endif

                        <!-- Live Button -->
                        @if($camera->stream_url && $camera->isOnline())
                            <button class="position-absolute bottom-0 end-0 m-2 btn btn-sm btn-danger"
                                    onclick="openStream('{{ $camera->stream_url }}', '{{ $camera->name }}')">
                                <i class="bi bi-play-circle me-1"></i>بث مباشر
                            </button>
                        @endif
                    </div>

                    <div class="card-body">
                        <h6 class="card-title mb-1">{{ $camera->name }}</h6>
                        <p class="card-text small text-muted mb-2">
                            @if($camera->building)
                                <i class="bi bi-building me-1"></i>{{ $camera->building->name }}
                            @endif
                            @if($camera->location)
                                <br><i class="bi bi-geo-alt me-1"></i>{{ $camera->location }}
                            @endif
                        </p>
                        <div class="d-flex flex-wrap gap-1">
                            <span class="badge bg-light text-dark">{{ $camera->getTypeLabel() }}</span>
                            @if($camera->has_audio)
                                <span class="badge bg-info"><i class="bi bi-mic"></i></span>
                            @endif
                            @if($camera->floor_number)
                                <span class="badge bg-secondary">طابق {{ $camera->floor_number }}</span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-camera-video fs-1 text-muted"></i>
                        <p class="text-muted mt-3 mb-0">لم يتم تسجيل أي كاميرات بعد</p>
                        <a href="{{ route('emergency.iot.cameras.create') }}" class="btn btn-primary mt-3">
                            <i class="bi bi-plus-lg me-1"></i>إضافة أول كاميرا
                        </a>
                    </div>
                </div>
            </div>
        @endforelse
    </div>
</div>

<!-- Stream Modal -->
<div class="modal fade" id="streamModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content bg-dark">
            <div class="modal-header border-0">
                <h5 class="modal-title text-white" id="streamTitle"></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="streamContainer" class="ratio ratio-16x9">
                    <iframe id="streamFrame" src="" allowfullscreen></iframe>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function openStream(url, name) {
    document.getElementById('streamTitle').textContent = name;
    document.getElementById('streamFrame').src = url;
    new bootstrap.Modal(document.getElementById('streamModal')).show();
}

document.getElementById('streamModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('streamFrame').src = '';
});

// Filtering
function filterCameras() {
    const building = document.getElementById('filterBuilding').value;
    const status = document.getElementById('filterStatus').value;
    const search = document.getElementById('searchCamera').value.toLowerCase();

    document.querySelectorAll('.camera-card').forEach(card => {
        const matchBuilding = !building || card.dataset.building === building;
        const matchStatus = !status || card.dataset.status === status;
        const matchSearch = !search || card.dataset.name.includes(search);

        card.style.display = (matchBuilding && matchStatus && matchSearch) ? '' : 'none';
    });
}

document.getElementById('filterBuilding').addEventListener('change', filterCameras);
document.getElementById('filterStatus').addEventListener('change', filterCameras);
document.getElementById('searchCamera').addEventListener('input', filterCameras);
</script>
@endpush
@endsection
