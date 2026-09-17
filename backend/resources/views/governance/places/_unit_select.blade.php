{{-- المرحلة ١٨-٣ (ج): قائمة «الوحدة» تُفلتر بالمكان المختار — $units (كل الوحدات الفعّالة)، $placeSelect (اسم قائمة المكان)، $selected --}}
@php($units = $units ?? \App\Modules\Governance\Models\PlaceUnit::where('is_active', true)->orderBy('type')->orderBy('sort')->orderBy('name')->get())
@php($selected = $selected ?? old('place_unit_id'))
@php($placeSelect = $placeSelect ?? 'place_id')
<select name="place_unit_id" id="placeUnitSelect" class="form-select {{ $class ?? '' }}" data-place-select="{{ $placeSelect }}">
  <option value="">— بلا تحديد —</option>
  @foreach($units as $u)
    <option value="{{ $u->id }}" data-place="{{ $u->place_id }}" @selected((string) $selected === (string) $u->id) hidden>{{ \App\Modules\Governance\Models\PlaceUnit::TYPE_LABELS[$u->type] ?? $u->type }}: {{ $u->name }}{{ $u->floor ? ' · الدور '.$u->floor : '' }}{{ $u->location ? ' · '.$u->location : '' }}</option>
  @endforeach
</select>
<script>
(function(){
  var sel=document.getElementById('placeUnitSelect'); if(!sel) return;
  var ps=document.querySelector('select[name="'+sel.dataset.placeSelect+'"]');
  function f(){ var p=ps?ps.value:''; var any=false;
    sel.querySelectorAll('option[data-place]').forEach(function(o){ var show=p && o.dataset.place===p; o.hidden=!show; if(show) any=true; if(!show && o.selected) sel.value=''; });
    var wrap=sel.closest('[data-unit-wrap]'); if(wrap) wrap.hidden=!any; }
  if(ps) ps.addEventListener('change', f); f();
})();
</script>
