@extends('layouts.app')

@section('page_title', 'السجل الفعلي')
@php
    // المعهد: رابط «مخاطر المكان» من اللوحة يمرّر ?place=HZ-xx
    $placeFilter = request('place') ? \App\Modules\Governance\Models\Place::where('code', request('place'))->first() : null;
    $placeQ = $placeFilter ? '?place='.e($placeFilter->code) : '';
@endphp

@section('content')
<div class="container-fluid" id="active-registry-app">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-1" style="color: var(--text-main);">
                <i class="bi bi-shield-check me-2" style="color: var(--accent);"></i>
                السجل الفعلي
                @if($placeFilter)<span class="badge bg-info ms-2" style="font-size:.7rem">{{ $placeFilter->code }} — {{ $placeFilter->name }}</span> <a href="{{ route('risk.active.index') }}" class="small" style="color:var(--text-muted)">كل الأماكن</a>@endif
            </h4>
            <p class="mb-0" style="color: var(--text-muted); font-size: 0.85rem;">
                تصفّح هرمي: فئة رئيسية → اختر الفرعيات → اختر خطر → تفاصيل
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('risk.active.create') }}" class="btn btn-accent">
                <i class="bi bi-plus-lg me-1"></i> إضافة خطر
            </a>
        </div>
    </div>

    {{-- ══════════════ Tree View ══════════════ --}}
    <div id="active-tree-view">
        <div class="row g-3">

            {{-- Column 1: الفئة الرئيسية --}}
            <div class="col-lg-4 col-md-6">
                <div class="card h-100 tree-panel">
                    <div class="card-header py-2">
                        <strong><i class="bi bi-1-circle me-1 text-accent"></i> الفئة الرئيسية</strong>
                    </div>
                    <div class="card-body p-2" style="max-height:500px; overflow-y:auto;">
                        <div id="active-categories-list">
                            <div class="text-muted small text-center py-4">
                                <div class="spinner-border spinner-border-sm"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Column 2: الفئة الفرعية --}}
            <div class="col-lg-4 col-md-6">
                <div class="card h-100 tree-panel">
                    <div class="card-header py-2 d-flex align-items-center">
                        <strong class="flex-grow-1"><i class="bi bi-2-circle me-1 text-accent"></i> الفئة الفرعية</strong>
                        <span class="badge bg-secondary ms-2" id="active-subcat-count">0</span>
                        <button class="btn btn-sm btn-link text-muted ms-1 p-0"
                                style="font-size:.72rem;" onclick="activeToggleSelectAllSubCats()" title="تحديد الكل">
                            <i class="bi bi-check-all"></i>
                        </button>
                    </div>
                    <div class="card-body p-2" style="max-height:500px; overflow-y:auto;">
                        <div id="active-subcategories-list">
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
                        <span class="badge bg-danger" id="active-risk-count">0</span>
                    </div>
                    <div class="card-body p-2" style="max-height:500px; overflow-y:auto;">
                        <div id="active-risks-tree">
                            <div class="text-muted small text-center py-4">اختر فئة فرعية لعرض المخاطر</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Detail Panel --}}
        <div id="active-risk-detail-panel" style="display:none; margin-top:1rem;">
            <div class="card tree-panel">
                <div class="card-header py-2 d-flex align-items-start gap-3 flex-wrap"
                     id="active-detail-panel-header" style="min-height:54px;"></div>
                <div class="card-body p-0">
                    <div id="active-detail-panel-body"></div>
                </div>
            </div>
        </div>
    </div>

</div>

