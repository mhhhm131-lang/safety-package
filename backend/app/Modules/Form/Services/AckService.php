<?php

namespace App\Modules\Form\Services;

use App\Models\User;
use App\Modules\Form\Models\FormField;
use App\Modules\Form\Models\FormRound;
use App\Modules\Form\Models\FormSubmission;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Form\Support\NonMaterialPackage;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Support\Facades\DB;

/**
 * ٢٩-٥ (قرار ٨٨، بكلمته «بعد تأكيد المدير يرسله النظام لجميع موظفي الإدارة»): توليد الإقرار السري وإرساله آلياً عند الاعتماد.
 *   قالب لكل جولة (`ack` + رقمها؛ سري؛ على الإدارة) فيه حقل «سُلّم رباعي» لكل بند قالت فيه الإدارة «نعم» في مرحلة واحدة على الأقل —
 *   بند «لا» في مراحله كلها لا يُعرض — يحمل ما قالته الإدارة (الادعاء ودليله ووثيقتها) ثم العبارات الأربع بالمتكلم.
 *   يُكلَّف به موظفو الإدارة وما تحتها ممن له حساب مفعّل، دون المدير المعتمِد. الحالة «الإقرارات جارية».
 *   لا إقرار إن لم تدّعِ الإدارة شيئاً أو لم يكن موظفون بحساب — ويُكتب السبب في سجل الجولة وتبقى «اعتمدها المدير».
 */
final class AckService
{
    public function __construct(
        private readonly FormService $forms,
    ) {}

    /** @throws \InvalidArgumentException إن لم تكن للبطاقة تعبئة في الجولة */
    public function generate(FormRound $round, ?User $by): ?FormTemplate
    {
        $card = $round->cardAssignment();
        $submission = $card
            ? FormSubmission::withoutGlobalScope('building')->where('round_id', $round->id)->where('form_id', $card->form_id)->with('answers.field')->latest('id')->first()
            : null;
        if (!$submission) {
            throw new \InvalidArgumentException('لا تعبئة لبطاقة الإدارة في هذه الجولة.');
        }

        $claims = [];
        foreach ($submission->answers as $a) {
            $f = $a->field;
            if (!$f || !$f->isClaim()) continue;
            $d = $f->claimDef();
            $c = $a->claimValue();
            $claims[$d['item']][$d['stage']] = [
                'claim'     => (string) ($c['claim'] ?? ''),
                'evidence'  => (string) ($c['evidence'] ?? ''),
                'answer_id' => $a->hasFile() ? $a->id : null,
                'form_id'   => $a->hasFile() ? (int) $submission->form_id : null,
                'file_name' => $a->hasFile() ? $a->file_name : null,
            ];
        }
        $items = array_values(array_filter(NonMaterialPackage::ITEMS, fn ($i) => collect($claims[$i['key']] ?? [])->contains(fn ($c) => $c['claim'] === FormField::CLAIM_YES)));
        if ($items === []) {
            $round->addLog('acks_none', $by, 'الإدارة لم تدّعِ «نعم» في أي بند — لا إقرار يُرسل');
            $round->save();
            return null;
        }

        $unit = $round->organizationUnit;
        $employees = UserProfile::whereIn('organization_unit_id', $unit->descendantIds())->where('is_active', true)
            ->where('user_id', '!=', (int) $round->approved_by_id)
            ->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        if ($employees === []) {
            $round->addLog('acks_none', $by, 'لا موظفين بحساب مفعّل في الإدارة غير مديرها — لا إقرار يُرسل');
            $round->save();
            return null;
        }

        return DB::transaction(function () use ($round, $by, $unit, $items, $claims, $employees) {
            $template = FormTemplate::create([
                'title'                => NonMaterialPackage::ACK_TITLE.' — '.$unit->name.' — الجولة '.$round->round_no,
                'description'          => 'إدارتك قالت «نعم» في هذه البنود وأرَتنا ما عندها. أربع عبارات لكل بند: نعم أو لا، أو «لا ينطبق على عملي». سري افتراضاً.',
                'intro'                => NonMaterialPackage::ACK_INTRO,
                'form_type'            => FormTemplate::TYPE_SURVEY,
                'package_code'         => NonMaterialPackage::ackCode($round->id),
                'fills_as'             => FormTemplate::FILLS_USER,
                'allows_anonymous'     => true,
                'organization_unit_id' => $unit->id,
                'is_active'            => true,
                'created_by_id'        => $by?->id,
            ]);
            $order = 0;
            foreach ($items as $item) {
                FormField::create([
                    'form_id'     => $template->id,
                    'label'       => $item['title'],
                    'help_text'   => $item['why'],
                    'field_type'  => FormField::TYPE_LADDER,
                    'is_required' => true,
                    'order'       => ++$order,
                    'options'     => ['item' => $item['key'], 'claims' => $claims[$item['key']]],
                ]);
            }

            $n = $this->forms->assignToUsers($template, $employees, null, $by?->id, 'round', (string) $round->id, $round->id);

            $round->state = FormRound::STATE_ACK;
            $round->addLog('acks_sent', $by, "أُرسل الإقرار السري آلياً إلى {$n} موظفاً — ".count($items).' من البنود السبعة');
            $round->save();

            return $template;
        });
    }
}
