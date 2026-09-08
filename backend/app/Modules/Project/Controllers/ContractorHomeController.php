<?php

namespace App\Modules\Project\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Project\Services\ContractorPreQualificationService;
use Illuminate\View\View;

/**
 * بوابة المقاول بحساب (المرحلة ٦): ملف الطرف، مشاريعه وحالة تأهيله فيها، عماله، مستنداته، درجة ثقته.
 * تبويب التصاريح يُضاف في ٦-ب (كان OHSMS يعرض التصاريح فقط عبر Permit/ContractorController).
 */
class ContractorHomeController extends Controller
{
    public function index(ContractorPreQualificationService $qual): View
    {
        $party = auth()->user()->externalParty()->with(['profile', 'projectAssignments.project.place', 'workers.trade', 'documents'])->firstOrFail();
        $evaluation = $qual->evaluate($party, $party->projectAssignments->first());
        $workersByStatus = $party->workers->countBy('status');
        $expiring = $party->documents->filter(fn ($d) => $d->is_expired || $d->is_expiring_soon);
        return view('modules.contractor_profile.home', compact('party', 'evaluation', 'workersByStatus', 'expiring'));
    }
}
