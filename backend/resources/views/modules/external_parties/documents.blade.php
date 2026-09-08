@extends('layouts.app')
@section('page_title', 'مستندات '.$externalParty->name)
@section('content')
@php($me = auth()->user())
@php($types = ['cr' => 'سجل تجاري', 'license' => 'رخصة', 'insurance' => 'تأمين', 'safety_cert' => 'شهادة سلامة', 'iso_cert' => 'شهادة ISO', 'other' => 'أخرى'])
<div class="d-flex align-items-center gap-2 mb-3">
  <h1 class="h4 m-0"><i class="bi bi-folder2-open me-2"></i>مستندات {{ $externalParty->name }}</h1>
  <a href="{{ $me->isContractorRole() ? route('contractor.home') : route('external-parties.show', $externalParty) }}" class="btn btn-sm btn-outline-secondary ms-auto">رجوع</a>
</div>
<div class="alert alert-info py-2 small">السجل التجاري والتأمين إلزاميان للتأهيل. بعد الرفع يوثّق مسؤول السلامة المستند فتُغذّي تواريخ انتهائه ملف التأهيل (قناة رفع المستندات).</div>

<div class="card mb-3"><div class="card-body">
  <form method="POST" action="{{ route('external-parties.documents.store', $externalParty) }}" enctype="multipart/form-data" class="row g-2 align-items-end">
    @csrf
    <div class="col-md-3"><label class="form-label small">الاسم</label><input name="name" class="form-control form-control-sm" required maxlength="200"></div>
    <div class="col-md-2"><label class="form-label small">النوع</label><select name="document_type" class="form-select form-select-sm" required>@foreach($types as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
    <div class="col-md-2"><label class="form-label small">انتهاء الصلاحية</label><input type="date" name="expiry_date" class="form-control form-control-sm"></div>
    <div class="col-md-3"><label class="form-label small">الملف (PDF/صورة حتى ٥ م.ب)</label><input type="file" name="file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png"></div>
    <div class="col-md-2"><button class="btn btn-sm btn-g w-100">رفع</button></div>
    <div class="col-12"><input name="notes" class="form-control form-control-sm" placeholder="ملاحظة (اختياري)"></div>
  </form>
</div></div>

<div class="card"><div class="card-body p-0">
  <table class="table table-sm align-middle mb-0" id="party-documents">
    <thead><tr><th>الاسم</th><th>النوع</th><th>الانتهاء</th><th>الحالة</th><th>المصدر</th><th>موثّق</th><th>الملف</th><th></th></tr></thead>
    <tbody>
    @forelse($documents as $doc)
      <tr data-doc="{{ $doc->id }}" data-type="{{ $doc->document_type }}" data-verified="{{ $doc->is_verified ? 1 : 0 }}">
        <td>{{ $doc->name }}</td>
        <td>{{ $types[$doc->document_type] ?? $doc->document_type }}</td>
        <td dir="ltr">{{ $doc->expiry_date?->toDateString() ?? '—' }}</td>
        <td>@if($doc->is_expired)<span class="badge bg-danger">منتهٍ</span>@elseif($doc->is_expiring_soon)<span class="badge bg-warning text-dark">يقارب الانتهاء</span>@elseif($doc->expiry_date)<span class="badge bg-success">ساري</span>@else<span class="badge bg-secondary">بلا تاريخ</span>@endif</td>
        <td class="small">{{ $doc->source_channel }}</td>
        <td>@if($doc->is_verified)<span class="text-success">✓ {{ $doc->verified_at?->format('Y-m-d') }}</span>@else<span class="text-muted">لا</span>@endif</td>
        <td>@if($doc->hasFile())<a href="{{ route('external-parties.documents.download', [$externalParty, $doc]) }}" target="_blank" class="btn btn-sm btn-outline-primary" title="{{ $doc->file }}"><i class="bi bi-download"></i></a>@else —@endif</td>
        <td>@if(!$doc->is_verified && $me->can_('external_party.edit'))<form method="POST" action="{{ route('external-parties.documents.verify', [$externalParty, $doc]) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-success">توثيق</button></form>@endif</td>
      </tr>
    @empty
      <tr><td colspan="8" class="text-center text-muted py-4">لا مستندات بعد</td></tr>
    @endforelse
    </tbody>
  </table>
</div></div>
<div class="mt-2">{{ $documents->links() }}</div>
@endsection
