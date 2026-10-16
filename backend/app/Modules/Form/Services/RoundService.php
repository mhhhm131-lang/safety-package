<?php

namespace App\Modules\Form\Services;

use App\Models\User;
use App\Modules\Form\Models\FormAssignment;
use App\Modules\Form\Models\FormRound;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Form\Support\NonMaterialPackage;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Risk\Support\RiskApproval;
use Illuminate\Support\Facades\DB;

/**
 * ٢٩-٤ (قرار ٨٨ على وثيقة التصميم §٤-٣): فتح الجولة واعتمادها.
 *   فتح جولة لإدارة (مسؤول السلامة والمناوب والمنسق — `form.send`؛ اللجنة ترى ولا تضغط — قرار ٨٧-٨):
 *   تكليف واحد لمدير الإدارة ببطاقة الإدارة. الموظفون لا يُكلَّفون بشيء؛ يصلهم الإقرار السري بعد الاعتماد (٢٩-٥).
 *   اعتماد المدير «البيانات صحيحة وأعتمدها»: بعد تعبئة البطاقة، بالاسم والوقت في السجل، ويُعلَم مسؤول السلامة.
 * الحارس: الوحدة من مبانيك (`InAccountBuildings` في المتحكّم)، والمعتمِد مدير هذه الوحدة بعينها.
 */
final class RoundService
{
    public function __construct(
        private readonly FormService $forms,
        private readonly FormNotifier $notifier,
    ) {}

    /** مدير الوحدة نفسها (أدوار المديرين الستة — قرار ٦٩) بحساب مفعّل؛ لا مدير ما فوقها. */
    public static function managerOf(OrganizationUnit $unit): ?UserProfile
    {
        return UserProfile::whereIn('role', RiskApproval::UNIT_MANAGERS)
            ->where('organization_unit_id', $unit->id)->where('is_active', true)
            ->orderBy('user_id')->first();
    }

    public static function isManagerOf(?User $user, int $unitId): bool
    {
        $p = $user?->profile;

        return $p !== null && $p->is_active && in_array($p->role, RiskApproval::UNIT_MANAGERS, true)
            && (int) $p->organization_unit_id === $unitId;
    }

    /** موظفو الإدارة وما تحتها بحساب مفعّل — رأس البطاقة يملؤه النظام. */
    public static function employeeCount(OrganizationUnit $unit): int
    {
        return UserProfile::whereIn('organization_unit_id', $unit->descendantIds())->where('is_active', true)->count();
    }

    public static function card(): ?FormTemplate
    {
        return FormTemplate::where('package_code', NonMaterialPackage::CODE)->where('is_active', true)->first();
    }

    /** @throws \InvalidArgumentException برسالة تُعرض كما هي */
    public function open(OrganizationUnit $unit, User $actor): FormRound
    {
        $card = self::card();
        if (!$card) {
            throw new \InvalidArgumentException('قالب «بطاقة الإدارة» غير موجود أو موقوف.');
        }
        if (FormRound::withoutGlobalScope('building')->where('organization_unit_id', $unit->id)->where('state', '!=', FormRound::STATE_CLOSED)->exists()) {
            throw new \InvalidArgumentException('لإدارة «'.$unit->name.'» جولة مفتوحة — تُقفل أولاً.');
        }
        $manager = self::managerOf($unit);
        if (!$manager) {
            throw new \InvalidArgumentException('إدارة «'.$unit->name.'» بلا مدير بحساب مفعّل في الهيكل.');
        }

        return DB::transaction(function () use ($unit, $actor, $card, $manager) {
            $no = (int) FormRound::withoutGlobalScope('building')->where('organization_unit_id', $unit->id)->max('round_no') + 1;
            $round = new FormRound([
                'organization_unit_id' => $unit->id,
                'building_id'          => $unit->buildingId(),
                'round_no'             => $no,
                'state'                => FormRound::STATE_SENT,
                'opened_by_id'         => $actor->id,
            ]);
            $round->addLog('opened', $actor, 'المدير المكلَّف: '.($manager->user?->name ?? '—'));
            $round->save();

            $this->forms->assignToUsers($card, [(int) $manager->user_id], null, $actor->id, 'round', (string) $round->id, $round->id);

            return $round;
        });
    }

    /** @throws \InvalidArgumentException برسالة تُعرض كما هي (صلاحية المدير تُفحص قبلها في السياسة) */
    public function approve(FormRound $round, User $actor): void
    {
        if ($round->state !== FormRound::STATE_SENT) {
            throw new \InvalidArgumentException('الجولة ليست لدى الإدارة — حالتها: '.$round->getStateLabel().'.');
        }
        $a = $round->cardAssignment();
        if (!$a || $a->status !== FormAssignment::STATUS_COMPLETED) {
            throw new \InvalidArgumentException('البطاقة لم تُعبَّأ بعد — عبّئها ثم اعتمد.');
        }

        $round->state = FormRound::STATE_DONE;
        $round->approved_by_id = $actor->id;
        $round->approved_at = now();
        $round->addLog('approved', $actor);
        $round->save();

        $this->notifier->roundApproved($round, $actor);
    }
}
