@extends('layouts.app')
@section('page_title', 'مهل بلاغ الشاغل')
@section('content')
<h1 class="h4 mb-1">مهل بلاغ الشاغل</h1>
<div class="small text-muted mb-3">المهلة بالساعات من وقت الإرسال حتى «استلمه الفني»، وتُعاد عند الإحالة. الفارغ = لا مهلة ولا تصعيد آلي (BACKEND.md ٥-٢-ب ج — بلا قيم افتراضية). عند التجاوز: إشعار لمسؤول السلامة والمناوب وتصعيد للجنة السلامة (وحتى تشكيلها لمسؤول السلامة)، ويُقيَّد في الخط الزمني.</div>
<form method="post" action="{{ route('incidents.settings.update') }}" class="card p-3" style="max-width:520px">
  @csrf
  @foreach($labels as $key => $label)
    <div class="mb-3">
      <label class="form-label fw-bold">{{ $label }}</label>
      <div class="input-group"><input type="number" step="0.25" min="0.25" max="720" name="{{ str_replace('.', '_', $key) }}" class="form-control" value="{{ old(str_replace('.', '_', $key), $values[$key]) }}" placeholder="لم تُقرر"><span class="input-group-text">ساعة</span></div>
    </div>
  @endforeach
  <button class="btn btn-g">حفظ</button>
</form>
@endsection
