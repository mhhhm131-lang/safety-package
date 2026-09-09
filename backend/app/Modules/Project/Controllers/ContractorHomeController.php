<?php

namespace App\Modules\Project\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Permit\Models\Permit;
use App\Modules\Project\Services\ContractorPreQualificationService;
use Illuminate\View\View;

/**
 * بوابة المقاول بحساب (المرحلة ٦): ملف الطرف، مشاريعه وحالة تأهيله فيها، عماله، مستنداته، درجة ثقته.
 * أُضيف في ٦-ب: تصاريح الطرف — ما ينتظر إجراءً منه أولاً (رفع أدلة البنود)، وما هو نشط.
 */
class ContractorHomeController extends Controller
{
    public function index(ContractorPreQualificationService $qual): View
    {
        $party = auth()->user()->externalParty()
            ->with(['profile', 'projectAssignments.project.place', 'workers.trade', 'documents'])
            ->firstOrFail();

        $evaluation = $qual->evaluate($party, $party->projectAssignments->first());
        $workersByStatus = $party->workers->countBy('status');
        $expiring = $party->documents->filter(fn ($d) => $d->is_expired || $d->is_expiring_soon);

        $permits = Permit::where('external_party_id', $party->id)
            ->with(['type', 'place', 'requirements'])
            ->latest('id')
            ->limit(20)
            ->get();

        return view('modules.contractor_profile.home', compact(
            'party', 'evaluation', 'workersByStatus', 'expiring', 'permits'
        ));
    }
}
