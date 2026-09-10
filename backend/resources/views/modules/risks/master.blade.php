@extends('layouts.app')

@section('page_title', 'كتاب المخاطر الرئيسي')

@section('content')
<div class="container-fluid" id="risk-book-app">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-1" style="color: var(--text-main);">
                <i class="bi bi-book-half me-2" style="color: var(--accent);"></i>
                كتاب المخاطر الرئيسي
            </h4>
            <p class="mb-0" style="color: var(--text-muted); font-size: 0.85rem;">
                تصفّح هرمي: فئة رئيسية → اختر الفرعيات → اختر خطر → تفاصيل
            </p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" onclick="riskBook.toggleView()">
                <i class="bi bi-table"></i> <span id="view-toggle-label">عرض الجدول</span>
            </button>
            <a href="{{ route('risk.master.create') }}" class="btn btn-accent">
                <i class="bi bi-plus-lg me-1"></i> إضافة خطر
            </a>
        </div>
    </div>

    {{-- Tree Navigator --}}
    <div id="tree-view">
        <div class="row g-3">

            {{-- Column 1: الفئة الرئيسية --}}
            <div class="col-lg-4 col-md-6">
                <div class="card h-100 tree-panel">
                    <div class="card-header py-2">
                        <strong><i class="bi bi-1-circle me-1 text-accent"></i> الفئة الرئيسية</strong>
                    </div>
                    <div class="card-body p-2" style="max-height:500px; overflow-y:auto;">
                        <div id="categories-list">
                            <div class="text-muted small text-center py-4">
                                <div class="spinner-border spinner-border-sm"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Column 2: الفئة الفرعية (multi-checkbox) --}}
            <div class="col-lg-4 col-md-6">
                <div class="card h-100 tree-panel">
                    <div class="card-header py-2 d-flex align-items-center">
                        <strong class="flex-grow-1"><i class="bi bi-2-circle me-1 text-accent"></i> الفئة الفرعية</strong>
                        <span class="badge bg-secondary ms-2" id="subcat-count">0</span>
                        <button class="btn btn-sm btn-link text-muted ms-1 p-0" id="subcat-select-all"
                                style="font-size:.72rem;" onclick="toggleSelectAllSubCats()" title="تحديد الكل">
                            <i class="bi bi-check-all"></i>
                        </button>
                    </div>
                    <div class="card-body p-2" style="max-height:500px; overflow-y:auto;">
                        <div id="subcategories-list">
                            <div class="text-muted small text-center py-4">اختر فئة رئيسية أولاً</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Column 3: المخاطر --}}
            <div class="col-lg-4 col-md-12">
                <div class="card h-100 tree-panel">
                    <div class="card-header py-2 d-flex align-items-center gap-2">
                        <strong class="flex-grow-1"><i class="bi bi-3-circle me-1 text-accent"></i> المخاطر</strong>
                        <span class="badge bg-danger" id="risk-count">0</span>
                    </div>
                    <div id="copy-bar" style="display:none;background:#0d6efd18;border-bottom:1px solid #0d6efd40;padding:6px 8px;" class="d-flex align-items-center gap-2">
                        <span class="small fw-semibold" style="color:#5ba4f5;" id="copy-bar-count">0 محدد</span>
                        <button onclick="copySelectedToReference()" class="btn btn-sm ms-auto"
                                style="background:#0d6efd;color:#fff;font-size:.75rem;padding:3px 10px;border-radius:6px;">
                            <i class="bi bi-arrow-down-circle me-1"></i>نسخ للمرجعي
                        </button>
                        <button onclick="clearSelection()" class="btn btn-sm btn-outline-secondary" style="font-size:.75rem;padding:3px 8px;border-radius:6px;">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                    <div class="card-body p-2" style="max-height:500px; overflow-y:auto;">
                        <div id="risks-tree">
                            <div class="text-muted small text-center py-4">اختر فئة فرعية لعرض المخاطر</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Detail Panel (below the 3 columns) --}}
        <div id="risk-detail-panel" style="display:none; margin-top:1rem;">
            <div class="card tree-panel">
                <div class="card-header py-2 d-flex align-items-start gap-3 flex-wrap"
                     id="detail-panel-header" style="min-height:54px;">
                </div>
                <div class="card-body p-0">
                    <div id="detail-panel-body"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- Legacy table view (hidden by default) --}}
    <div id="table-view" style="display:none;">
        <div class="card mb-3" style="background:var(--bg-card); border:1px solid var(--border-color);">
            <div class="card-body">
                <form method="GET" action="{{ route('risk.master.index') }}" class="row g-2">
                    <div class="col-md-4">
                        <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="ابحث في العنوان أو الكود...">
                    </div>
                    <div class="col-md-3">
                        <select name="category_id" class="form-select">
                            <option value="">جميع الفئات</option>
                            @foreach(($categories ?? []) as $cat)
                                <option value="{{ $cat->id }}" @selected(request('category_id')==$cat->id)>{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="severity_filter" class="form-select">
                            <option value="">جميع الدرجات</option>
                            <option value="critical" @selected(request('severity_filter')=='critical')>حرجة (15+)</option>
                            <option value="high" @selected(request('severity_filter')=='high')>عالية (7-14)</option>
                            <option value="low" @selected(request('severity_filter')=='low')>منخفضة (1-6)</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-accent w-100"><i class="bi bi-search"></i></button>
                    </div>
                </form>
            </div>
        </div>

        @include('modules.risks.partials._risk_table', [
            'risks' => $risks ?? collect(),
            'registry_type' => 'master',
            'can_edit' => true,
        ])
    </div>
</div>

<style>
    .tree-panel { background: var(--bg-card); border: 1px solid var(--border-color); }
    .tree-panel .card-header {
        background: var(--bg-dark);
        border-bottom: 1px solid var(--border-color);
        color: var(--text-main);
    }
    .text-accent { color: var(--accent); }

    /* Category list (column 1) */
    .cat-nav-item {
        display: block;
        padding: 0.45rem 0.75rem;
        margin: 0.15rem 0;
        border-radius: 0.35rem;
        cursor: pointer;
        font-size: 0.84rem;
        color: var(--text-main);
        border: 1px solid transparent;
        transition: background 0.15s;
    }
    .cat-nav-item:hover {
        background: rgba(8, 145, 178, 0.08);
        border-color: rgba(8, 145, 178, 0.25);
    }
    .cat-nav-item.selected {
        background: rgba(8, 145, 178, 0.18);
        border-color: var(--accent);
        color: var(--accent);
        font-weight: 600;
    }

    /* Sub-category checkbox items (column 2) */
    .subcat-check-item {
        display: block;
        padding: 0.35rem 0.6rem;
        margin: 0.12rem 0;
        border-radius: 0.3rem;
        border: 1px solid transparent;
        transition: background 0.15s;
        font-size: 0.82rem;
        color: var(--text-main);
    }
    .subcat-check-item:hover {
        background: rgba(8, 145, 178, 0.06);
        border-color: rgba(8, 145, 178, 0.2);
    }
    .subcat-check-item label {
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 0.45rem;
        margin: 0;
        width: 100%;
    }
    .subcat-check-item input[type="checkbox"] {
        flex-shrink: 0;
        cursor: pointer;
    }
    .subcat-check-item.checked {
        background: rgba(8, 145, 178, 0.12);
        border-color: rgba(8, 145, 178, 0.4);
    }

    /* Risk items (column 3) */
    .risk-item {
        display: flex;
        align-items: center;
        gap: 4px;
        padding: 0.4rem 0.6rem;
        margin: 0.15rem 0;
        border-radius: 0.3rem;
        cursor: pointer;
        font-size: 0.82rem;
        color: var(--text-main);
        transition: background 0.15s;
        border: 1px solid transparent;
    }
    .risk-item:hover {
        background: rgba(8, 145, 178, 0.08);
        border-color: rgba(8, 145, 178, 0.3);
    }
    .risk-item.selected {
        background: rgba(239, 68, 68, 0.12);
        border-color: #ef4444;
    }
    .risk-item .score {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        font-size: 0.7rem;
        font-weight: 700;
        color: #fff;
        flex-shrink: 0;
    }
    .score-high { background: #ef4444; }
    .score-med  { background: #f59e0b; }
    .score-low  { background: #10b981; }

    /* Contextual sub-category marker */
    .contextual-mark { font-size: 0.72rem; }

    /* Detail panel header */
    .detail-risk-title {
        font-size: 1rem;
        font-weight: 700;
        color: var(--text-main);
        line-height: 1.3;
    }
    .detail-risk-meta {
        font-size: 0.75rem;
        color: var(--text-muted);
        margin-top: 2px;
    }
    .score-big {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 48px;
        height: 48px;
        border-radius: 50%;
        color: #fff;
        font-weight: 700;
        font-size: 1.1rem;
        flex-shrink: 0;
    }

    /* Benefit note */
    .benefit-note {
        font-size: .78rem;
        color: var(--text-muted);
        background: rgba(16,185,129,.08);
        border-inline-start: 3px solid #10b981;
        border-radius: .25rem;
        padding: .25rem .6rem;
        margin-top: .4rem;
        display: inline-block;
    }

    /* Detail phase table */
    .detail-phase-table {
        font-size: .8rem;
        color: var(--text-main);
        width: 100%;
        margin: 0;
    }
    .detail-phase-table thead th {
        background: var(--bg-dark);
        color: var(--text-muted);
        font-size: .7rem;
        font-weight: 600;
        white-space: nowrap;
        padding: .5rem .7rem;
        border-color: var(--border-color);
        border-bottom: 2px solid var(--border-color);
    }
    .detail-phase-table tbody td {
        padding: .55rem .7rem;
        border-color: var(--border-color);
        vertical-align: top;
        white-space: normal;
        line-height: 1.55;
    }
    .detail-phase-badge {
        display: inline-block;
        padding: .2rem .6rem;
        border-radius: 20px;
        font-size: .7rem;
        font-weight: 700;
        white-space: nowrap;
    }

    /* Pop-trigger (matches _risk_table.blade.php) */
    .pop-trigger {
        display: inline-flex; align-items: center; gap: 3px;
        cursor: pointer;
        padding: 2px 7px;
        border-radius: 20px;
        border: 1px solid var(--border-color);
        background: var(--bg-dark);
        font-size: .72rem;
        color: var(--text-main);
        transition: background .15s;
        white-space: nowrap;
    }
    .pop-trigger:hover { background: rgba(8,145,178,.12); border-color: var(--accent); color: var(--accent); }
    .badge-count { font-weight: 600; }
    .pop-preview {
        max-width: 110px;
        overflow: hidden;
        text-overflow: ellipsis;
        display: inline-block;
        vertical-align: middle;
    }
    .pop-arrow { font-size: .55rem; opacity: .6; }
    .popover { max-width: 340px; font-size: .82rem; direction: rtl; text-align: right; }
    .popover-body ul { margin: 0; padding-right: 1.2rem; padding-left: 0; }

    /* Impact tags */
    .impact-tag {
        display: inline-block;
        padding: 1px 5px;
        border-radius: 999px;
        font-size: .62rem;
        font-weight: 600;
        color: #fff;
    }
    .impact-low      { background: #10b981; }
    .impact-medium   { background: #06b6d4; }
    .impact-high     { background: #f59e0b; }
    .impact-critical { background: #ef4444; }

    /* Trade modal */
    .trade-modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.65);
        z-index: 2000;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }
    .trade-modal-overlay.show { display: flex; }
    .trade-modal-dialog {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: 0.75rem;
        max-width: 720px;
        width: 100%;
        max-height: 90vh;
        overflow-y: auto;
        padding: 1.5rem 1.75rem;
        position: relative;
        color: var(--text-main);
    }
    .trade-modal-close {
        position: absolute;
        top: 0.5rem;
        inset-inline-end: 0.75rem;
        background: transparent;
        border: none;
        color: var(--text-muted);
        font-size: 1.75rem;
        line-height: 1;
        cursor: pointer;
        padding: 0.25rem 0.6rem;
    }
    .trade-modal-close:hover { color: var(--accent); }
    .trade-modal-header { border-bottom: 1px solid var(--border-color); padding-bottom: .75rem; margin-bottom: 1rem; }
    .trade-title-row { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
    .trade-title-row .badge { font-size: .7rem; }
    .bg-accent { background: var(--accent) !important; color: #fff; }
    .trade-section { margin-top: 1rem; }
    .trade-section h6 { font-size: .85rem; color: var(--accent); margin-bottom: .5rem; padding-bottom: .2rem; border-bottom: 1px dashed var(--border-color); }
    .trade-description { font-size: .85rem; line-height: 1.7; color: var(--text-main); margin: 0; }
    .trade-task-list, .trade-comp-list { margin: 0; padding-inline-start: 1.4rem; font-size: .82rem; color: var(--text-main); }
    .trade-task-list li, .trade-comp-list li { margin-bottom: .35rem; line-height: 1.55; }
</style>

<script>
(function() {
    const routes = {
        riskDetail: (id) => `{{ url('app/risk/book/tree/risk') }}/${id}`,
        editRisk:   (id) => `{{ url('app/risk/master') }}/${id}/edit`,
    };

    const state = {
        categoryId: null,
        selectedRiskId: null,
    };

    const selectedSubCatIds = new Set();
    const selectedRiskIds   = new Set();   // unified: copy + detail
    const riskDetailCache   = new Map();   // id → fetched detail object

    // Active Bootstrap Popovers in the detail panel (so we can destroy on re-render)
    let activePops = [];

    async function fetchJson(url) {
        const r = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    }

    function el(id) { return document.getElementById(id); }

    // -----------------------------------------------------------------
    // Popover helpers
    // -----------------------------------------------------------------

    function escAttr(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function truncWords(s, n) {
        if (!s) return '';
        const words = s.trim().split(/\s+/);
        return words.length <= n ? s : words.slice(0, n).join(' ') + '…';
    }

    function popTrigger(title, contentHtml, preview) {
        return `<span class="pop-trigger"
            data-bs-toggle="popover"
            data-bs-placement="top"
            data-bs-trigger="click"
            data-bs-title="${escAttr(title)}"
            data-bs-html="true"
            data-bs-content="${escAttr(contentHtml)}">
            <span class="pop-preview">${preview}</span>
            <i class="bi bi-chevron-down pop-arrow"></i>
        </span>`;
    }

    function initPopovers(container) {
        // Destroy old popovers first
        activePops.forEach(p => { try { p.dispose(); } catch(_){} });
        activePops = [];

        const els = container.querySelectorAll('[data-bs-toggle="popover"]');
        els.forEach(popEl => {
            const p = new bootstrap.Popover(popEl, { container: 'body' });
            activePops.push(p);
            popEl.addEventListener('show.bs.popover', () => {
                activePops.forEach(other => { if (other !== p) other.hide(); });
            });
        });

        container.addEventListener('click', function(e) {
            if (!e.target.closest('[data-bs-toggle="popover"]') && !e.target.closest('.popover')) {
                activePops.forEach(p => p.hide());
            }
        }, { capture: false });
    }

    // -----------------------------------------------------------------
    // Column 1 — الفئات الرئيسية
    // -----------------------------------------------------------------

    function treeError(msg, retryFn) {
        return `<div class="alert alert-warning m-2 p-2 small d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>${msg}</span>
            ${retryFn ? `<button class="btn btn-sm btn-outline-warning ms-auto" onclick="${retryFn}()">إعادة</button>` : ''}
        </div>`;
    }

    async function loadCategories() {
        const container = el('categories-list');
        try {
            const cats = await fetchJson("{{ url('app/risk/book/tree/categories') }}");
            container.innerHTML = '';
            if (!cats.length) {
                container.innerHTML = '<div class="text-muted small text-center py-4">لا توجد فئات</div>';
                return;
            }
            cats.forEach(cat => {
                const item = document.createElement('div');
                item.className = 'cat-nav-item';
                item.dataset.id = cat.id;
                item.textContent = cat.name;
                item.addEventListener('click', () => selectCategory(cat.id, item));
                container.appendChild(item);
            });
        } catch(e) {
            container.innerHTML = treeError('فشل تحميل الفئات', 'loadCategories');
        }
    }

    async function selectCategory(id, node) {
        state.categoryId = id;
        selectedSubCatIds.clear();
        document.querySelectorAll('.cat-nav-item').forEach(n => n.classList.remove('selected'));
        node.classList.add('selected');

        el('subcategories-list').innerHTML = '<div class="text-center py-2"><div class="spinner-border spinner-border-sm"></div></div>';
        el('risks-tree').innerHTML = '<div class="text-muted small text-center py-4">اختر فئة فرعية</div>';
        el('risk-count').textContent = '0';
        hideDetailPanel();

        try {
            const subs = await fetchJson(`{{ url('app/risk/book/tree/sub-categories') }}/${id}`);
            renderSubCategories(subs);
        } catch(e) {
            el('subcategories-list').innerHTML = treeError('فشل تحميل الفئات الفرعية');
        }
    }

    // -----------------------------------------------------------------
    // Column 2 — الفئات الفرعية (multi-checkbox)
    // -----------------------------------------------------------------

    function renderSubCategories(subs) {
        const container = el('subcategories-list');
        container.innerHTML = '';
        el('subcat-count').textContent = subs.length;

        if (!subs.length) {
            container.innerHTML = '<div class="text-muted small text-center py-4">لا توجد فئات فرعية</div>';
            return;
        }

        subs.forEach(sub => {
            const uid = 'sc-' + sub.id;
            const item = document.createElement('div');
            item.className = 'subcat-check-item';
            item.dataset.subId = sub.id;

            const universalMark = !sub.is_universal
                ? '<span class="contextual-mark ms-auto" title="فرعي ظرفي">🎯</span>'
                : '';

            item.innerHTML = `
                <label for="${uid}">
                    <input type="checkbox" class="subcat-cb form-check-input" id="${uid}" value="${sub.id}">
                    <span class="flex-grow-1">${sub.name}</span>
                    ${universalMark}
                </label>`;

            item.querySelector('input').addEventListener('change', (e) => {
                const subId = parseInt(e.target.value);
                if (e.target.checked) {
                    selectedSubCatIds.add(subId);
                    item.classList.add('checked');
                } else {
                    selectedSubCatIds.delete(subId);
                    item.classList.remove('checked');
                }
                hideDetailPanel();
                loadRisksForSelectedSubs();
            });

            container.appendChild(item);
        });
    }

    window.toggleSelectAllSubCats = function() {
        const checkboxes = document.querySelectorAll('#subcategories-list .subcat-cb');
        if (!checkboxes.length) return;
        const anyUnchecked = [...checkboxes].some(cb => !cb.checked);
        checkboxes.forEach(cb => {
            const subId = parseInt(cb.value);
            cb.checked = anyUnchecked;
            const item = cb.closest('.subcat-check-item');
            if (anyUnchecked) {
                selectedSubCatIds.add(subId);
                item.classList.add('checked');
            } else {
                selectedSubCatIds.delete(subId);
                item.classList.remove('checked');
            }
        });
        hideDetailPanel();
        loadRisksForSelectedSubs();
    };

    // -----------------------------------------------------------------
    // Column 3 — المخاطر (loaded from all selected sub-cats in parallel)
    // -----------------------------------------------------------------

    async function loadRisksForSelectedSubs() {
        // Clear previous risk selections when sub-cat selection changes
        selectedRiskIds.clear();
        riskDetailCache.clear();
        document.querySelectorAll('.risk-cb').forEach(cb => cb.checked = false);
        document.querySelectorAll('.risk-item.selected').forEach(n => n.classList.remove('selected'));
        updateCopyBar();
        hideDetailPanel();

        const ids = [...selectedSubCatIds];
        if (!ids.length) {
            el('risks-tree').innerHTML = '<div class="text-muted small text-center py-4">اختر فئة فرعية لعرض المخاطر</div>';
            el('risk-count').textContent = '0';
            return;
        }

        el('risks-tree').innerHTML = '<div class="text-center py-2"><div class="spinner-border spinner-border-sm"></div></div>';

        const results = await Promise.all(
            ids.map(id =>
                fetchJson(`{{ url('app/risk/book/tree/risks-by-sub-category') }}/${id}`).catch(() => [])
            )
        );

        // Merge and deduplicate by ID, sort by score desc
        const seen = new Set();
        const merged = [];
        results.flat().forEach(r => {
            if (!seen.has(r.id)) {
                seen.add(r.id);
                merged.push(r);
            }
        });
        merged.sort((a, b) => (b.risk_score || 0) - (a.risk_score || 0));

        renderRisksList(merged);
    }

    function renderRisksList(risks) {
        const container = el('risks-tree');
        container.innerHTML = '';
        el('risk-count').textContent = risks.length;

        if (!risks.length) {
            container.innerHTML = '<div class="text-muted small text-center py-4">لا توجد مخاطر في الفرعيات المحددة</div>';
            return;
        }

        risks.forEach(r => {
            const item = document.createElement('div');
            item.className = 'risk-item';
            item.dataset.riskId = r.id;
            const scoreClass = r.risk_score >= 15 ? 'score-high' : (r.risk_score >= 7 ? 'score-med' : 'score-low');
            item.innerHTML = `
                <input type="checkbox" class="risk-cb form-check-input" value="${r.id}"
                       style="cursor:pointer;flex-shrink:0;" onclick="event.stopPropagation();">
                <span class="score ${scoreClass}">${r.risk_score || 0}</span>
                <span class="risk-title flex-grow-1" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${r.title}</span>
                <button class="btn-copy-one" onclick="event.stopPropagation(); copyOneToReference(${r.id}, this)"
                        title="نسخ للمرجعي"
                        style="flex-shrink:0;background:none;border:none;padding:0 2px;opacity:.5;cursor:pointer;">
                    <i class="bi bi-arrow-down-circle" style="font-size:.85rem;color:#5ba4f5;"></i>
                </button>`;

            item.querySelector('.risk-cb').addEventListener('change', (e) => {
                const id = parseInt(e.target.value);
                if (e.target.checked) {
                    selectedRiskIds.add(id);
                    item.classList.add('selected');
                } else {
                    selectedRiskIds.delete(id);
                    item.classList.remove('selected');
                }
                updateCopyBar();
                refreshDetailPanel();
            });

            // Click anywhere on the row = toggle the checkbox
            item.addEventListener('click', (ev) => {
                if (ev.target.closest('.btn-copy-one')) return;
                if (ev.target.classList.contains('risk-cb')) return;
                const cb = item.querySelector('.risk-cb');
                cb.checked = !cb.checked;
                cb.dispatchEvent(new Event('change'));
            });

            container.appendChild(item);
        });
    }

    // -----------------------------------------------------------------
    // Risk detail — inline panel below the 3 columns
    // -----------------------------------------------------------------

    async function refreshDetailPanel() {
        const ids = [...selectedRiskIds];
        if (!ids.length) {
            hideDetailPanel();
            return;
        }

        const panel  = el('risk-detail-panel');
        const header = el('detail-panel-header');
        const body   = el('detail-panel-body');
        panel.style.display = '';
        header.innerHTML = '<div class="d-flex align-items-center gap-2"><div class="spinner-border spinner-border-sm"></div><span class="small text-muted">جاري التحميل...</span></div>';
        body.innerHTML = '';

        // Fetch only uncached risks
        await Promise.all(ids.map(async id => {
            if (!riskDetailCache.has(id)) {
                try {
                    const r = await fetchJson(routes.riskDetail(id));
                    riskDetailCache.set(id, r);
                } catch (e) {
                    riskDetailCache.set(id, null);
                }
            }
        }));

        const risks = ids.map(id => riskDetailCache.get(id)).filter(Boolean);
        if (!risks.length) {
            header.innerHTML = '<span class="text-danger small">تعذّر تحميل التفاصيل</span>';
            return;
        }

        renderDetail(risks, header, body);
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function hideDetailPanel() {
        const panel = el('risk-detail-panel');
        panel.style.display = 'none';
        activePops.forEach(p => { try { p.dispose(); } catch(_){} });
        activePops = [];
    }

    // risks = array of risk detail objects
    function renderDetail(risks, headerEl, bodyEl) {
        const single = risks.length === 1;
        const r0     = risks[0];

        const phaseStyles = {
            proactive:   { color: '#0891b2', bg: 'rgba(8,145,178,.07)',  icon: '🛡️', label: 'استباقي',  hint: 'قبل بدء العمل' },
            operational: { color: '#d97706', bg: 'rgba(217,119,6,.07)',  icon: '⚡', label: 'تشغيلي',   hint: 'أثناء العمل' },
            response:    { color: '#dc2626', bg: 'rgba(220,38,38,.07)',  icon: '🚑', label: 'استجابة',  hint: 'بعد وقوع الحادث' },
        };
        const phaseOrder = ['proactive', 'operational', 'response'];
        const dash = `<span style="color:var(--text-muted)">—</span>`;

        // ── Header ──
        if (single) {
            const crumbs = [r0.category, r0.sub_category, r0.title].filter(Boolean);
            const breadcrumb = crumbs.map((c, i) =>
                i < crumbs.length - 1
                    ? `<span style="color:var(--text-muted);">${escAttr(c)}</span>`
                    : `<strong style="color:var(--text-main);">${escAttr(c)}</strong>`
            ).join(`<span style="margin:0 4px;color:var(--text-muted);opacity:.5;">›</span>`);
            headerEl.innerHTML = `
                <nav class="flex-grow-1 overflow-hidden" style="font-size:.83rem;line-height:1.7;word-break:break-word;">
                    ${breadcrumb}
                </nav>
                <div class="d-flex gap-1 flex-shrink-0 align-items-center">
                    <button onclick="copyOneToReference(${r0.id}, this)" class="btn btn-sm"
                            style="background:#0d6efd;color:#fff;font-size:.72rem;white-space:nowrap;">
                        <i class="bi bi-arrow-down-circle me-1"></i>نسخ للمرجعي
                    </button>
                    <a href="${routes.editRisk(r0.id)}" class="btn btn-sm btn-outline-secondary"
                       style="font-size:.72rem;white-space:nowrap;">
                        <i class="bi bi-pencil me-1"></i>تعديل
                    </a>
                </div>`;
        } else {
            headerEl.innerHTML = `
                <span class="badge bg-danger fs-6 me-2">${risks.length}</span>
                <span class="fw-semibold flex-grow-1">مقارنة أطوار المخاطر المحددة</span>
                <button onclick="copySelectedToReference()" class="btn btn-sm ms-2"
                        style="background:#0d6efd;color:#fff;font-size:.75rem;white-space:nowrap;">
                    <i class="bi bi-arrow-down-circle me-1"></i>نسخ الكل للمرجعي
                </button>`;
        }

        // ── Phase table rows ──
        // Column order: الكود+عنوان | الطور | الأسباب | المتأثرون | التقييم القبلي | الوقائي | التصحيحي | التقييم بعد | الجهة | المرجع القانوني | الفائدة
        function buildPhaseRows(r, showRiskCol) {
            const phasesById = {};
            (r.phases || []).forEach(p => { phasesById[p.phase] = p; });
            const sc = r.risk_score >= 15 ? 'score-high' : (r.risk_score >= 7 ? 'score-med' : 'score-low');
            const scoreColor = r.risk_score >= 15 ? '#dc3545' : (r.risk_score >= 9 ? '#ffc107' : (r.risk_score >= 4 ? '#0dcaf0' : '#198754'));
            let rows = '';

            phaseOrder.forEach((key, idx) => {
                const st = phaseStyles[key];
                const p  = phasesById[key];

                // الأسباب
                let causesHtml = dash;
                if (p?.causes?.length) {
                    const listHtml = `<ul class='mb-0 ps-3'>${p.causes.map(c => `<li>${escAttr(c.name)}</li>`).join('')}</ul>`;
                    causesHtml = popTrigger(`الأسباب — ${st.label}`, listHtml,
                        `<span class="badge-count">${p.causes.length} سبب</span>`);
                }

                // المتأثرون (أفراد / ممتلكات / سمعة / وقت / قدرات)
                let groupsHtml = dash;
                if (p?.affected_groups?.length) {
                    const chips = p.affected_groups.map(g =>
                        `<span style="display:inline-block;margin:1px 2px;padding:1px 6px;border-radius:12px;font-size:.7rem;background:${st.color}20;color:${st.color};border:1px solid ${st.color}60;">${escAttr(g.name)}</span>`
                    ).join('');
                    groupsHtml = popTrigger(`العواقب والأضرار — ${st.label}`,
                        `<div>${p.affected_groups.map(g => `<div><b>${escAttr(g.name)}</b>${g.impact ? ' — ' + ({low:'منخفض',medium:'متوسط',high:'عالٍ',critical:'حرج'}[g.impact] || g.impact) : ''}${g.rep_scope ? ' / ' + ({local:'محلي',regional:'إقليمي',national:'وطني',international:'دولي'}[g.rep_scope] || g.rep_scope) : ''}${g.detail ? '<div class="small text-muted">' + escAttr(g.detail) + '</div>' : ''}</div>`).join('')}</div>`,
                        `<div style="line-height:1.8;">${chips}</div>`);
                }

                // الإجراء الوقائي
                let preventiveHtml = dash;
                if (p?.preventive_action) {
                    preventiveHtml = popTrigger(`الإجراء الوقائي — ${st.label}`, escAttr(p.preventive_action),
                        `<span class="pop-preview">${truncWords(p.preventive_action, 4)}</span>`);
                }

                // الإجراء التصحيحي
                let correctiveHtml = dash;
                if (p?.corrective_action) {
                    correctiveHtml = popTrigger(`الإجراء التصحيحي — ${st.label}`, escAttr(p.corrective_action),
                        `<span class="pop-preview">${truncWords(p.corrective_action, 4)}</span>`);
                }

                // التقييم بعد الإجراءات
                let residualHtml = dash;
                if (p?.residual_assessment) {
                    residualHtml = popTrigger(`التقييم بعد الإجراءات — ${st.label}`, escAttr(p.residual_assessment),
                        `<span class="pop-preview">${truncWords(p.residual_assessment, 4)}</span>`);
                }

                // الجهة والشخص
                let responsibleHtml = dash;
                const orgUnit = p?.responsible_org_unit;
                const respUser = p?.responsible_user;
                if (orgUnit || respUser) {
                    const cHtml = `<b>الجهة:</b> ${escAttr(orgUnit || '—')}<br><b>الشخص:</b> ${escAttr(respUser || '—')}`;
                    responsibleHtml = popTrigger(`الجهة والشخص — ${st.label}`, cHtml,
                        `<span class="pop-preview" style="font-size:.72rem;">${truncWords(orgUnit || respUser || '', 2)}</span>`);
                }

                // الكود — rowspan=3 (أول صف فقط، العنوان انتقل للـbreadcrumb)
                let riskCell = '';
                if (idx === 0) {
                    riskCell = `
                        <td rowspan="3" class="align-top text-center" style="vertical-align:top!important;min-width:70px;max-width:90px;padding:6px 4px;">
                            <div class="d-flex flex-column align-items-center gap-1">
                                ${r.code
                                    ? `<code style="font-size:.7rem;color:var(--text-muted);word-break:break-all;direction:ltr;">${escAttr(r.code)}</code>`
                                    : `<span style="color:var(--text-muted);font-size:.72rem;">—</span>`}
                                ${showRiskCol ? `<div class="d-flex flex-column gap-1 mt-1 w-100">
                                    <button onclick="copyOneToReference(${r.id}, this)" class="btn btn-sm w-100"
                                            style="background:#0d6efd;color:#fff;font-size:.6rem;padding:2px 4px;white-space:nowrap;">
                                        <i class="bi bi-arrow-down-circle"></i> نسخ
                                    </button>
                                    <a href="${routes.editRisk(r.id)}" class="btn btn-sm btn-outline-secondary w-100"
                                       style="font-size:.6rem;padding:2px 4px;white-space:nowrap;text-align:center;">
                                        <i class="bi bi-pencil"></i> تعديل
                                    </a>
                                </div>` : ''}
                            </div>
                        </td>`;
                }

                // التقييم القبلي — rowspan=3 (أول صف فقط)
                let preAssessCell = '';
                if (idx === 0) {
                    preAssessCell = `
                        <td rowspan="3" class="align-middle text-center" style="vertical-align:middle!important;min-width:80px;">
                            <div style="display:inline-flex;flex-direction:column;align-items:center;gap:2px;">
                                <span style="display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:50%;background:${scoreColor};color:#fff;font-weight:bold;font-size:.95rem;">${r.risk_score || 0}</span>
                                <span style="font-size:.65rem;color:var(--text-muted);">${r.severity || ''}×${r.likelihood || ''}</span>
                            </div>
                        </td>`;
                }

                // المرجع القانوني — rowspan=3 (أول صف فقط)
                let legalCell = '';
                if (idx === 0) {
                    legalCell = `
                        <td rowspan="3" class="align-top" style="vertical-align:top!important;min-width:110px;">
                            ${r.legal_reference
                                ? `<span style="font-size:.75rem;color:#0d6efd;line-height:1.6;"><i class="bi bi-book-half me-1"></i>${escAttr(r.legal_reference)}</span>`
                                : dash}
                        </td>`;
                }

                // الفائدة — rowspan=3 (أول صف فقط)
                let benefitCell = '';
                if (idx === 0) {
                    benefitCell = `
                        <td rowspan="3" class="align-top" style="vertical-align:top!important;min-width:100px;">
                            ${r.benefit
                                ? `<span style="font-size:.75rem;color:var(--text-muted);line-height:1.6;">${escAttr(r.benefit)}</span>`
                                : dash}
                        </td>`;
                }

                // الإدارة — rowspan=3
                let adminCell = '';
                if (idx === 0) {
                    adminCell = `
                        <td rowspan="3" class="align-middle" style="vertical-align:middle!important;min-width:90px;">
                            <small style="color:var(--text-muted);">${escAttr(r.owner_department || '—')}</small>
                        </td>`;
                }

                // المسؤول — rowspan=3
                let coordinatorCell = '';
                if (idx === 0) {
                    coordinatorCell = `
                        <td rowspan="3" class="align-middle" style="vertical-align:middle!important;min-width:90px;">
                            <small style="color:var(--text-muted);">${escAttr(r.assigned_coordinator || '—')}</small>
                        </td>`;
                }

                // الفريق التنفيذي — rowspan=3
                let fieldTeamCell = '';
                if (idx === 0) {
                    fieldTeamCell = `
                        <td rowspan="3" class="align-middle" style="vertical-align:middle!important;min-width:90px;">
                            <small style="color:var(--text-muted);">${escAttr(r.assigned_field_team || '—')}</small>
                        </td>`;
                }

                const borderTop = (showRiskCol && idx === 0) ? 'border-top:3px solid var(--border-color);' : '';

                rows += `
                    <tr style="background:${st.bg};${borderTop}">
                        ${riskCell}
                        <td class="text-nowrap align-top" style="width:100px;">
                            <span class="detail-phase-badge"
                                  style="background:${st.bg};color:${st.color};border:1px solid ${st.color};">
                                ${st.icon} ${st.label}
                            </span>
                            <div style="font-size:.6rem;color:var(--text-muted);margin-top:2px;">${st.hint}</div>
                        </td>
                        <td>${causesHtml}</td>
                        <td>${groupsHtml}</td>
                        ${preAssessCell}
                        <td>${preventiveHtml}</td>
                        <td>${correctiveHtml}</td>
                        <td>${residualHtml}</td>
                        <td>${responsibleHtml}</td>
                        ${adminCell}
                        ${coordinatorCell}
                        ${fieldTeamCell}
                        ${legalCell}
                        ${benefitCell}
                    </tr>`;
            });
            return rows;
        }

        const showRiskCol = !single;
        let allRows = risks.map(r => buildPhaseRows(r, showRiskCol)).join('');

        bodyEl.innerHTML = `
            <div class="table-responsive">
                <table class="detail-phase-table table table-bordered mb-0" style="font-size:.82rem;">
                    <thead>
                        <tr style="background:var(--bg-main);">
                            <th style="min-width:70px;text-align:center;">الكود</th>
                            <th style="width:100px;">الطور</th>
                            <th>الأسباب</th>
                            <th>العواقب والأضرار</th>
                            <th style="width:80px;text-align:center;">التقييم القبلي</th>
                            <th>الإجراء الوقائي</th>
                            <th>الإجراء التصحيحي</th>
                            <th>التقييم بعد الإجراءات</th>
                            <th>الجهة والشخص</th>
                            <th>الإدارة</th>
                            <th>المسؤول</th>
                            <th>الفريق التنفيذي</th>
                            <th style="min-width:110px;">المرجع القانوني</th>
                            <th style="min-width:100px;">الفائدة</th>
                        </tr>
                    </thead>
                    <tbody>${allRows}</tbody>
                </table>
            </div>`;

        initPopovers(bodyEl);
    }

    // -----------------------------------------------------------------
    // Copy to reference
    // -----------------------------------------------------------------

    function updateCopyBar() {
        const bar = el('copy-bar');
        const cnt = el('copy-bar-count');
        if (selectedRiskIds.size > 0) {
            bar.style.display = 'flex';
            cnt.textContent = selectedRiskIds.size + ' محدد';
        } else {
            bar.style.display = 'none';
        }
    }

    window.clearSelection = function() {
        selectedRiskIds.clear();
        document.querySelectorAll('.risk-cb').forEach(cb => cb.checked = false);
        document.querySelectorAll('.risk-item.selected').forEach(n => n.classList.remove('selected'));
        updateCopyBar();
        hideDetailPanel();
    };

    window.copyOneToReference = async function(riskId, btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:.7rem;height:.7rem;"></span>';
        try {
            const res = await fetch('{{ route("risk.copyFromMaster", ":id") }}'.replace(':id', riskId), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok || res.redirected) {
                btn.innerHTML = '<i class="bi bi-check-circle-fill" style="color:#22c55e;font-size:.85rem;"></i>';
                showToast('تم نسخ الخطر للسجل المرجعي ✓', 'success');
            } else {
                throw new Error(data.message || 'خطأ في النسخ');
            }
        } catch (e) {
            btn.innerHTML = '<i class="bi bi-arrow-down-circle" style="font-size:.85rem;color:#5ba4f5;"></i>';
            btn.disabled = false;
            showToast('فشل النسخ: ' + e.message, 'error');
        }
    };

    window.copySelectedToReference = async function() {
        if (!selectedRiskIds.size) return;
        const btn = el('copy-bar').querySelector('button');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" style="width:.75rem;height:.75rem;"></span>جاري النسخ...';
        try {
            const params = new URLSearchParams();
            selectedRiskIds.forEach(id => params.append('risk_ids[]', id));
            const res = await fetch('{{ route("risk.bulkCopyFromMaster") }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: params.toString(),
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok || res.redirected || data.success !== false) {
                showToast(`تم نسخ ${selectedRiskIds.size} خطر للسجل المرجعي ✓`, 'success');
                clearSelection();
                document.querySelectorAll('.risk-item').forEach(item => {
                    const copyBtn = item.querySelector('.btn-copy-one');
                    if (copyBtn) copyBtn.innerHTML = '<i class="bi bi-check-circle-fill" style="color:#22c55e;font-size:.85rem;"></i>';
                });
            } else {
                throw new Error(data.message || data.error || 'خطأ في النسخ');
            }
        } catch (e) {
            showToast('فشل النسخ: ' + e.message, 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-down-circle me-1"></i>نسخ للمرجعي';
        }
    };

    // -----------------------------------------------------------------
    // Toast
    // -----------------------------------------------------------------

    function showToast(msg, type) {
        const t = document.createElement('div');
        t.style.cssText = `position:fixed;bottom:24px;left:24px;z-index:9999;
            padding:10px 18px;border-radius:10px;font-size:.85rem;font-weight:600;
            box-shadow:0 4px 20px rgba(0,0,0,.3);transition:opacity .4s;
            background:${type === 'success' ? '#166534' : '#7f1d1d'};color:#fff;`;
        t.textContent = msg;
        document.body.appendChild(t);
        setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 400); }, 3000);
    }

    // -----------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------

    window.riskBook = {
        toggleView() {
            const tv  = el('tree-view');
            const tb  = el('table-view');
            const lbl = el('view-toggle-label');
            if (tv.style.display === 'none') {
                tv.style.display = '';
                tb.style.display = 'none';
                lbl.textContent  = 'عرض الجدول';
            } else {
                tv.style.display = 'none';
                tb.style.display = '';
                lbl.textContent  = 'عرض الشجرة';
            }
        },
    };

    document.addEventListener('DOMContentLoaded', () => {
        loadCategories();
    });
})();
</script>
@endsection
