<?php

namespace App\Modules\Store\Controllers;

use App\Core\Permissions\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Modules\Store\Inbox\InspectionReportTasks;
use App\Modules\Store\Models\InstituteDocument;
use Illuminate\Http\Request;

/**
 * قرار المستخدم ٢٠٢٦-٠٩-١٣: «نماذج الفحص» من «أريد أن…» — النماذج العشرة بمكانها، وآخر جولة، وعدد البلاغات المفتوحة،
 * وكل نموذج بضغطة. قراءة فقط من وثائق النماذج (institute_documents)؛ النماذج نفسها لا تُمس.
 */
class InspectionsController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(PermissionRegistry::uiRole($request->user()->role()), 403);
        $docs = InstituteDocument::whereIn('key', array_column(InspectionReportTasks::FORMS, 'key'))->get()->keyBy('key');
        $rows = [];
        foreach (InspectionReportTasks::FORMS as $f) {
            $doc = $docs->get($f['key']);
            $data = $doc ? json_decode($doc->data, true) : null;
            $reports = is_array($data) ? (array) ($data['reports'] ?? []) : [];
            $rounds = is_array($data) ? (array) ($data['rounds'] ?? []) : [];
            $open = count(array_filter($reports, fn ($r) => is_array($r) && !$this->closed($r)));
            $last = null;
            foreach ($rounds as $r) { if (is_array($r) && !empty($r['date'])) $last = $r['date']; }
            $rows[] = $f + ['exists' => (bool) $doc, 'reports' => count($reports), 'open' => $open, 'rounds' => count($rounds), 'last' => $last, 'label' => str_contains($f['key'], 'fire') ? 'الحريق (١٢ نظاماً)' : (str_contains($f['key'], 'center') ? 'الجاهزية (٧ أنظمة)' : 'نموذج الفحص')];
        }
        return view('modules.store.inspections', ['rows' => $rows]);
    }

    private function closed(array $r): bool
    {
        foreach ((array) ($r['levels'] ?? []) as $l) if (is_array($l) && empty($l['up']) && empty($l['back'])) return true;
        return false;
    }
}
