@extends('layouts.app')

@section('page_title', 'السجل الفعلي للمخاطر')

@section('content')
<div class="container-fluid" id="active-registry-app">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-1" style="color: var(--text-main);">
                <i class="bi bi-lightning-charge me-2" style="color: var(--accent);"></i>
                السجل الفعلي للمخاطر
            </h4>
            <p class="mb-0" style="color: var(--text-muted); font-size: 0.85rem;">
                تصفّح هرمي: فئة رئيسية → اختر الفرعيات → اختر خطر → تفاصيل
            </p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" onclick="activeBook.toggleView()">
                <i class="bi bi-table"></i> <span id="active-view-toggle-label">عرض الجدول</span>
            </button>
            <a href="{{ route('risk.export', request()->query()) }}" class="btn btn-outline-success">
                <i class="bi bi-download me-1"></i> تصدير CSV
            </a>
            <a href="{{ route('risk.create') }}" class="btn btn-accent">
                <i class="bi bi-plus-circle me-1"></i> إضافة خطر
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

            {{-- Column 2: الفئة الفرعية (multi-checkbox) --}}
            <div class="col-lg-4 col-md-6">
                <div class="card h-100 tree-panel">
                    <div class="card-header py-2 d-flex align-items-center">
                        <strong class="flex-grow-1"><i class="bi bi-2-circle me-1 text-accent"></i> الفئة الفرعية</strong>
                        <span class="badge bg-secondary ms-2" id="active-subcat-count">0</span>
                        <button class="btn btn-sm btn-link text-muted ms-1 p-0" style="font-size:.72rem;"
                                onclick="activeToggleSelectAllSubCats()" title="تحديد الكل">
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

    {{-- ══════════════ Table View (toggle) ══════════════ --}}
    <div id="active-table-view" style="display:none;">
        <div class="card mb-3" style="background:var(--bg-card); border:1px solid var(--border-color);">
            <div class="card-body">
                <form method="GET" action="{{ route('risk.index') }}" class="row g-2">
                    <div class="col-md-3">
                        <input type="text" name="search" value="{{ request('search') }}" class="form-control" placeholder="ابحث في العنوان أو الكود...">
                    </div>
                    <div class="col-md-2">
                        <select name="category_id" class="form-select">
                            <option value="">جميع الفئات</option>
                            @foreach(($categories ?? []) as $cat)
                                <option value="{{ $cat->id }}" @selected(request('category_id')==$cat->id)>{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select">
                            <option value="">جميع الحالات</option>
                            <option value="active"      @selected(request('status')=='active')>نشط</option>
                            <option value="in_progress" @selected(request('status')=='in_progress')>قيد المعالجة</option>
                            <option value="closed"      @selected(request('status')=='closed')>مغلق</option>
                            <option value="escalated"   @selected(request('status')=='escalated')>مصعّد</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="severity_filter" class="form-select">
                            <option value="">جميع الدرجات</option>
                            <option value="critical" @selected(request('severity_filter')=='critical')>حرجة (15+)</option>
                            <option value="high"     @selected(request('severity_filter')=='high')>عالية (7-14)</option>
                            <option value="low"      @selected(request('severity_filter')=='low')>منخفضة (1-6)</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-accent w-100"><i class="bi bi-search"></i> بحث وتصفية</button>
                    </div>
                </form>
            </div>
        </div>
        @include('modules.risks.partials._risk_table', [
            'risks'         => $risks ?? collect(),
            'registry_type' => 'active',
            'can_edit'      => true,
        ])
    </div>
</div>

{{-- ══ CSS (نفس الكتاب) ══ --}}
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
    .risk-item.selected { background:rgba(239,68,68,.12);border-color:#ef4444; }
    .risk-item .score { display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;font-size:.7rem;font-weight:700;color:#fff;flex-shrink:0; }
    .score-high{background:#ef4444;} .score-med{background:#f59e0b;} .score-low{background:#10b981;}
    .detail-risk-title { font-size:1rem;font-weight:700;color:var(--text-main);line-height:1.3; }
    .detail-risk-meta { font-size:.75rem;color:var(--text-muted);margin-top:2px; }
    .score-big { display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:50%;color:#fff;font-weight:700;font-size:1.1rem;flex-shrink:0; }
    .detail-phase-table { font-size:.8rem;color:var(--text-main);width:100%;margin:0; }
    .detail-phase-table thead th { background:var(--bg-dark);color:var(--text-muted);font-size:.7rem;font-weight:600;white-space:nowrap;padding:.5rem .7rem;border-color:var(--border-color);border-bottom:2px solid var(--border-color); }
    .detail-phase-table tbody td { padding:.55rem .7rem;border-color:var(--border-color);vertical-align:top;white-space:normal;line-height:1.55; }
    .detail-phase-badge { display:inline-block;padding:.2rem .6rem;border-radius:20px;font-size:.7rem;font-weight:700;white-space:nowrap; }
    .pop-trigger { display:inline-flex;align-items:center;gap:3px;cursor:pointer;padding:2px 7px;border-radius:20px;border:1px solid var(--border-color);background:var(--bg-dark);font-size:.72rem;color:var(--text-main);transition:background .15s;white-space:nowrap; }
    .pop-trigger:hover { background:rgba(8,145,178,.12);border-color:var(--accent);color:var(--accent); }
    .badge-count{font-weight:600;} .pop-preview{max-width:110px;overflow:hidden;text-overflow:ellipsis;display:inline-block;vertical-align:middle;} .pop-arrow{font-size:.55rem;opacity:.6;}
    .popover{max-width:340px;font-size:.82rem;direction:rtl;text-align:right;} .popover-body ul{margin:0;padding-right:1.2rem;padding-left:0;}
    .impact-tag{display:inline-block;padding:1px 5px;border-radius:999px;font-size:.62rem;font-weight:600;color:#fff;}
    .impact-low{background:#10b981;} .impact-medium{background:#06b6d4;} .impact-high{background:#f59e0b;} .impact-critical{background:#ef4444;}
    /* ── نقطة التغيير 3: badge للحالة في عمود المخاطر ── */
    .status-dot { display:inline-block;width:7px;height:7px;border-radius:50%;flex-shrink:0; }
</style>

{{-- ══ JS: نسخة من الكتاب مع تغيير 3 نقاط فقط ══ --}}
<script>
(function() {
    // ── نقطة التغيير 1: الـ API endpoints ──
    const API = {
        categories:   `{{ url('app/risk/registry/tree/active/categories') }}`,
        subCategories:(catId)    => `{{ url('app/risk/registry/tree/active/sub-categories') }}/${catId}`,
        risksBySub:   (subCatId) => `{{ url('app/risk/registry/tree/active/risks-by-sub-category') }}/${subCatId}`,
        riskDetail:   (id)       => `{{ url('app/risk/registry/tree/active/risk') }}/${id}`,
        // ── نقطة التغيير 2: روابط الإجراءات ──
        editRisk:     (id)       => `{{ url('app/risk') }}/${id}/update`,
        showRisk:     (id)       => `{{ url('app/risk') }}/${id}/detail`,
    };

    // ── نقطة التغيير 3: ألوان الحالة ──
    const STATUS_COLORS = {
        draft:'#6b7280', pending_approval:'#0891b2', approved:'#059669',
        active:'#2563eb', in_progress:'#d97706', closed:'#374151',
        rejected:'#dc2626', escalated:'#9333ea',
    };
    const STATUS_LABELS = {
        draft:'مسودة', pending_approval:'بانتظار الاعتماد', approved:'معتمد',
        active:'نشط', in_progress:'قيد المعالجة', closed:'مغلق',
        rejected:'مرفوض', escalated:'مصعّد',
    };

    const state             = { categoryId: null };
    const selectedSubCatIds = new Set();
    const selectedRiskIds   = new Set();
    const riskDetailCache   = new Map();
    let activePops          = [];

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

    // ── Column 1 ──
    async function loadCategories() {
        const cats = await fetchJson(API.categories);
        const container = el('active-categories-list');
        container.innerHTML = '';
        if (!cats.length) {
            container.innerHTML = '<div class="text-muted small text-center py-4">لا توجد مخاطر فعلية بعد — فعّل مخاطر من السجل المرجعي</div>';
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
    }

    async function selectCategory(id, node) {
        state.categoryId = id;
        selectedSubCatIds.clear();
        document.querySelectorAll('.cat-nav-item').forEach(n => n.classList.remove('selected'));
        node.classList.add('selected');
        el('active-subcategories-list').innerHTML = '<div class="text-center py-2"><div class="spinner-border spinner-border-sm"></div></div>';
        el('active-risks-tree').innerHTML = '<div class="text-muted small text-center py-4">اختر فئة فرعية</div>';
        el('active-risk-count').textContent = '0';
        hideDetailPanel();
        const subs = await fetchJson(API.subCategories(id));
        renderSubCategories(subs);
    }

    // ── Column 2 ──
    function renderSubCategories(subs) {
        const container = el('active-subcategories-list');
        container.innerHTML = '';
        el('active-subcat-count').textContent = subs.length;
        if (!subs.length) { container.innerHTML = '<div class="text-muted small text-center py-4">لا توجد فئات فرعية</div>'; return; }
        subs.forEach(sub => {
            const uid  = 'act-sc-' + sub.id;
            const item = document.createElement('div');
            item.className = 'subcat-check-item';
            item.dataset.subId = sub.id;
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

    window.activeToggleSelectAllSubCats = function() {
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
        selectedRiskIds.clear(); riskDetailCache.clear();
        document.querySelectorAll('.risk-cb').forEach(cb => cb.checked = false);
        document.querySelectorAll('.risk-item.selected').forEach(n => n.classList.remove('selected'));
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
        if (!risks.length) { container.innerHTML = '<div class="text-muted small text-center py-4">لا توجد مخاطر في الفرعيات المحددة</div>'; return; }
        risks.forEach(r => {
            const item = document.createElement('div');
            item.className = 'risk-item';
            item.dataset.riskId = r.id;
            const scoreClass = r.risk_score >= 15 ? 'score-high' : (r.risk_score >= 7 ? 'score-med' : 'score-low');
            // ── نقطة التغيير 3: نقطة ملونة للحالة في قائمة المخاطر ──
            const statusColor = STATUS_COLORS[r.status] || '#6b7280';
            item.innerHTML = `
                <input type="checkbox" class="risk-cb form-check-input" value="${r.id}"
                       style="cursor:pointer;flex-shrink:0;" onclick="event.stopPropagation();">
                <span class="score ${scoreClass}">${r.risk_score || 0}</span>
                <span class="risk-title flex-grow-1" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${r.title}</span>
                <span class="status-dot" style="background:${statusColor};" title="${STATUS_LABELS[r.status]||r.status}"></span>`;
            item.querySelector('.risk-cb').addEventListener('change', e => {
                const id = parseInt(e.target.value);
                if (e.target.checked) { selectedRiskIds.add(id); item.classList.add('selected'); }
                else { selectedRiskIds.delete(id); item.classList.remove('selected'); }
                refreshDetailPanel();
            });
            item.addEventListener('click', ev => {
                if (ev.target.classList.contains('risk-cb')) return;
                const cb = item.querySelector('.risk-cb');
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
        const phaseOrder = ['proactive','operational','response'];
        const dash = `<span style="color:var(--text-muted)">—</span>`;

        if (single) {
            const sc = r0.risk_score >= 15 ? 'score-high' : (r0.risk_score >= 7 ? 'score-med' : 'score-low');
            const statusColor = STATUS_COLORS[r0.status] || '#6b7280';
            const statusLabel = STATUS_LABELS[r0.status] || r0.status || '';
            // ── نقطة التغيير 2+3: زر "تعديل" + الحالة + الوحدة التنظيمية ──
            headerEl.innerHTML = `
                <div class="score-big ${sc}">${r0.risk_score || 0}</div>
                <div class="flex-grow-1 overflow-hidden">
                    <div class="detail-risk-title">${r0.title}</div>
                    <div class="detail-risk-meta">
                        ${r0.code ? `<span class="badge bg-secondary me-1" style="font-family:monospace;">${r0.code}</span>` : ''}
                        <span class="me-1">${r0.category || ''}</span>
                        ${r0.sub_category ? `<span style="opacity:.5">›</span> <span class="ms-1">${r0.sub_category}</span>` : ''}
                        · الشدة <strong>${r0.severity}/5</strong> · الاحتمالية <strong>${r0.likelihood}/5</strong>
                    </div>
                    <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                        <span class="badge" style="background:${statusColor};font-size:.72rem;">${statusLabel}</span>
                        ${r0.organization_unit ? `<span style="font-size:.78rem;color:var(--text-muted);"><i class="bi bi-building me-1"></i>${r0.organization_unit}</span>` : ''}
                        ${r0.scope_type === 'general' ? `<span class="badge bg-secondary" style="font-size:.68rem;">عام للمؤسسة</span>` : ''}
                    </div>
                    ${r0.description ? `<div class="mt-1" style="font-size:.82rem;color:var(--text-muted);line-height:1.5;">${r0.description}</div>` : ''}
                </div>
                <div class="d-flex flex-column gap-1 ms-2 flex-shrink-0">
                    <a href="${API.showRisk(r0.id)}" class="btn btn-sm btn-outline-primary"
                       style="font-size:.72rem;white-space:nowrap;">
                        <i class="bi bi-eye me-1"></i>تفاصيل
                    </a>
                    <a href="${API.editRisk(r0.id)}" class="btn btn-sm btn-outline-secondary"
                       style="font-size:.72rem;text-align:center;">
                        <i class="bi bi-pencil me-1"></i>تعديل
                    </a>
                </div>`;
        } else {
            headerEl.innerHTML = `
                <span class="badge bg-danger fs-6 me-2">${risks.length}</span>
                <span class="fw-semibold flex-grow-1">مخاطر محددة — مقارنة الأطوار</span>`;
        }

        // ── Phase table (نفس كود الكتاب) ──
        function buildPhaseRows(r, showRiskCol) {
            const phasesById = {};
            (r.phases || []).forEach(p => { phasesById[p.phase] = p; });
            const sc = r.risk_score >= 15 ? 'score-high' : (r.risk_score >= 7 ? 'score-med' : 'score-low');
            const statusColor = STATUS_COLORS[r.status] || '#6b7280';
            let rows = '';
            phaseOrder.forEach((key, idx) => {
                const st = phaseStyles[key];
                const p  = phasesById[key];
                let causesHtml = dash;
                if (p?.causes?.length) {
                    const lh = `<ul class='mb-0 ps-3'>${p.causes.map(c=>`<li>${escAttr(c.name)}</li>`).join('')}</ul>`;
                    causesHtml = popTrigger(`الأسباب (${st.label})`, lh, `<span class="badge-count">${p.causes.length} سبب</span>`);
                }
                let groupsHtml = dash;
                if (p?.affected_groups?.length) {
                    const lh = `<ul class='mb-0 ps-3'>${p.affected_groups.map(g=>{
                        const imp = g.impact ? ` <span class="impact-tag impact-${escAttr(g.impact)}">${escAttr(g.impact)}</span>` : '';
                        return `<li>${escAttr(g.name)}${imp}</li>`;
                    }).join('')}</ul>`;
                    groupsHtml = popTrigger(`المتأثرون (${st.label})`, lh, `<span class="badge-count">${p.affected_groups.length} فئة</span>`);
                }
                const preventiveHtml = p?.preventive_action
                    ? popTrigger(`الإجراء الوقائي (${st.label})`, escAttr(p.preventive_action), `<span class="pop-preview">${truncWords(p.preventive_action,3)}</span>`) : dash;
                const correctiveHtml = p?.corrective_action
                    ? popTrigger(`الإجراء التصحيحي (${st.label})`, escAttr(p.corrective_action), `<span class="pop-preview">${truncWords(p.corrective_action,3)}</span>`) : dash;
                let responsibleHtml = dash;
                const orgUnit=p?.responsible_org_unit; const respUser=p?.responsible_user;
                if (orgUnit||respUser) {
                    responsibleHtml = popTrigger(`الجهة والمفوّض (${st.label})`,
                        `<b>الجهة:</b> ${escAttr(orgUnit||'—')}<br><b>المفوّض:</b> ${escAttr(respUser||'—')}`,
                        `<span class="pop-preview">${truncWords(orgUnit||respUser||'',2)}</span>`);
                }
                let riskCell = '';
                if (showRiskCol && idx === 0) {
                    riskCell = `<td rowspan="3" class="align-middle" style="vertical-align:middle!important;min-width:140px;max-width:180px;">
                        <div class="d-flex flex-column align-items-start gap-1">
                            <span class="score-big ${sc}" style="width:32px;height:32px;font-size:.8rem;">${r.risk_score||0}</span>
                            <strong style="font-size:.8rem;line-height:1.3;">${r.title}</strong>
                            <span class="badge" style="background:${statusColor};font-size:.65rem;">${STATUS_LABELS[r.status]||r.status||''}</span>
                            <a href="${API.editRisk(r.id)}" class="btn btn-sm btn-outline-secondary"
                               style="font-size:.65rem;padding:1px 6px;white-space:nowrap;">
                                <i class="bi bi-pencil"></i> تعديل
                            </a>
                        </div></td>`;
                }
                const borderTop = (showRiskCol && idx===0) ? 'border-top:3px solid var(--border-color);' : '';
                rows += `<tr style="background:${st.bg};${borderTop}">
                    ${riskCell}
                    <td class="text-nowrap align-top" style="width:110px;">
                        <span class="detail-phase-badge" style="background:${st.bg};color:${st.color};border:1px solid ${st.color};">
                            ${st.icon} ${st.label}
                        </span>
                        <div style="font-size:.6rem;color:var(--text-muted);margin-top:3px;">${st.hint}</div>
                    </td>
                    <td>${causesHtml}</td><td>${groupsHtml}</td>
                    <td>${correctiveHtml}</td><td>${preventiveHtml}</td>
                    <td>${responsibleHtml}</td>
                    ${idx===0 ? `<td rowspan="3" class="align-middle" style="vertical-align:middle!important;min-width:120px;">
                        ${r.benefit ? `<span style="font-size:.78rem;color:var(--text-muted);line-height:1.5;">${r.benefit}</span>` : dash}
                    </td>` : ''}
                </tr>`;
            });
            return rows;
        }

        const showRiskCol = !single;
        const allRows = risks.map(r => buildPhaseRows(r, showRiskCol)).join('');
        const riskColHeader = showRiskCol ? '<th style="min-width:140px;">الخطر</th>' : '';
        bodyEl.innerHTML = `<div class="table-responsive">
            <table class="detail-phase-table table table-bordered mb-0">
                <thead><tr>
                    ${riskColHeader}
                    <th>الطور</th><th>الأسباب</th><th>المتأثرون</th>
                    <th>الإجراء التصحيحي</th><th>الإجراء الوقائي</th>
                    <th>الجهة / المفوّض</th><th>الفائدة</th>
                </tr></thead>
                <tbody>${allRows}</tbody>
            </table></div>`;
        initPopovers(bodyEl);
    }

    window.activeBook = {
        toggleView() {
            const tv  = el('active-tree-view');
            const tb  = el('active-table-view');
            const lbl = el('active-view-toggle-label');
            if (tv.style.display === 'none') { tv.style.display=''; tb.style.display='none'; lbl.textContent='عرض الجدول'; }
            else { tv.style.display='none'; tb.style.display=''; lbl.textContent='عرض الشجرة'; }
        },
    };

    document.addEventListener('DOMContentLoaded', () => loadCategories().catch(console.error));
})();
</script>
@endsection
