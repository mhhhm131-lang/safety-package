@extends('layouts.app')
@section('title', 'الإشعارات')
@section('content')
<div class="d-flex align-items-center mb-3">
  <h1 class="h4 m-0">الإشعارات</h1>
  <button class="btn btn-sm btn-outline-secondary ms-auto" onclick="fetch('{{ route('app.notifications.read-all') }}',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,'Accept':'application/json'}}).then(()=>location.reload())">تعليم الكل مقروءاً</button>
</div>
<div class="list-group">
@forelse($notifications as $n)
  <a class="list-group-item list-group-item-action {{ $n->is_read ? '' : 'fw-bold' }}" href="{{ $n->url ?: '#' }}"
     onclick="fetch('{{ route('app.notifications.read', $n) }}',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,'Accept':'application/json'},keepalive:true})">
    <div class="d-flex"><span>{{ $n->title }}</span><span class="ms-auto small text-muted">{{ $n->created_at?->diffForHumans() }}</span></div>
    @if($n->message)<div class="small text-muted fw-normal">{{ $n->message }}</div>@endif
  </a>
@empty
  <div class="list-group-item text-muted">لا إشعارات</div>
@endforelse
</div>
<div class="mt-2">{{ $notifications->links() }}</div>
@endsection
