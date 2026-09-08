@extends('layouts.app')

@section('page_title', 'جهات الاتصال للطوارئ')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0" style="color: var(--text-main);">
            <i class="bi bi-telephone me-2"></i>جهات الاتصال للطوارئ
        </h4>
        <div>
            <a href="{{ route('emergency.contacts.create') }}" class="btn btn-accent me-2">
                <i class="bi bi-plus-lg me-1"></i>جهة اتصال جديدة
            </a>
            <a href="{{ route('emergency.dashboard') }}" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-right me-1"></i>الرجوع
            </a>
        </div>
    </div>

    <div class="card">
        <div class="card-body p-0">
            @if($contacts->isEmpty())
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-telephone-x fs-1 d-block mb-2"></i>
                    <p>لا توجد جهات اتصال مسجلة</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>الاسم</th>
                                <th>الدور</th>
                                <th>المبنى</th>
                                <th>الهاتف</th>
                                <th>النوع</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($contacts as $contact)
                            <tr>
                                <td>
                                    <span class="badge bg-secondary">{{ $contact->priority }}</span>
                                </td>
                                <td>
                                    <strong>{{ $contact->name }}</strong>
                                    @if($contact->organization)
                                        <br><small class="text-muted">{{ $contact->organization }}</small>
                                    @endif
                                </td>
                                <td>{{ $contact->role }}</td>
                                <td>
                                    @if($contact->building)
                                        <a href="{{ route('emergency.buildings.show', $contact->building) }}" class="text-decoration-none">
                                            {{ $contact->building->name }}
                                        </a>
                                    @else
                                        <span class="text-muted">عام</span>
                                    @endif
                                </td>
                                <td>
                                    <a href="tel:{{ $contact->phone }}" class="btn btn-sm btn-outline-success">
                                        <i class="bi bi-telephone me-1"></i>{{ $contact->phone }}
                                    </a>
                                    @if($contact->phone_alt)
                                        <br><small class="text-muted">{{ $contact->phone_alt }}</small>
                                    @endif
                                </td>
                                <td>
                                    @if($contact->contact_type === 'internal')
                                        <span class="badge bg-primary">داخلي</span>
                                    @else
                                        <span class="badge bg-info">خارجي</span>
                                    @endif
                                    @if($contact->auto_notify)
                                        <span class="badge bg-warning text-dark" title="إشعار تلقائي"><i class="bi bi-bell-fill"></i></span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('emergency.contacts.edit', $contact) }}" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form action="{{ route('emergency.contacts.destroy', $contact) }}" method="POST" class="d-inline" onsubmit="return confirm('هل أنت متأكد من حذف جهة الاتصال؟')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="p-3">
                    {{ $contacts->links() }}
                </div>
            @endif
        </div>
    </div>

    {{-- Emergency Numbers Card --}}
    <div class="card mt-4 border-danger">
        <div class="card-header bg-danger text-white">
            <h5 class="mb-0"><i class="bi bi-telephone-fill me-2"></i>أرقام الطوارئ العامة</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3 col-sm-6">
                    <div class="text-center p-3 bg-light rounded">
                        <h4 class="text-danger mb-1">911</h4>
                        <small class="text-muted">الطوارئ الموحد</small>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="text-center p-3 bg-light rounded">
                        <h4 class="text-danger mb-1">998</h4>
                        <small class="text-muted">الدفاع المدني</small>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="text-center p-3 bg-light rounded">
                        <h4 class="text-danger mb-1">997</h4>
                        <small class="text-muted">الإسعاف</small>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="text-center p-3 bg-light rounded">
                        <h4 class="text-danger mb-1">999</h4>
                        <small class="text-muted">الشرطة</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