<style>
    .tree-panel { background: var(--bg-card); border: 1px solid var(--border-color); }
    .tree-panel .card-header { background: var(--bg-dark); border-bottom: 1px solid var(--border-color); color: var(--text-main); }
    .text-accent { color: var(--accent); }
    .cat-nav-item { display:block;padding:.45rem .75rem;margin:.15rem 0;border-radius:.35rem;cursor:pointer;font-size:.84rem;color:var(--text-main);border:1px solid transparent;transition:background .15s; }
    .cat-nav-item:hover { background:rgba(8,145,178,.08);border-color:rgba(8,145,178,.25); }
    .cat-nav-item.selected { background:rgba(8,145,178,.18);border-color:var(--accent);color:var(--accent);font-weight:600; }
    .subcat-check-item { display:block;padding:.35rem .6rem;margin:.12rem 0;border-radius:.3rem;border:1px solid transparent;transition:background .15s;font-size:.82rem;color:var(--text-main); }
    .subcat-check-item:hover { background:rgba(8,145,178,.06);border-color:rgba(8,145,178,.2); }
    .subcat-check-item label { cursor:pointer;display:flex;align-items:center;gap:.45rem;margin:0;width:100%; }
    .subcat-check-item input[type="checkbox"] { flex-shrink:0;cursor:pointer; }
    .subcat-check-item.checked { background:rgba(8,145,178,.12);border-color:rgba(8,145,178,.4); }
    .risk-item { display:flex;align-items:center;gap:4px;padding:.4rem .6rem;margin:.15rem 0;border-radius:.3rem;cursor:pointer;font-size:.82rem;color:var(--text-main);transition:background .15s;border:1px solid transparent; }
    .risk-item:hover { background:rgba(8,145,178,.08);border-color:rgba(8,145,178,.3); }
    .risk-item.selected { background:rgba(16,185,129,.12);border-color:#10b981; }
    .risk-item .score { display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;font-size:.7rem;font-weight:700;color:#fff;flex-shrink:0; }
    .score-high { background:#ef4444; } .score-med { background:#f59e0b; } .score-low { background:#10b981; }
    .detail-risk-title { font-size:1rem;font-weight:700;color:var(--text-main);line-height:1.3; }
    .detail-risk-meta { font-size:.75rem;color:var(--text-muted);margin-top:2px; }
    .score-big { display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:50%;color:#fff;font-weight:700;font-size:1.1rem;flex-shrink:0; }
    .risk-table { font-size:.8rem;color:var(--text-main); }
    .risk-table thead th { background:var(--bg-dark);color:var(--text-muted);font-weight:600;font-size:.7rem;white-space:nowrap;border-bottom:2px solid var(--border-color);padding:.6rem .5rem; }
    .risk-table tbody td { padding:.4rem .5rem;border-color:var(--border-color);vertical-align:middle;white-space:nowrap; }
    .phase-col { width:80px; }
    .phase-badge { display:inline-block;padding:.18rem .55rem;border-radius:20px;font-size:.68rem;font-weight:700;white-space:nowrap; }
    .code-pill { display:inline-block;padding:.2rem .5rem;border-radius:.25rem;font-family:monospace;font-size:.7rem;font-weight:600;white-space:nowrap; }
    .code-pill-active { background:rgba(16,185,129,.18);color:#34d399; }
    .score-circle { display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;color:#fff;font-weight:700;font-size:.78rem; }
    .risk-title-cell { max-width:180px; }
    .risk-title-link { color:var(--text-main);text-decoration:none;font-weight:500;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
    .risk-title-link:hover { color:var(--accent); }
    .pop-trigger { display:inline-flex;align-items:center;gap:3px;cursor:pointer;padding:2px 7px;border-radius:20px;border:1px solid var(--border-color);background:var(--bg-dark);font-size:.72rem;color:var(--text-main);transition:background .15s;white-space:nowrap; }
    .pop-trigger:hover { background:rgba(8,145,178,.12);border-color:var(--accent);color:var(--accent); }
    .badge-count { font-weight:600; }
    .pop-preview { max-width:110px;overflow:hidden;text-overflow:ellipsis;display:inline-block;vertical-align:middle; }
    .pop-arrow { font-size:.55rem;opacity:.6; }
    .popover { max-width:340px;font-size:.82rem;direction:rtl;text-align:right; }
    .popover-body ul { margin:0;padding-right:1.2rem;padding-left:0; }
    .impact-tag { display:inline-block;padding:1px 5px;border-radius:999px;font-size:.62rem;font-weight:600;color:#fff; }
    .impact-low{background:#10b981;} .impact-medium{background:#06b6d4;} .impact-high{background:#f59e0b;} .impact-critical{background:#ef4444;}
    .status-badge { display:inline-block;padding:1px 7px;border-radius:999px;font-size:.65rem;font-weight:600; }
    .status-active    { background:rgba(16,185,129,.15);color:#059669;border:1px solid #059669; }
    .status-draft     { background:rgba(100,116,139,.12);color:#64748b;border:1px solid #94a3b8; }
    .status-mitigated { background:rgba(8,145,178,.12);color:#0891b2;border:1px solid #0891b2; }
    .status-closed    { background:rgba(239,68,68,.1);color:#dc2626;border:1px solid #dc2626; }
</style>

<script>
(function () {
    const API = {
        categories:    `{{ url('app/risk/registry/tree/active/categories') }}{{ $placeQ }}`,
        subCategories: (catId)    => `{{ url('app/risk/registry/tree/active/sub-categories') }}/${catId}{{ $placeQ }}`,
        risksBySub:    (subCatId) => `{{ url('app/risk/registry/tree/active/risks-by-sub-category') }}/${subCatId}{{ $placeQ }}`,
        riskDetail:    (id)       => `{{ url('app/risk/registry/tree/active/risk') }}/${id}`,
        editRisk:      (id)       => `{{ url('app/risk/active') }}/${id}/edit`,
    };

    const selectedSubCatIds = new Set();
    const selectedRiskIds   = new Set();
    const riskDetailCache   = new Map();
    let activePops = [];

    async function fetchJson(url) {
        const r = await fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    }

    function el(id) { return document.getElementById(id); }

    function escAttr(s) {
        return String(s ?? '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    function truncWords(s, n) {
        if (!s) return '';
        const words = s.trim().split(/\s+/);
        return words.length <= n ? s : words.slice(0, n).join(' ') + '…';
    }
    function popTrigger(title, contentHtml, preview) {
        return `<span class="pop-trigger"
            data-bs-toggle="popover" data-bs-placement="top" data-bs-trigger="click"
            data-bs-title="${escAttr(title)}" data-bs-html="true" data-bs-content="${escAttr(contentHtml)}">
            <span class="pop-preview">${preview}</span>
            <i class="bi bi-chevron-down pop-arrow"></i></span>`;
    }
    function initPopovers(container) {
        activePops.forEach(p => { try { p.dispose(); } catch(_){} });
        activePops = [];
        container.querySelectorAll('[data-bs-toggle="popover"]').forEach(popEl => {
            const p = new bootstrap.Popover(popEl, { container: 'body' });
            activePops.push(p);
            popEl.addEventListener('show.bs.popover', () => activePops.forEach(o => { if (o !== p) o.hide(); }));
        });
        container.addEventListener('click', e => {
            if (!e.target.closest('[data-bs-toggle="popover"]') && !e.target.closest('.popover'))
                activePops.forEach(p => p.hide());
        }, { capture: false });
    }

    function statusBadge(status, label) {
        const cls = { active:'status-active', draft:'status-draft', mitigated:'status-mitigated', closed:'status-closed' }[status] || 'status-draft';
        return `<span class="status-badge ${cls}">${escAttr(label || status)}</span>`;
    }

    function treeError(msg, retryFn) {
        return `<div class="alert alert-warning m-2 p-2 small d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <span>${msg}</span>
            ${retryFn ? `<button class="btn btn-sm btn-outline-warning ms-auto" onclick="${retryFn}()">إعادة</button>` : ''}
        </div>`;
    }

    // ── Column 1 ──
    async function loadCategories() {
        const container = el('active-categories-list');
        try {
            const cats = await fetchJson(API.categories);
            container.innerHTML = '';
            if (!cats.length) {
                container.innerHTML = '<div class="text-muted small text-center py-4">لا توجد مخاطر فعلية بعد</div>';
                return;
            }
            cats.forEach(cat => {
                const item = document.createElement('div');
                item.className = 'cat-nav-item';
                item.textContent = cat.name;
                item.addEventListener('click', () => selectCategory(cat.id, item));
                container.appendChild(item);
            });
        } catch(e) {
            container.innerHTML = treeError('فشل تحميل الفئات', 'loadCategories');
        }
    }

    async function selectCategory(id, node) {
        selectedSubCatIds.clear();
        document.querySelectorAll('#active-categories-list .cat-nav-item').forEach(n => n.classList.remove('selected'));
        node.classList.add('selected');
        el('active-subcategories-list').innerHTML = '<div class="text-center py-2"><div class="spinner-border spinner-border-sm"></div></div>';
        el('active-risks-tree').innerHTML = '<div class="text-muted small text-center py-4">اختر فئة فرعية</div>';
        el('active-risk-count').textContent = '0';
        hideDetailPanel();
        try {
            const subs = await fetchJson(API.subCategories(id));
            renderSubCategories(subs);
        } catch(e) {
            el('active-subcategories-list').innerHTML = treeError('فشل تحميل الفئات الفرعية');
        }
    }

    // ── Column 2 ──
    function renderSubCategories(subs) {
        const container = el('active-subcategories-list');
        container.innerHTML = '';
        el('active-subcat-count').textContent = subs.length;
        if (!subs.length) { container.innerHTML = '<div class="text-muted small text-center py-4">لا توجد فئات فرعية</div>'; return; }
        subs.forEach(sub => {
            const uid  = 'active-sc-' + sub.id;
            const item = document.createElement('div');
            item.className = 'subcat-check-item';
            item.innerHTML = `<label for="${uid}">
                <input type="checkbox" class="active-subcat-cb form-check-input" id="${uid}" value="${sub.id}">
                <span class="flex-grow-1">${sub.name}</span>
            </label>`;
            item.querySelector('input').addEventListener('change', e => {
                const subId = parseInt(e.target.value);
                if (e.target.checked) { selectedSubCatIds.add(subId); item.classList.add('checked'); }
                else { selectedSubCatIds.delete(subId); item.classList.remove('checked'); }
                hideDetailPanel();
                loadRisksForSelectedSubs();
            });
            container.appendChild(item);
        });
    }

    window.activeToggleSelectAllSubCats = function () {
        const checkboxes = document.querySelectorAll('#active-subcategories-list .active-subcat-cb');
        if (!checkboxes.length) return;
        const anyUnchecked = [...checkboxes].some(cb => !cb.checked);
        checkboxes.forEach(cb => {
            const subId = parseInt(cb.value);
            cb.checked = anyUnchecked;
            const item = cb.closest('.subcat-check-item');
            if (anyUnchecked) { selectedSubCatIds.add(subId); item.classList.add('checked'); }
            else { selectedSubCatIds.delete(subId); item.classList.remove('checked'); }
        });
        hideDetailPanel();
        loadRisksForSelectedSubs();
    };

    // ── Column 3 ──
    async function loadRisksForSelectedSubs() {
        selectedRiskIds.clear();
        riskDetailCache.clear();
        hideDetailPanel();
        const ids = [...selectedSubCatIds];
        if (!ids.length) {
            el('active-risks-tree').innerHTML = '<div class="text-muted small text-center py-4">اختر فئة فرعية لعرض المخاطر</div>';
            el('active-risk-count').textContent = '0';
            return;
        }
        el('active-risks-tree').innerHTML = '<div class="text-center py-2"><div class="spinner-border spinner-border-sm"></div></div>';
        const results = await Promise.all(ids.map(id => fetchJson(API.risksBySub(id)).catch(() => [])));
        const seen = new Set(); const merged = [];
        results.flat().forEach(r => { if (!seen.has(r.id)) { seen.add(r.id); merged.push(r); } });
        merged.sort((a, b) => (b.risk_score || 0) - (a.risk_score || 0));
        renderRisksList(merged);
    }

    function renderRisksList(risks) {
        const container = el('active-risks-tree');
        container.innerHTML = '';
        el('active-risk-count').textContent = risks.length;
        if (!risks.length) { container.innerHTML = '<div class="text-muted small text-center py-4">لا توجد مخاطر</div>'; return; }
        risks.forEach(r => {
            const item = document.createElement('div');
            item.className = 'risk-item';
            const scoreClass = r.risk_score >= 15 ? 'score-high' : (r.risk_score >= 7 ? 'score-med' : 'score-low');
            const stLabel = { active:'نشط', draft:'مسودة', mitigated:'مخفَّف', closed:'مغلق' }[r.status] || r.status;
            const stCls   = { active:'status-active', draft:'status-draft', mitigated:'status-mitigated', closed:'status-closed' }[r.status] || 'status-draft';
            item.innerHTML = `
                <input type="checkbox" class="active-risk-cb form-check-input" value="${r.id}"
                       style="cursor:pointer;flex-shrink:0;" onclick="event.stopPropagation();">
                <span class="score ${scoreClass}">${r.risk_score || 0}</span>
                <span class="risk-title flex-grow-1" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${r.title}</span>
                <span class="status-badge ${stCls}" style="flex-shrink:0;font-size:.6rem;">${stLabel}</span>`;
            item.querySelector('.active-risk-cb').addEventListener('change', e => {
                const id = parseInt(e.target.value);
                if (e.target.checked) { selectedRiskIds.add(id); item.classList.add('selected'); }
                else { selectedRiskIds.delete(id); item.classList.remove('selected'); }
                refreshDetailPanel();
            });
            item.addEventListener('click', ev => {
                if (ev.target.classList.contains('active-risk-cb')) return;
                const cb = item.querySelector('.active-risk-cb');
                cb.checked = !cb.checked;
                cb.dispatchEvent(new Event('change'));
            });
            container.appendChild(item);
        });
    }

    // ── Detail Panel ──
    async function refreshDetailPanel() {
        const ids = [...selectedRiskIds];
        if (!ids.length) { hideDetailPanel(); return; }
        const panel  = el('active-risk-detail-panel');
        const header = el('active-detail-panel-header');
        const body   = el('active-detail-panel-body');
        panel.style.display = '';
        header.innerHTML = '<div class="d-flex align-items-center gap-2"><div class="spinner-border spinner-border-sm"></div><span class="small text-muted">جاري التحميل...</span></div>';
        body.innerHTML = '';
        await Promise.all(ids.map(async id => {
            if (!riskDetailCache.has(id)) {
                try { riskDetailCache.set(id, await fetchJson(API.riskDetail(id))); }
                catch(e) { riskDetailCache.set(id, null); }
            }
        }));
        const risks = ids.map(id => riskDetailCache.get(id)).filter(Boolean);
        if (!risks.length) { header.innerHTML = '<span class="text-danger small">تعذّر تحميل التفاصيل</span>'; return; }
        renderDetail(risks, header, body);
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function hideDetailPanel() {
        el('active-risk-detail-panel').style.display = 'none';
        activePops.forEach(p => { try { p.dispose(); } catch(_){} });
        activePops = [];
    }

    function renderDetail(risks, headerEl, bodyEl) {
        const single = risks.length === 1;
        const r0     = risks[0];
        const phaseStyles = {
            proactive:   { color:'#0891b2', bg:'rgba(8,145,178,.07)',  icon:'🛡️', label:'استباقي',  hint:'قبل بدء العمل' },
            operational: { color:'#d97706', bg:'rgba(217,119,6,.07)',  icon:'⚡', label:'تشغيلي',   hint:'أثناء العمل' },
            response:    { color:'#dc2626', bg:'rgba(220,38,38,.07)',  icon:'🚑', label:'استجابة',  hint:'بعد وقوع الحادث' },
        };
        const phaseOrder = ['proactive', 'operational', 'response'];
        const dash = `<span style="color:var(--text-muted)">—</span>`;

        // ── Header (breadcrumb) ──
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
                    ${r0.status_label ? `<span class="ms-2">${statusBadge(r0.status, r0.status_label)}</span>` : ''}
                    ${r0.organization_unit ? `<span class="ms-2" style="font-size:.72rem;color:var(--text-muted);"><i class="bi bi-building me-1"></i>${escAttr(r0.organization_unit)}</span>` : ''}
                </nav>
                <div class="d-flex gap-1 flex-shrink-0 align-items-center">
                    <a href="${API.editRisk(r0.id)}" class="btn btn-sm btn-outline-secondary"
                       style="font-size:.72rem;white-space:nowrap;">
                        <i class="bi bi-pencil me-1"></i>تعديل
                    </a>
                </div>`;
        } else {
            headerEl.innerHTML = `
                <span class="badge bg-danger fs-6 me-2">${risks.length}</span>
                <span class="fw-semibold flex-grow-1">مقارنة أطوار المخاطر المحددة</span>`;
        }

        // ── Full table rows (matches _risk_table.blade.php — active type) ──
        function buildRow(r) {
            const phasesById = {};
            (r.phases || []).forEach(p => { phasesById[p.phase] = p; });
            const score = r.risk_score || 0;
            const scoreColor = score >= 15 ? '#ef4444' : (score >= 7 ? '#f59e0b' : '#10b981');
            let rows = '';

            ['proactive','operational','response'].forEach((key, idx) => {
                const st = {
                    proactive:   { label:'استباقي', color:'#0891b2', bg:'rgba(8,145,178,.10)' },
                    operational: { label:'تشغيلي',  color:'#d97706', bg:'rgba(217,119,6,.10)'  },
                    response:    { label:'استجابة', color:'#dc2626', bg:'rgba(220,38,38,.10)'  },
                }[key];
                const p       = phasesById[key];
                const isFirst = idx === 0;
                const isLast  = idx === 2;
                const rowStyle = `background:${st.bg};${isFirst ? 'border-top:2px solid var(--border-color);' : ''}${isLast ? 'border-bottom:3px solid var(--border-color);' : ''}`;

                const causesList = (p?.causes || []).map(c => c.name);
                let causesHtml = dash;
                if (causesList.length) {
                    causesHtml = popTrigger(`الأسباب — ${st.label}`,
                        `<ul class='mb-0 ps-3'>${causesList.map(c => `<li>${escAttr(c)}</li>`).join('')}</ul>`,
                        `<span class="badge-count">${causesList.length} سبب</span>`);
                }

                const groupsList = (p?.affected_groups || []).map(g => g.name);
                let groupsHtml = dash;
                if (groupsList.length) {
                    groupsHtml = popTrigger(`المتأثرون — ${st.label}`,
                        `<ul class='mb-0 ps-3'>${groupsList.map(g => `<li>${escAttr(g)}</li>`).join('')}</ul>`,
                        `<span class="badge-count">${groupsList.length} فئة</span>`);
                }

                let correctiveHtml = dash;
                if (p?.corrective_action) {
                    correctiveHtml = popTrigger(`الإجراء التصحيحي — ${st.label}`, escAttr(p.corrective_action),
                        `<span class="pop-preview">${truncWords(p.corrective_action, 3)}</span>`);
                }

                let preventiveHtml = dash;
                if (p?.preventive_action) {
                    preventiveHtml = popTrigger(`الإجراء الوقائي — ${st.label}`, escAttr(p.preventive_action),
                        `<span class="pop-preview">${truncWords(p.preventive_action, 3)}</span>`);
                }

                const orgUnit  = p?.responsible_org_unit;
                const respUser = p?.responsible_user;
                let responsibleHtml = dash;
                if (orgUnit || respUser) {
                    responsibleHtml = popTrigger(`الجهة والمفوّض — ${st.label}`,
                        `<b>الجهة:</b> ${escAttr(orgUnit||'—')}<br><b>المفوّض:</b> ${escAttr(respUser||'—')}`,
                        `<span class="pop-preview">${truncWords(orgUnit||respUser||'', 2)}</span>`);
                }

                rows += `
                    <tr style="${rowStyle}">
                        ${isFirst ? `
                        <td rowspan="3" class="align-middle text-center" style="vertical-align:middle!important;">
                            <span class="code-pill code-pill-active">${escAttr(r.code || 'R-' + r.id)}</span>
                        </td>
                        <td rowspan="3" class="align-middle text-center" style="vertical-align:middle!important;">
                            <span class="score-circle" style="background:${scoreColor};">${score}</span>
                        </td>
                        <td rowspan="3" class="risk-title-cell align-middle" style="vertical-align:middle!important;">
                            <a href="${API.editRisk(r.id)}" class="risk-title-link" title="${escAttr(r.title||'')}">
                                ${escAttr((r.title||'').length > 35 ? r.title.slice(0,35)+'…' : (r.title||''))}
                            </a>
                        </td>
                        <td rowspan="3" class="align-middle" style="vertical-align:middle!important;">
                            <small class="text-muted">${escAttr(r.category||'—')}</small>
                        </td>
                        ` : ''}
                        <td class="text-nowrap">
                            <span class="phase-badge" style="background:${st.bg};color:${st.color};border:1px solid ${st.color};">
                                ${st.label}
                            </span>
                        </td>
                        <td>${causesHtml}</td>
                        <td>${groupsHtml}</td>
                        <td>${correctiveHtml}</td>
                        <td>${preventiveHtml}</td>
                        ${isFirst ? `
                        <td rowspan="3" class="align-middle" style="vertical-align:middle!important;">
                            <small>${escAttr(r.organization_unit||'—')}</small>
                        </td>
                        <td rowspan="3" class="align-middle" style="vertical-align:middle!important;">
                            ${statusBadge(r.status, r.status_label)}
                        </td>
                        <td rowspan="3" class="align-middle" style="vertical-align:middle!important;">
                            <small class="text-muted">${escAttr(r.owner_department||'—')}</small>
                        </td>
                        <td rowspan="3" class="align-middle" style="vertical-align:middle!important;">
                            <small class="text-muted">${escAttr(r.assigned_coordinator||'—')}</small>
                        </td>
                        <td rowspan="3" class="align-middle" style="vertical-align:middle!important;">
                            <small class="text-muted">${escAttr(r.assigned_field_team||'—')}</small>
                        </td>
                        ` : ''}
                        <td>${responsibleHtml}</td>
                        ${isFirst ? `
                        <td rowspan="3" class="align-middle" style="vertical-align:middle!important;">
                            <a href="${API.editRisk(r.id)}" class="btn btn-sm btn-outline-secondary" title="تعديل">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </td>
                        ` : ''}
                    </tr>`;
            });
            return rows;
        }

        const allRows = risks.map(r => buildRow(r)).join('');

        bodyEl.innerHTML = `
            <div class="table-responsive">
                <table class="risk-table table table-sm mb-0">
                    <thead><tr>
                        <th>الكود</th>
                        <th class="text-center">الدرجة</th>
                        <th>العنوان</th>
                        <th>الفئة</th>
                        <th class="phase-col">الطور</th>
                        <th>الأسباب</th>
                        <th>المتأثرون</th>
                        <th>الإجراء التصحيحي</th>
                        <th>الإجراء الوقائي</th>
                        <th>الوحدة</th>
                        <th>الحالة</th>
                        <th>الإدارة</th>
                        <th>المسؤول</th>
                        <th>الفريق التنفيذي</th>
                        <th>الجهة / المفوّض</th>
                        <th>إجراءات</th>
                    </tr></thead>
                    <tbody>${allRows}</tbody>
                </table>
            </div>`;

        initPopovers(bodyEl);
    }


    document.addEventListener('DOMContentLoaded', () => loadCategories());
})();
</script>
@endsection
