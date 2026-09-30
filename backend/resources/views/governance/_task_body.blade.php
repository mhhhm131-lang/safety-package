{{-- جسم مهمة في «ما ينتظرك»: النص وسطر المهلة وأزرار الفعل — للبطاقة المفردة وللبند داخل الدفعة (٢٦-١١). $t: Task، $label: النص المعروض --}}
<div class="flex-grow-1" style="min-width:220px">
  <div class="fw-bold">{{ $label }}</div>
  <div class="small text-muted mt-1">
    @if($t->isOverdue)<span class="badge st-late">متأخر</span>
    @elseif($t->dueAt)<span class="badge st-wait">المهلة {{ $t->dueAt->format('m/d H:i') }}</span>@endif
    @if($t->createdAt) · {{ $t->createdAt->diffForHumans() }}@endif
  </div>
</div>
<div class="task-actions">
  @if($t->primaryMethod() === 'POST')
    <form method="post" action="{{ $t->primary['url'] }}" class="m-0">@csrf<button class="btn btn-g">{{ $t->primary['label'] }}</button></form>
  @else
    <a class="btn btn-g" href="{{ route('app.inbox.open', ['url' => $t->primary['url']]) }}" data-target="{{ $t->primary['url'] }}">{{ $t->primary['label'] }}</a>
  @endif
  @if($t->secondary)
    @if($t->secondaryMethod() === 'POST')
      <form method="post" action="{{ $t->secondary['url'] }}" class="m-0">@csrf<button class="btn btn-o">{{ $t->secondary['label'] }}</button></form>
    @else
      <a class="btn btn-o" href="{{ $t->secondary['url'] }}">{{ $t->secondary['label'] }}</a>
    @endif
  @endif
</div>
