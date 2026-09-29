{{-- المرحلة ١٢ (قرار ٣٥): «أريد أن…» — أزرار بلغة الناس مشتقة من الصلاحيات. $intents: Collection<Intent> --}}
{{-- ٢٦-٨ (قرار ٦٧): محركان — «ما ينتظرك» ما يُراد مني، و«أريد أن» ما أبدؤه. المجموعات العشر نفسها (InboxService::GROUPS) بترتيبها وأيقوناتها؛ المجموعة الفارغة لا تظهر --}}
{{-- ٢٦-٧ (قرار ٦٦): الطوارئ كلها في صفحة المركز والزر الأحمر — لا تُعرض هنا؛ النوايا تبقى في السجل للشريط والاختبارات --}}
@php
  $shown = $intents->whereNotIn('key', ['sos', 'trigger', 'center', 'arrived', 'drill', 'teams', 'medical', 'systems']);
  $groups = \App\Core\Inbox\InboxService::GROUPS;
  $byGroup = $shown->groupBy('group');
  $rows = [];
  foreach ($groups as $g => $icon) { if (($items = $byGroup->get($g)) && $items->isNotEmpty()) $rows[$g] = [$icon, $items]; }
  // لا يُخفى شيء (٦١): مجموعة خارج العشر تُعرض آخراً
  foreach ($byGroup as $g => $items) { if (!isset($groups[$g]) && $items->isNotEmpty()) $rows[$g] = ['bi-folder2', $items]; }
@endphp
@if($rows)
<style>#intents .intent-row{padding:.35rem 0;border-bottom:1px solid var(--line)}#intents .intent-row:last-child{border-bottom:0}#intents .grp-nm{min-width:8.5rem;font-weight:700}
@media(max-width:700px){#intents .grp-nm{flex-basis:100%;min-width:0}}</style>
<div class="mt-4" id="intents">
  <h2 class="sec-h sec-h-lg mb-2"><i class="bi bi-hand-index-thumb"></i> أريد أن…</h2>
  <div class="card"><div class="card-body py-1" id="intentRows">
  @foreach($rows as $g => [$icon, $items])
    <div class="intent-row d-flex flex-wrap align-items-center gap-2" data-group="{{ $g }}" data-icon="{{ $icon }}">
      <span class="grp-nm small text-muted"><i class="bi {{ $icon }}"></i> {{ $g }}</span>
      @foreach($items as $i)
        <a class="btn btn-o btn-sm" href="{{ $i->url }}" data-intent="{{ $i->key }}" title="{{ $i->hint }}"><i class="bi {{ $i->icon }}"></i> {{ $i->label }}</a>
      @endforeach
    </div>
  @endforeach
  </div></div>
</div>
@endif
<!-- intents:end -->
