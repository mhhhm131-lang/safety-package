<?php

namespace App\Modules\Report\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Governance\Models\Place;
use App\Modules\Report\Services\DashboardService;
use App\Modules\Report\Services\KpiService;
use App\Modules\Report\Services\ReportScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * لوحة التقارير وتقريرا البلاغات والمخاطر.
 *
 * **تصحيح لسلوك OHSMS:** `dashboard()` هناك ملفوفة بـ`try/catch (\Throwable)` تُرجع
 * أصفاراً عند أي خطأ. المدير العام يقرأ «٠ بلاغ» ويظنها حقيقة، والخلل لا يظهر في السجل.
 * لا التقاط هنا: الخطأ يظهر خطأً ويُصلَح.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly KpiService $kpi,
    ) {}

    private function scope(Request $request): ReportScope
    {
        return ReportScope::fromRequest(
            $request->query('from'),
            $request->query('to'),
            (int) $request->query('place_id') ?: null,
            Auth::id(),
        );
    }

    public function dashboard(Request $request)
    {
        $scope = $this->scope($request);

        return view('modules.reports.dashboard', [
            'data'   => $this->dashboard->overview($scope),
            'kpi'    => $this->kpi->all($scope),
            'scope'  => $scope,
            'places' => Place::orderBy('sort')->get(['id', 'code', 'name']),
        ]);
    }

    public function incidents(Request $request)
    {
        $scope = $this->scope($request);

        return view('modules.reports.incidents', [
            'data'   => $this->dashboard->incidentReport($scope),
            'scope'  => $scope,
            'places' => Place::orderBy('sort')->get(['id', 'code', 'name']),
        ]);
    }

    public function risks(Request $request)
    {
        $scope = $this->scope($request);

        return view('modules.reports.risks', [
            'data'   => $this->dashboard->riskReport($scope),
            'scope'  => $scope,
            'places' => Place::orderBy('sort')->get(['id', 'code', 'name']),
        ]);
    }

    /** تصدير أرقام اللوحة CSV — بعلامة ترتيب البايت حتى تفتح Excel العربية صحيحة. */
    public function export(Request $request): StreamedResponse
    {
        $scope = $this->scope($request);
        $data  = $this->dashboard->overview($scope);
        $kpi   = $this->kpi->all($scope);

        $rows = [
            ['المدة', $scope->label()],
            ['عدد الأيام', $scope->days()],
            [],
            ['المؤشر', 'القيمة'],
            ['متوسط وصول البلاغ إلى الفني (دقيقة)', $this->cell($data['response']['incident']['avg_minutes'])],
            ['أطول وصول للفني (دقيقة)', $this->cell($data['response']['incident']['max_minutes'])],
            ['بلاغات لم تصل الفني بعد', $data['response']['incident']['pending']],
            ['متوسط الإقرار بالحالة الطارئة (دقيقة)', $this->cell($data['response']['emergency']['avg_minutes'])],
            ['حالات طارئة بلا إقرار', $data['response']['emergency']['unacknowledged']],
            ['نسبة إغلاق البلاغات %', $this->cell($kpi['closure_rate'])],
            ['متوسط أيام الإغلاق', $this->cell($kpi['avg_closure_days'])],
            ['نسبة التصعيد %', $this->cell($kpi['escalation_rate'])],
            ['متوسط درجة الخطر النشط', $this->cell($kpi['avg_risk_score'])],
            ['نسبة تفعيل التصاريح %', $this->cell($kpi['permit_activation'])],
            ['نسبة إنهاء الحالات الطارئة %', $this->cell($kpi['emergency_end_rate'])],
            [],
            ['المكان', 'بلاغات', 'حالات طارئة', 'مخاطر حرجة', 'تصاريح نشطة'],
        ];

        foreach ($data['by_place'] as $place) {
            $rows[] = [$place['code'].' — '.$place['name'], $place['incidents'], $place['emergency'],
                $place['critical_risks'], $place['active_permits']];
        }

        $name = 'تقرير-'.$scope->from->format('Ymd').'-'.$scope->to->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** «لا بيانات» بدل فراغ يُقرأ صفراً. */
    private function cell(int|float|null $value): string
    {
        return $value === null ? 'لا بيانات' : (string) $value;
    }
}
