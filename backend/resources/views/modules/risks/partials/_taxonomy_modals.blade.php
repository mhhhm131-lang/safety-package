{{-- ══════════════════════════════════════════════════════
     Inline taxonomy modals — shared by all risk create forms
     Requires: catSelect, subCatSelect, riskTypeSelect IDs
     ══════════════════════════════════════════════════════ --}}

{{-- Modal: Add Subcategory --}}
<div class="modal fade" id="modalAddSubCat" tabindex="-1" aria-labelledby="modalAddSubCatLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content" dir="rtl">
            <div class="modal-header py-2 px-3">
                <h6 class="modal-title fw-bold" id="modalAddSubCatLabel">
                    <i class="bi bi-diagram-2 me-1 text-primary"></i> إضافة فئة فرعية
                </h6>
                <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-3 py-3">
                <p class="text-muted small mb-3" id="subCatModalHint">ستُضاف تحت الفئة الرئيسية المختارة</p>
                <div class="mb-3">
                    <label class="form-label fw-medium small">الاسم بالعربية <span class="text-danger">*</span></label>
                    <input type="text" id="newSubCatName" class="form-control form-control-sm" placeholder="مثال: مخاطر الحرائق الكيميائية" autofocus>
                    <div class="invalid-feedback" id="newSubCatError"></div>
                </div>
                <div class="mb-2">
                    <label class="form-label fw-medium small">الاسم بالإنجليزية</label>
                    <input type="text" id="newSubCatNameEn" class="form-control form-control-sm" placeholder="Optional">
                </div>
            </div>
            <div class="modal-footer py-2 px-3">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnSaveSubCat">
                    <span id="btnSaveSubCatSpinner" class="spinner-border spinner-border-sm d-none me-1"></span>
                    حفظ
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Modal: Add Cause (Risk Type) --}}
<div class="modal fade" id="modalAddCause" tabindex="-1" aria-labelledby="modalAddCauseLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content" dir="rtl">
            <div class="modal-header py-2 px-3">
                <h6 class="modal-title fw-bold" id="modalAddCauseLabel">
                    <i class="bi bi-tag me-1 text-primary"></i> إضافة نوع مخاطرة
                </h6>
                <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-3 py-3">
                <p class="text-muted small mb-3" id="causeModalHint">ستُضاف تحت الفئة الفرعية المختارة</p>
                <div class="mb-3">
                    <label class="form-label fw-medium small">الاسم بالعربية <span class="text-danger">*</span></label>
                    <input type="text" id="newCauseName" class="form-control form-control-sm" placeholder="مثال: تسرب المواد الكيميائية" autofocus>
                    <div class="invalid-feedback" id="newCauseError"></div>
                </div>
                <div class="mb-2">
                    <label class="form-label fw-medium small">الاسم بالإنجليزية</label>
                    <input type="text" id="newCauseNameEn" class="form-control form-control-sm" placeholder="Optional">
                </div>
            </div>
            <div class="modal-footer py-2 px-3">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-primary btn-sm" id="btnSaveCause">
                    <span id="btnSaveCauseSpinner" class="spinner-border spinner-border-sm d-none me-1"></span>
                    حفظ
                </button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    // ── Subcategory modal ──
    document.getElementById('btnSaveSubCat').addEventListener('click', function () {
        const catId  = document.getElementById('catSelect').value;
        const name   = document.getElementById('newSubCatName').value.trim();
        const nameEn = document.getElementById('newSubCatNameEn').value.trim();
        const errEl  = document.getElementById('newSubCatError');
        const input  = document.getElementById('newSubCatName');
        const spinner = document.getElementById('btnSaveSubCatSpinner');

        input.classList.remove('is-invalid');
        if (!name) { input.classList.add('is-invalid'); errEl.textContent = 'الاسم مطلوب'; return; }
        if (!catId) { input.classList.add('is-invalid'); errEl.textContent = 'اختر الفئة الرئيسية أولاً'; return; }

        this.disabled = true;
        spinner.classList.remove('d-none');

        fetch('{{ route("risk.taxonomy.subcategory.store") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            body: JSON.stringify({ category_id: catId, name, name_en: nameEn || null }),
        })
        .then(r => r.ok ? r.json() : r.json().then(e => Promise.reject(e)))
        .then(data => {
            const sub = document.getElementById('subCatSelect');
            const opt = new Option(data.name, data.id, true, true);
            sub.appendChild(opt);
            sub.dispatchEvent(new Event('change'));
            bootstrap.Modal.getInstance(document.getElementById('modalAddSubCat')).hide();
            document.getElementById('newSubCatName').value = '';
            document.getElementById('newSubCatNameEn').value = '';
        })
        .catch(err => {
            input.classList.add('is-invalid');
            const msgs = err?.errors ? Object.values(err.errors).flat() : [];
            errEl.textContent = msgs[0] ?? 'حدث خطأ، حاول مرة أخرى';
        })
        .finally(() => { this.disabled = false; spinner.classList.add('d-none'); });
    });

    // ── Cause modal ──
    document.getElementById('btnSaveCause').addEventListener('click', function () {
        const subId  = document.getElementById('subCatSelect').value;
        const name   = document.getElementById('newCauseName').value.trim();
        const nameEn = document.getElementById('newCauseNameEn').value.trim();
        const errEl  = document.getElementById('newCauseError');
        const input  = document.getElementById('newCauseName');
        const spinner = document.getElementById('btnSaveCauseSpinner');

        input.classList.remove('is-invalid');
        if (!name) { input.classList.add('is-invalid'); errEl.textContent = 'الاسم مطلوب'; return; }
        if (!subId) { input.classList.add('is-invalid'); errEl.textContent = 'اختر الفئة الفرعية أولاً'; return; }

        this.disabled = true;
        spinner.classList.remove('d-none');

        fetch('{{ route("risk.taxonomy.cause.store") }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            body: JSON.stringify({ type_category_id: subId, name, name_en: nameEn || null }),
        })
        .then(r => r.ok ? r.json() : r.json().then(e => Promise.reject(e)))
        .then(data => {
            const sel = document.getElementById('riskTypeSelect');
            const opt = new Option(data.name, data.id, true, true);
            sel.appendChild(opt);
            sel.dispatchEvent(new Event('change'));
            bootstrap.Modal.getInstance(document.getElementById('modalAddCause')).hide();
            document.getElementById('newCauseName').value = '';
            document.getElementById('newCauseNameEn').value = '';
        })
        .catch(err => {
            input.classList.add('is-invalid');
            const msgs = err?.errors ? Object.values(err.errors).flat() : [];
            errEl.textContent = msgs[0] ?? 'حدث خطأ، حاول مرة أخرى';
        })
        .finally(() => { this.disabled = false; spinner.classList.add('d-none'); });
    });

    // Update modal hints with selected category/subcategory names
    document.getElementById('modalAddSubCat').addEventListener('show.bs.modal', function () {
        const catText = document.getElementById('catSelect').selectedOptions[0]?.text ?? '';
        document.getElementById('subCatModalHint').textContent = catText
            ? 'ستُضاف تحت: ' + catText
            : 'اختر الفئة الرئيسية أولاً';
    });

    document.getElementById('modalAddCause').addEventListener('show.bs.modal', function () {
        const subText = document.getElementById('subCatSelect').selectedOptions[0]?.text ?? '';
        document.getElementById('causeModalHint').textContent = subText
            ? 'ستُضاف تحت: ' + subText
            : 'اختر الفئة الفرعية أولاً';
    });
})();
</script>
@endpush
