{{-- المرحلة ١٢ (قرار ٣٥): «أريد أن…» — أزرار بلغة الناس مشتقة من الصلاحيات. $intents: Collection<Intent> --}}
{{-- ٢٥-٣ (قرار ٦٥): «أستغيث الآن» شريط ثابت في كل الشاشات — لا تُعرض هنا مرة ثانية --}}
@php $intents = $intents->whereNotIn('key', ['sos', 'trigger', 'center', 'arrived', 'drill', 'teams', 'medical', 'systems']); @endphp {{-- ٢٦-٧ (قرار ٦٦): الطوارئ كلها في صفحة المركز والزر الأحمر — لا تُعرض هنا؛ النوايا تبقى في السجل للشريط والاختبارات --}}
@if($intents->isNotEmpty())
<div class="mt-4" id="intents">
  <h2 class="sec-h sec-h-lg mb-2"><i class="bi bi-hand-index-thumb"></i> أريد أن…</h2>
  @php($primary = $intents->where('primary', true))
  @if($primary->isNotEmpty())
    <div class="d-flex flex-wrap gap-2 mb-3">
      @foreach($primary as $i)
        <a class="btn btn-g btn-lg" href="{{ $i->url }}" data-intent="{{ $i->key }}" title="{{ $i->hint }}"><i class="bi {{ $i->icon }}"></i> {{ $i->label }}</a>
      @endforeach
    </div>
  @endif
  <div class="row g-2">
    @foreach($intents->where('primary', false)->groupBy('group') as $group => $items)
      <div class="col-md-6 col-xl-4">
        <div class="card h-100"><div class="card-header py-1 small text-muted">{{ $group }}</div>
          <div class="list-group list-group-flush">
            @foreach($items as $i)
              <a class="list-group-item list-group-item-action py-2" href="{{ $i->url }}" data-intent="{{ $i->key }}"><i class="bi {{ $i->icon }} me-1"></i> {{ $i->label }}@if($i->hint)<div class="small text-muted">{{ $i->hint }}</div>@endif</a>
            @endforeach
          </div>
        </div>
      </div>
    @endforeach
  </div>
</div>
@endif
