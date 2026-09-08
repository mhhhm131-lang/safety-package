@extends('layouts.app')
@section('title', 'الأماكن')
@section('content')
<h1 class="h4 mb-1">الأماكن (٨+١)</h1>
<div class="small text-muted mb-3">الرموز ثابتة بالمرجعية SOURCE.md؛ الاسم فقط يُعدَّل.</div>
<div class="card"><table class="table m-0">
<thead><tr><th>الرمز</th><th>الاسم</th><th>الوحدات التي تشغله</th><th></th></tr></thead>
<tbody>
@foreach($places as $p)
<tr>
  <form method="post" action="{{ route('app.places.update', $p) }}">@csrf @method('PUT')
  <td dir="ltr" class="text-end fw-bold">{{ $p->code }}</td>
  <td><input class="form-control form-control-sm" name="name" value="{{ $p->name }}"></td>
  <td>{{ $p->units_count }}</td>
  <td><button class="btn btn-sm btn-outline-secondary">حفظ</button></td>
  </form>
</tr>
@endforeach
</tbody></table></div>
@endsection
