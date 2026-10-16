<?php

namespace App\Modules\Form\Controllers;

use App\Core\Rules\InAccountBuildings;
use App\Http\Controllers\Controller;
use App\Modules\Form\Models\FormRound;
use App\Modules\Form\Services\FormVisibilityService;
use App\Modules\Form\Services\RoundService;
use App\Modules\Governance\Models\OrganizationUnit;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ٢٩-٤ (قرار ٨٨): جولات بطاقة الإدارة — القائمة، الفتح لإدارة، صفحة الجولة، اعتماد المدير.
 * القائمة والصفحة بقاعدة الرؤية (٢٩-٠) والمبنى (`BelongsToBuilding` يردّ 403 قبل أي كود)؛ الفتح بحارس «الوحدة من مبانيك».
 */
class FormRoundController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RoundService $rounds,
        private readonly FormVisibilityService $visibility,
    ) {}

    public function index()
    {
        $q = FormRound::with(['organizationUnit', 'openedBy', 'approvedBy'])->latest('id');
        $this->visibility->apply($q, Auth::user());

        return view('modules.forms.rounds.index', [
            'rounds'  => $q->paginate(30),
            'units'   => OrganizationUnit::treeOptions(),
            'canOpen' => Auth::user()->can('open', FormRound::class),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('open', FormRound::class);
        $data = $request->validate(
            ['unit_id' => InAccountBuildings::unitRules('required')], // حارس الكتابة: الوحدة من مبانيك
            [], ['unit_id' => 'الإدارة'],
        );

        try {
            $round = $this->rounds->open(OrganizationUnit::findOrFail((int) $data['unit_id']), Auth::user());
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('err', $e->getMessage());
        }

        return redirect()->route('forms.rounds.show', $round)
            ->with('ok', 'فُتحت الجولة '.$round->round_no.' وكُلّف مدير الإدارة ببطاقة الإدارة وأُشعر.');
    }

    public function show(FormRound $round)
    {
        $this->authorize('view', $round);
        $round->load(['organizationUnit', 'openedBy', 'approvedBy']);
        $assignment = $round->cardAssignment();
        $ack = \App\Modules\Form\Models\FormTemplate::where('package_code', \App\Modules\Form\Support\NonMaterialPackage::ackCode($round->id))->first(); // ٢٩-٥

        return view('modules.forms.rounds.show', [
            'round'      => $round,
            'assignment' => $assignment,
            'employees'  => $round->organizationUnit ? RoundService::employeeCount($round->organizationUnit) : 0,
            'isManager'  => RoundService::isManagerOf(Auth::user(), (int) $round->organization_unit_id),
            'ack'        => $ack,
            'ackStats'   => $ack?->assignmentStats(Auth::user()), // العدد لا الأسماء
        ]);
    }

    public function approve(FormRound $round)
    {
        $this->authorize('approve', $round);

        try {
            $this->rounds->approve($round, Auth::user());
        } catch (\InvalidArgumentException $e) {
            return back()->with('err', $e->getMessage());
        }

        return redirect()->route('forms.rounds.show', $round)
            ->with('ok', 'اعتُمدت البطاقة باسمك ووقتك. تصل بنودها التي قلت فيها «نعم» موظفي إدارتك سرياً.');
    }
}
