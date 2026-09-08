@extends('layouts.app')
@section('title', 'الأماكن')
@section('content')
<h1 class="h4 mb-1">الأماكن (٨+١)</h1>
<div class="small text-muted mb-3">الرموز ثابتة بالمرجعية SOURCE.md؛ الاسم فقط يُعدَّل. لكل مكان خطتاه ونموذجه وملفه في اللوحة.</div>
<div class="card"><div class="table-responsive"><table class="table m-0">
<thead><tr><th>الرمز</th><th>الاسم</th><th>الوثائق والنماذج</th><th>الوحدات التي تشغله</th><th></th></tr></thead>
<tbody>
@foreach($places as $p)
<tr>
  <form method="post" action="{{ route('app.places.update', $p) }}">@csrf @method('PUT')
  <td dir="ltr" class="text-end fw-bold">{{ $p->code }}</td>
  <td style="min-width:220px"><input class="form-control form-control-sm" name="name" value="{{ $p->name }}"></td>
  <td class="small">
    @foreach($p->links() as [$label, $url])
      <a class="badge text-bg-light border text-decoration-none me-1 mb-1" href="{{ $url }}">{{ $label }}</a>
    @endforeach
  </td>
  <td>{{ $p->units_count }}</td>
  <td><button class="btn btn-sm btn-outline-secondary">حفظ</button></td>
  </form>
</tr>
@endforeach
</tbody></table></div></div>
@endsection
