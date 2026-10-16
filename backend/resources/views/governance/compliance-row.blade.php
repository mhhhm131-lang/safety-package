{{-- صف قابل للتقييم في المقارنة (مادة بلا بنود أو بند): الحالة وكلام المبنى، و«تعديل» لمن يحرّر كلام المبنى. يستقبل $r و$isItem و$canEdit و$openRow و$AR --}}
@php($M = \App\Modules\Emergency\Models\BuildingComplianceItem::class)
@php($changed = $r->changedFields())
<div class="cp-row {{ $isItem ? 'item' : '' }}" id="r{{ $r->id }}" data-row="{{ $r->key }}" data-status="{{ $r->status }}" data-edited="{{ $r->isEdited() ? 1 : 0 }}">
  <div class="d-flex flex-wrap align-items-start gap-2">
    <span class="badge {{ $M::STATUS_CLASS[$r->status] ?? '' }} {{ in_array('status', $changed, true) ? 'cp-ch' : '' }}" data-f="status">{{ $r->statusLabel() }}</span>
    <div class="flex-grow-1">
      @if($isItem)<div><b>{{ $AR($r->item_no) }} — {{ $r->title }}</b>@if($r->prepared)<div class="cp-prep"><b>ما أعدّه مسؤول السلامة:</b> {{ $r->prepared }}</div>@endif</div>@endif
      <div class="cp-words mt-1">
        <div class="{{ in_array('current_state', $changed, true) ? 'cp-ch' : '' }}" data-f="current_state"><b>الوضع الحالي:</b> {{ $r->current_state ?? '—' }}@if(in_array('current_state', $changed, true))<div class="cp-src">الأصل في الوثيقة: {{ $r->source['current_state'] ?? '—' }}</div>@endif</div>
        <div class="{{ in_array('action', $changed, true) ? 'cp-ch' : '' }}" data-f="action"><b>الإجراء:</b> {{ $r->action ?? '—' }}</div>
        <div class="{{ in_array('holder_name', $changed, true) ? 'cp-ch' : '' }}" data-f="holder_name"><b>المكلّف:</b> {{ $r->holder_name ?? '—' }}</div>
        <div class="{{ in_array('due_date', $changed, true) ? 'cp-ch' : '' }}" data-f="due_date"><b>الموعد:</b> {{ $r->due_date?->toDateString() ?? '—' }}</div>
        @if($r->notes)<div class="{{ in_array('notes', $changed, true) ? 'cp-ch' : '' }}" data-f="notes"><b>ملاحظات:</b> {{ $r->notes }}</div>@endif
      </div>
      @if($r->isEdited())<div class="cp-src">عدّله {{ $r->edited_by_name }} · {{ $r->edited_at->format('Y-m-d') }}@if($r->changedLabels()) · {{ implode('، ', $r->changedLabels()) }}@endif</div>@endif
    </div>
    @if($canEdit)<button type="button" class="btn btn-o btn-sm cp-edit" data-for="e{{ $r->id }}" aria-controls="e{{ $r->id }}" aria-expanded="false"><i class="bi bi-pencil"></i> تعديل</button>@endif
  </div>
  @if($canEdit)
  <div class="cp-form" id="e{{ $r->id }}" @if($openRow !== $r->id) hidden @endif>
    <form method="post" action="{{ route('app.compliance.update', $r) }}" data-row-form="{{ $r->id }}">@csrf
      <input type="hidden" name="_row" value="{{ $r->id }}">
      <div class="d-flex flex-wrap gap-2 mb-2">
        @foreach($M::STATUSES as $k => $label)
          <input type="radio" class="btn-check" name="status" value="{{ $k }}" id="st{{ $r->id }}{{ $k }}" @checked(old('status', $r->status) === $k)>
          <label class="btn btn-sm {{ $k === 'met' ? 'btn-outline-success' : ($k === 'not_met' ? 'btn-outline-danger' : 'btn-outline-secondary') }}" for="st{{ $r->id }}{{ $k }}">{{ $label }}</label>
        @endforeach
      </div>
      <div class="row g-2">
        <div class="col-12 col-md-6"><label class="form-label small mb-0">الوضع الحالي في هذا المبنى</label><textarea name="current_state" class="form-control form-control-sm" rows="2" maxlength="2000">{{ old('current_state', $r->current_state) }}</textarea>
          @if(in_array('current_state', $changed, true))<div class="cp-src">الأصل في الوثيقة: {{ $r->source['current_state'] ?? '—' }}</div>@endif</div>
        <div class="col-12 col-md-6"><label class="form-label small mb-0">الإجراء</label><textarea name="action" class="form-control form-control-sm" rows="2" maxlength="2000" placeholder="ما يُفعل لاستيفاء المادة">{{ old('action', $r->action) }}</textarea></div>
        <div class="col-12 col-md-4"><label class="form-label small mb-0">المكلّف</label><input name="holder_name" class="form-control form-control-sm" maxlength="120" value="{{ old('holder_name', $r->holder_name) }}" placeholder="اسم الشخص"></div>
        <div class="col-12 col-md-4"><label class="form-label small mb-0">الموعد</label><input type="date" name="due_date" class="form-control form-control-sm" value="{{ old('due_date', $r->due_date?->toDateString()) }}"></div>
        <div class="col-12 col-md-4"><label class="form-label small mb-0">ملاحظات</label><input name="notes" class="form-control form-control-sm" maxlength="2000" value="{{ old('notes', $r->notes) }}"></div>
      </div>
      @error('due_date')<div class="text-danger small">{{ $message }}</div>@enderror
      <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
        <button class="btn btn-g btn-sm">حفظ</button>
        <button type="button" class="btn btn-o btn-sm cp-cancel" data-for="e{{ $r->id }}">إلغاء</button>
      </div>
    </form>
    @if($r->isEdited())<form method="post" action="{{ route('app.compliance.restore', $r) }}" class="mt-1">@csrf<button class="btn btn-link btn-sm p-0" data-restore="{{ $r->id }}">استعادة الأصل من الوثيقة</button></form>@endif
  </div>
  @endif
</div>
