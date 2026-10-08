{{-- مربع مكان في الصفحة الأولى (٢٥-١؛ ٢٨-٣: المفتاح رمز المكان الكامل، ومركز السلامة بصنفه) — $hz الرمز، $t بلاطته، $p المكان، $n عدد المربعات --}}
<a class="pl-tile {{ $t['cls'] }}" href="{{ $p->category === 'HZ-00' ? route('emergency.dashboard') : route('app.places.units.file', $p) }}" data-place="{{ $hz }}" data-cls="{{ $t['cls'] }}" data-open="{{ $t['open'] }}" data-od="{{ $t['od'] }}" data-a="{{ $t['a'] }}"@if($n > 1 && $p->category !== 'HZ-00') data-filter="1"@endif>
  <span class="small text-muted" dir="ltr">{{ $hz }}</span>
  <span class="nm">{{ $p->name }}</span>
  <span class="small st">
    @if(!$t['has'])<span class="text-muted">لم تُفتح جولة بعد</span>
    @elseif($t['open'])<b>{{ $t['open'] }}</b> مفتوح@if($t['od']) · <b class="text-danger">{{ $t['od'] }} متجاوز</b>@endif
    @else<b class="text-success">لا شيء مفتوح</b>@endif
  </span>
</a>
