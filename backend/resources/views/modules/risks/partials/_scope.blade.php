{{--
    خانة النطاق في نماذج السجل الفعلي الثلاثة (التفعيل، خطر جديد، التعديل) — مصدر واحد.
    قرار ٧٠: أدوار السجل كله ($scopeUnits = null) تختار «عام» أو أي وحدة؛
    والمدير ومنسق السلامة وحدتهما وما تحتها فقط — وحدة صاحب الحساب مختارة سلفاً، ولا «عام».
    المتغيرات: $col، $scopeLabel، $scopeDefault، $unitDefault، $scopeRequired (اختياري)، $editing (اختياري).
--}}
@if($scopeUnits === null)
<div class="{{ $col }}">
    <label class="form-label" style="color:var(--text-main);">{{ $scopeLabel }} @if($scopeRequired ?? false) <span class="text-danger">*</span> @endif</label>
    <select name="scope_type" id="scopeType" class="form-select" @if($scopeRequired ?? false) required @endif
            onchange="document.getElementById('orgUnitDiv').style.display=this.value==='org_unit'?'block':'none'">
        <option value="general" @selected(old('scope_type', $scopeDefault ?? 'general')==='general')>عام — المؤسسة بالكامل</option>
        <option value="org_unit" @selected(old('scope_type', $scopeDefault)==='org_unit')>وحدة تنظيمية محددة</option>
    </select>
</div>
<div class="{{ $col }}" id="orgUnitDiv" style="display:{{ old('scope_type', $scopeDefault)==='org_unit'?'block':'none' }};">
    <label class="form-label" style="color:var(--text-main);">الوحدة التنظيمية</label>
    <select name="organization_unit_id" class="form-select">
        <option value="">اختر...</option>
        @foreach($orgUnits as $unit)
            <option value="{{ $unit->id }}" @selected(old('organization_unit_id', $unitDefault)==$unit->id)>{{ $unit->name }}</option>
        @endforeach
    </select>
</div>
@elseif(($editing ?? false) && !$scopeUnits->contains('id', $unitDefault))
{{-- خطر بنطاق المعهد كله يفتحه صاحب وحدة: نطاقه يبقى كما هو، لا يُنقل إلى وحدته بالحفظ --}}
<div class="{{ $col }}">
    <label class="form-label" style="color:var(--text-main);">{{ $scopeLabel }}</label>
    <input type="hidden" name="scope_type" value="{{ $scopeDefault ?? 'general' }}">
    <input type="hidden" name="organization_unit_id" value="{{ $unitDefault }}">
    <input type="text" class="form-control" value="عام — المؤسسة بالكامل" disabled>
</div>
@else
<div class="{{ $col }}" id="orgUnitDiv">
    <input type="hidden" name="scope_type" value="org_unit">
    <label class="form-label" style="color:var(--text-main);">الوحدة التنظيمية <span class="text-danger">*</span></label>
    <select name="organization_unit_id" class="form-select" required>
        @foreach($scopeUnits as $unit)
            <option value="{{ $unit->id }}" @selected(old('organization_unit_id', $unitDefault ?? $myUnitId)==$unit->id)>{{ $unit->name }}</option>
        @endforeach
    </select>
    @if($scopeUnits->isEmpty())
        <small class="text-danger">حسابك غير مربوط بوحدة تنظيمية — اطلب من مسؤول السلامة ربطه بوحدتك.</small>
    @else
        <small style="color:var(--text-muted);">وحدتك وما تحتها — يعتمده مديرها</small>
    @endif
</div>
@endif
