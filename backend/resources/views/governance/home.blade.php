@extends('layouts.app')
@section('title', 'الرئيسية')
@section('content')
<h1 class="h4 mb-3">مرحباً {{ auth()->user()->name }}</h1>
<div class="row g-3">
  @foreach($cards as $c)
  <div class="col-md-4">
    <a class="card h-100 text-decoration-none text-dark" href="{{ $c['url'] }}">
      <div class="card-body d-flex gap-3 align-items-start">
        <i class="bi {{ $c['icon'] }} fs-2 text-success"></i>
        <div><div class="fw-bold">{{ $c['title'] }}</div><div class="small text-muted">{{ $c['desc'] }}</div></div>
      </div>
    </a>
  </div>
  @endforeach
</div>
@endsection
