<?php

namespace App\Modules\Form\Services;

use App\Models\User;
use App\Modules\Form\Models\FormAnswer;
use App\Modules\Form\Models\FormAssignment;
use App\Modules\Form\Models\FormField;
use App\Modules\Form\Models\FormSubmission;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Form\Support\OwnerScope;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Risk\Models\Risk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * عمليات النماذج: القالب وحقوله، التكليف، التعبئة، النتائج.
 *
 * **الإصلاح الجوهري (٥-٨):** التعبئة في OHSMS كانت لا تعمل إطلاقاً — الشاشة ترسل `fields[]`
 * والخدمة تقرأ `answers[]`، ثم يكتب النموذج `submitted_at` بلا عمود له. هنا مفتاح واحد
 * (`fields`) وعمود حقيقي، وكل تعبئة تتحقق من التكليف وتُغلقه.
 *
 * ٢٩-٠ (قرار ٨٧): النتائج والمتابعة والتصدير والتذكير تمرّ بقاعدة الرؤية `FormVisibilityService` حين يُعطى الحساب القارئ.
 */
class FormService
{
    public function __construct(
        private readonly FormNotifier $notifier,
        private readonly FormVisibilityService $visibility,
    ) {}

    // ── القالب وحقوله ──

    public function createForm(array $data, ?int $userId = null): FormTemplate
    {
        return FormTemplate::create($data + ['created_by_id' => $userId]);
    }

    public function addField(FormTemplate $form, array $data): FormField
    {
        $data['form_id'] = $form->id;
        $data['order'] ??= ((int) FormField::where('form_id', $form->id)->max('order')) + 1;
        $data['options'] = $this->normalizeOptions($data);
        if (($data['field_type'] ?? null) === FormField::TYPE_SECTION) $data['is_required'] = false; // ٢٩-١: القسم بلا إجابة

        return FormField::create($data);
    }

    public function updateField(FormField $field, array $data): FormField
    {
        $data['options'] = $this->normalizeOptions($data + ['field_type' => $data['field_type'] ?? $field->field_type]);
        $field->update($data);

        return $field->fresh();
    }

    public function reorderFields(FormTemplate $form, array $fieldIds): void
    {
        foreach (array_values($fieldIds) as $i => $fieldId) {
            FormField::where('id', $fieldId)->where('form_id', $form->id)->update(['order' => $i + 1]);
        }
    }

    /**
     * الخيارات تصل سطراً لكل خيار من الشاشة؛ الأنواع التي لا تحتاجها تُفرَّغ.
     * ٢٩-١: الجدول تعريفه من سطور الشاشة (`FormField::parseTableText`) أو بنيةً جاهزة من البذرة (`{columns, rows}`).
     */
    private function normalizeOptions(array $data): ?array
    {
        $type = $data['field_type'] ?? null;
        if (!in_array($type, FormField::TYPES_WITH_OPTIONS, true)) {
            return null;
        }
        $raw = $data['options'] ?? null;
        if ($type === FormField::TYPE_TABLE) {
            $def = is_array($raw) ? FormField::normalizeTableDef($raw) : FormField::parseTableText((string) $raw);

            return $def['columns'] === [] ? null : $def;
        }
        if ($type === FormField::TYPE_CLAIM) {
            // قرار ٨٨: من الشاشة سطر التلميح وحده؛ من البذرة البنية كاملة (البند، المرحلة، الإرفاق، من السجل)
            if (is_array($raw)) {
                return ['item' => (string) ($raw['item'] ?? ''), 'stage' => (string) ($raw['stage'] ?? ''), 'hint' => trim((string) ($raw['hint'] ?? '')),
                    'attach' => (bool) ($raw['attach'] ?? false), 'from_register' => (bool) ($raw['from_register'] ?? false)];
            }

            return ['item' => '', 'stage' => '', 'hint' => trim((string) $raw), 'attach' => false, 'from_register' => false];
        }
        if (is_array($raw)) {
            $list = $raw;
        } else {
            $list = preg_split('/\r\n|\r|\n/', (string) $raw) ?: [];
        }
        $list = array_values(array_filter(array_map('trim', $list), fn ($v) => $v !== ''));

        return $list ?: null;
    }

    // ── التكليف ──

    /**
     * تكليف مجموعة مستخدمين. غير تكراري: من كُلّف سابقاً بالنموذج نفسه لا يُكرَّر تكليفه،
     * وتُحدَّث مهلته إن تغيّرت. يعيد عدد التكليفات الجديدة.
     *
     * @param array<int> $userIds
     */
    public function assignToUsers(
        FormTemplate $form,
        array $userIds,
        ?string $dueDate = null,
        ?int $actorId = null,
        string $source = 'user',
        ?string $sourceValue = null,
    ): int {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($userIds === []) {
            return 0;
        }

        // ٢٩-٠: التكليف القائم يُسأل عنه في المعهد كله لا في مباني المرسل — وإلا تكرّر التكليف وسقط القيد الفريد
        $existing = FormAssignment::withoutGlobalScope('building')->where('form_id', $form->id)
            ->whereIn('assigned_to_id', $userIds)
            ->pluck('assigned_to_id')
            ->all();

        $created = [];
        DB::transaction(function () use ($form, $userIds, $existing, $dueDate, $actorId, $source, $sourceValue, &$created) {
            foreach ($userIds as $userId) {
                if (in_array($userId, $existing, true)) {
                    // تكليف قائم: تُحدَّث المهلة فقط، ولا يُنشأ تكليف ثانٍ
                    if ($dueDate) {
                        FormAssignment::withoutGlobalScope('building')->where('form_id', $form->id)
                            ->where('assigned_to_id', $userId)
                            ->where('status', '!=', FormAssignment::STATUS_COMPLETED)
                            ->update(['due_date' => $dueDate]);
                    }
                    continue;
                }
                $created[] = FormAssignment::create([
                    'form_id'        => $form->id,
                    'assigned_to_id' => $userId,
                    'due_date'       => $dueDate,
                    'source'         => $source,
                    'source_value'   => $sourceValue,
                    'assigned_by_id' => $actorId,
                    'created_at'     => now(),
                ]);
            }
        });

        foreach ($created as $assignment) {
            $this->notifier->assigned($assignment);
        }

        return count($created);
    }

    /** تكليف كل من يحمل دوراً (مفعّلاً). */
    public function assignToRole(FormTemplate $form, string $role, ?string $dueDate = null, ?int $actorId = null): int
    {
        $userIds = UserProfile::where('role', $role)->where('is_active', true)->pluck('user_id')->all();

        return $this->assignToUsers($form, $userIds, $dueDate, $actorId, 'role', $role);
    }

    /** تكليف كل من في وحدة تنظيمية وما تحتها. */
    public function assignToUnit(FormTemplate $form, int $unitId, ?string $dueDate = null, ?int $actorId = null): int
    {
        $unitIds = OrganizationUnit::descendantIdsOf($unitId);
        $userIds = UserProfile::whereIn('organization_unit_id', $unitIds)->where('is_active', true)->pluck('user_id')->all();

        return $this->assignToUsers($form, $userIds, $dueDate, $actorId, 'unit', (string) $unitId);
    }

    /** تكليف كل من مكانه هو المكان المحدد. */
    public function assignToPlace(FormTemplate $form, int $placeId, ?string $dueDate = null, ?int $actorId = null): int
    {
        $userIds = UserProfile::where('place_id', $placeId)->where('is_active', true)->pluck('user_id')->all();

        return $this->assignToUsers($form, $userIds, $dueDate, $actorId, 'place', (string) $placeId);
    }

    /** تذكير مكلَّف — **يُرسل فعلاً** (في OHSMS كان يختم الوقت ولا يرسل). */
    public function remind(FormAssignment $assignment): bool
    {
        if (!$assignment->isOpen()) {
            return false;
        }
        $assignment->update(['reminded_at' => now()]);
        $this->notifier->reminded($assignment);

        return true;
    }

    /** تذكير كل من لم يعبّئ — ممن يراهم المذكِّر (٢٩-٠: قاعدة الرؤية). يعيد عددهم. */
    public function remindAllPending(FormTemplate $form, ?User $actor = null): int
    {
        $count = 0;
        $q = $form->assignments()->whereIn('status', [FormAssignment::STATUS_PENDING, FormAssignment::STATUS_OVERDUE]);
        if ($actor) $this->visibility->apply($q, $actor);
        foreach ($q->get() as $a) {
            $count += $this->remind($a) ? 1 : 0;
        }

        return $count;
    }

    // ── التعبئة ──

    /**
     * حفظ تعبئة. `$values` مفاتيحها معرّفات الحقول (`fields[<id>]` من الشاشة — مفتاح واحد لا اثنان).
     * `$files` للصور، و`$signatures` لبيانات التوقيع المرسوم (data URL).
     *
     * ٢٩-٢ (قرار ٨٨ §٤) `$anonymous`: تعبئة سرية — تُقبل في قالب يقبل السرية وحده. صندوق الإجابات بلا شخص ولا تكليف
     * ولا جهاز، والتاريخ باليوم لا بالساعة حتى لا يُطابَق بوقت إكمال التكليف؛ الإدارة والمبنى يُقرآن من حساب المعبِّئ قبل قطع النسبة.
     * صندوق التكليف يُعلَّم «مكتملاً» بوقته كما في كل تعبئة — بلا رابط إلى الإجابة.
     *
     * @param array<int, mixed>        $values
     * @param array<int, UploadedFile> $files
     * @param array<int, string>       $signatures
     */
    public function submit(
        FormTemplate $form,
        int $userId,
        array $values,
        array $files = [],
        array $signatures = [],
        ?string $ip = null,
        bool $anonymous = false,
    ): FormSubmission {
        $fields = $form->fields()->get()->keyBy('id');
        $anonymous = $anonymous && $form->allows_anonymous;

        return DB::transaction(function () use ($form, $userId, $values, $files, $signatures, $ip, $fields, $anonymous) {
            $assignment = FormAssignment::where('form_id', $form->id)
                ->where('assigned_to_id', $userId)
                ->first();

            if ($anonymous) {
                $scope = OwnerScope::of($userId);
                $day = now()->startOfDay();
                $submission = FormSubmission::create([
                    'form_id'              => $form->id,
                    'assignment_id'        => null,
                    'submitted_by_id'      => null,
                    'building_id'          => $scope['building_id'],
                    'organization_unit_id' => $scope['organization_unit_id'],
                    'submitted_at'         => $day,
                    'submitted_ip'         => null,
                    'created_at'           => $day,
                ]);
            } else {
                $submission = FormSubmission::create([
                    'form_id'         => $form->id,
                    'assignment_id'   => $assignment?->id,
                    'submitted_by_id' => $userId,
                    'submitted_at'    => now(),
                    'submitted_ip'    => $ip,
                ]);
            }

            foreach ($fields as $fieldId => $field) {
                if (!$field->hasAnswer()) {
                    continue; // ٢٩-١: القسم عنوان بلا إجابة
                }
                $answer = new FormAnswer(['submission_id' => $submission->id, 'field_id' => $fieldId]);

                if ($field->isTable()) {
                    // ٢٩-١: صفوف الجدول كما أُدخلت، والخلية الثابتة من التعريف لا من المرسِل
                    $rows = self::tableRows($field, $values[$fieldId] ?? null);
                    if ($rows === [] && !$field->is_required) {
                        continue;
                    }
                    $answer->value = json_encode($rows, JSON_UNESCAPED_UNICODE);
                } elseif ($field->isAckItem()) {
                    // ٢٩-١: بند إقرار ثلاثي {ok, und, app, note}
                    $ack = self::ackValue($values[$fieldId] ?? null);
                    if ($ack === null && !$field->is_required) {
                        continue;
                    }
                    $answer->value = json_encode($ack ?? ['ok' => '', 'und' => '', 'app' => '', 'note' => ''], JSON_UNESCAPED_UNICODE);
                } elseif ($field->isClaim()) {
                    // قرار ٨٨: ادعاء بدليل {claim: yes|no, evidence}؛ «موثّق» المخاطر يقرؤه النظام من سجل الإدارة
                    $def = $field->claimDef();
                    if ($def['from_register']) {
                        $claim = \App\Modules\Form\Support\RegisterFacts::documentedRisksClaim(\App\Modules\Form\Support\OwnerScope::of($userId)['organization_unit_id']);
                    } else {
                        $claim = self::claimValue($values[$fieldId] ?? null);
                        if ($claim === null) {
                            if (!$field->is_required) {
                                continue;
                            }
                            $claim = ['claim' => '', 'evidence' => ''];
                        }
                        $file = $files[$fieldId] ?? null;
                        if ($def['attach'] && $claim['claim'] === FormField::CLAIM_YES && $file instanceof UploadedFile) {
                            $answer->attachUpload($file);
                        }
                    }
                    $answer->value = json_encode($claim, JSON_UNESCAPED_UNICODE);
                } elseif ($field->field_type === FormField::TYPE_SIGNATURE) {
                    $data = $signatures[$fieldId] ?? null;
                    if ($data && $answer->attachSignatureDataUrl($data)) {
                        $answer->value = 'موقَّع';
                    } elseif (!$field->is_required) {
                        continue;
                    }
                } elseif ($field->field_type === FormField::TYPE_PHOTO) {
                    $file = $files[$fieldId] ?? null;
                    if ($file instanceof UploadedFile) {
                        $answer->attachUpload($file);
                        $answer->value = $file->getClientOriginalName();
                    } elseif (!$field->is_required) {
                        continue;
                    }
                } else {
                    $raw = $values[$fieldId] ?? null;
                    if ($raw === null || $raw === '' || $raw === []) {
                        if (!$field->is_required) {
                            continue;
                        }
                        $raw = '';
                    }
                    // الإقرار يصل "1" (قاعدة `accepted` لا تقبل نصاً) ويُخزَّن نصاً مقروءاً في النتائج.
                    $answer->value = match (true) {
                        $field->field_type === FormField::TYPE_ACKNOWLEDGE => 'أقرّ بذلك',
                        is_array($raw) => json_encode(array_values($raw), JSON_UNESCAPED_UNICODE),
                        default        => (string) $raw,
                    };
                }

                $answer->save();
            }

            // التكليف يُغلق بالتعبئة (وهذا هو المعنى الوحيد لـ«مكتمل»).
            $assignment?->update([
                'status'       => FormAssignment::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            return $submission->fresh(['answers']);
        });
    }

    /**
     * ٢٩-١: صفوف الجدول من مدخلات الشاشة (`fields[id][صف][عمود]`) مطبَّعة على أعمدة التعريف.
     * صفوف ثابتة: صف لكل اسم بترتيبه والخلية الثابتة من التعريف؛ وإن خلت كل خلاياه الحرة عُدّ فارغاً.
     * صفوف حرة: الصفوف الفارغة كلها تُسقط.
     * @return array<int, array<int, string>>
     */
    public static function tableRows(FormField $field, mixed $raw): array
    {
        $def = $field->tableDef();
        $cols = $def['columns'];
        $in = is_array($raw) ? array_values($raw) : [];
        $cell = fn ($r, int $c): string => is_array($r) ? trim((string) ($r[$c] ?? '')) : '';

        if ($def['rows'] !== []) {
            $out = [];
            $any = false;
            foreach ($def['rows'] as $i => $label) {
                $row = [];
                foreach ($cols as $c => $col) {
                    $v = $col['type'] === 'fixed' ? $label : $cell($in[$i] ?? null, $c);
                    if ($col['type'] !== 'fixed' && $v !== '') $any = true;
                    $row[] = $v;
                }
                $out[] = $row;
            }

            return $any ? $out : [];
        }

        $out = [];
        foreach ($in as $r) {
            $row = [];
            $empty = true;
            foreach ($cols as $c => $col) {
                $v = $cell($r, $c);
                if ($v !== '') $empty = false;
                $row[] = $v;
            }
            if (!$empty) $out[] = $row;
        }

        return $out;
    }

    /**
     * ٢٩-١: إجابة بند الإقرار من مدخلات الشاشة — null إن لم يُجب عن أي سؤال.
     * @return array{ok: string, und: string, app: string, note: string}|null
     */
    public static function ackValue(mixed $raw): ?array
    {
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        $any = false;
        foreach (array_keys(FormField::ACK_QUESTIONS) as $k) {
            $v = trim((string) ($raw[$k] ?? ''));
            $v = in_array($v, [FormField::ACK_YES, FormField::ACK_NO], true) ? $v : '';
            if ($v !== '') $any = true;
            $out[$k] = $v;
        }
        $out['note'] = trim((string) ($raw['note'] ?? ''));

        return $any ? $out : null;
    }

    /**
     * قرار ٨٨: إجابة الادعاء من مدخلات الشاشة — null إن لم يُجب (لا نعم ولا لا).
     * @return array{claim: string, evidence: string}|null
     */
    public static function claimValue(mixed $raw): ?array
    {
        $raw = is_array($raw) ? $raw : [];
        $claim = trim((string) ($raw['claim'] ?? ''));
        if (!in_array($claim, [FormField::CLAIM_YES, FormField::CLAIM_NO], true)) {
            return null;
        }

        return ['claim' => $claim, 'evidence' => $claim === FormField::CLAIM_YES ? trim((string) ($raw['evidence'] ?? '')) : ''];
    }

    /**
     * هل يحق لهذا المستخدم تعبئة هذا النموذج؟
     * (في OHSMS كانت التعبئة مفتوحة لأي مستخدم مسجَّل بلا تحقق من التكليف.)
     */
    public function canFill(FormTemplate $form, int $userId): bool
    {
        if (!$form->is_active) {
            return false;
        }

        return FormAssignment::where('form_id', $form->id)
            ->where('assigned_to_id', $userId)
            ->exists();
    }

    /** هل عبّأ هذا المستخدم النموذج مسبقاً؟ (٢٩-٢: يُقرأ من التكليف المكتمل، فالتعبئة السرية بلا اسم) */
    public function hasSubmitted(FormTemplate $form, int $userId): bool
    {
        return FormAssignment::where('form_id', $form->id)->where('assigned_to_id', $userId)
                ->where('status', FormAssignment::STATUS_COMPLETED)->exists()
            || FormSubmission::where('form_id', $form->id)->where('submitted_by_id', $userId)->exists();
    }

    // ── التوليد من المخاطر ──

    /**
     * توليد نموذج من مخاطر: نوعه يُشتق من أشدّ خطر (توعية ← تأكيد ← إقرار ← إقرار بشاهد)،
     * ونص المقدمة يعرض كل خطر مع بنود تحكمه، فيقرأ الموظف ما يقرّ به لا عنواناً مجرداً.
     *
     * (في OHSMS كان كل خطر يصير حقل «نص طويل» بعنوان الخطر — لا يقرأ الموظف شيئاً ولا يقرّ بشيء.)
     *
     * @param array<int> $riskIds
     */
    public function generateFromRisks(array $riskIds, string $title, ?string $description, ?int $userId = null): FormTemplate
    {
        $risks = Risk::whereIn('id', $riskIds)->with('controls')->get();
        if ($risks->isEmpty()) {
            throw new \InvalidArgumentException('لا مخاطر مختارة.');
        }

        $maxScore = (int) $risks->max('risk_score');
        $type     = FormTemplate::typeForRiskScore($maxScore);

        return DB::transaction(function () use ($risks, $title, $description, $userId, $type) {
            $form = FormTemplate::create([
                'title'          => $title,
                'description'    => $description,
                'intro'          => $this->buildIntro($risks),
                'form_type'      => $type,
                'source_risk_id' => $risks->sortByDesc('risk_score')->first()?->id,
                'place_id'       => $risks->pluck('place_id')->filter()->unique()->count() === 1
                    ? $risks->pluck('place_id')->filter()->first()
                    : null,
                'created_by_id'  => $userId,
            ]);

            $form->risks()->sync($risks->pluck('id')->all());

            $order = 1;
            $form->fields()->create([
                'label'       => 'أقرّ بأنني اطّلعت على المخاطر المذكورة أعلاه وضوابطها، وألتزم بها.',
                'field_type'  => FormField::TYPE_ACKNOWLEDGE,
                'is_required' => true,
                'order'       => $order++,
            ]);

            if ($type !== FormTemplate::TYPE_AWARENESS) {
                $form->fields()->create([
                    'label'       => 'ملاحظات أو استفسار (اختياري)',
                    'field_type'  => FormField::TYPE_TEXTAREA,
                    'is_required' => false,
                    'order'       => $order++,
                ]);
            }

            if (in_array($type, [FormTemplate::TYPE_DECLARATION, FormTemplate::TYPE_DECLARATION_WITNESSED], true)) {
                $form->fields()->create([
                    'label'       => 'التوقيع',
                    'help_text'   => 'وقّع بإصبعك أو بالفأرة داخل الإطار.',
                    'field_type'  => FormField::TYPE_SIGNATURE,
                    'is_required' => true,
                    'order'       => $order++,
                ]);
            }

            if ($type === FormTemplate::TYPE_DECLARATION_WITNESSED) {
                $form->fields()->create([
                    'label'       => 'اسم الشاهد وصفته',
                    'help_text'   => 'من حضر التوقيع من مسؤولي السلامة أو مديري الإدارة.',
                    'field_type'  => FormField::TYPE_TEXT,
                    'is_required' => true,
                    'order'       => $order++,
                ]);
                $form->fields()->create([
                    'label'       => 'توقيع الشاهد',
                    'field_type'  => FormField::TYPE_SIGNATURE,
                    'is_required' => true,
                    'order'       => $order++,
                ]);
            }

            return $form->fresh(['fields', 'risks']);
        });
    }

    /** نص المقدمة: كل خطر بدرجته وضوابطه الاستباقية. */
    private function buildIntro(Collection $risks): string
    {
        $lines = [];
        foreach ($risks->sortByDesc('risk_score') as $risk) {
            $lines[] = "• {$risk->title} (درجة الخطر: {$risk->risk_score})";
            if ($risk->description) {
                $lines[] = '  '.trim($risk->description);
            }
            $controls = $risk->controls->where('phase', 'preventive')->take(5);
            foreach ($controls as $control) {
                $lines[] = '  - '.$control->description_ar;
            }
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    // ── النتائج ──

    /**
     * التعبئات التي يراها هذا الحساب (٢٩-٠: المبنى من النطاق العام، والوحدة من `FormVisibilityService`)؛ بلا حساب: الكل.
     * @return Collection<int, FormSubmission>
     */
    public function results(FormTemplate $form, ?User $viewer = null): Collection
    {
        $q = FormSubmission::where('form_id', $form->id)->with(['answers.field', 'submittedBy']);
        // ٢٩-٢: صفوف القالب السري بترتيب عشوائي — ترتيب الوصول يطابق ترتيب إكمال التكليفات فيكشف أصحابها
        $form->allows_anonymous ? $q->inRandomOrder() : $q->latest('submitted_at');
        if ($viewer) $this->visibility->apply($q, $viewer);

        return $q->get();
    }

    /** ٢٩-٢: عدد تعبئات القالب السري التي يراها الحساب دون العتبة ← لا نتائج ولا تصدير (null = تُعرض) */
    public function hiddenBelowThreshold(FormTemplate $form, ?User $viewer = null): ?int
    {
        if (!$form->allows_anonymous) {
            return null;
        }
        $q = FormSubmission::where('form_id', $form->id);
        if ($viewer) $this->visibility->apply($q, $viewer);
        $n = $q->count();

        return $n < FormTemplate::ANONYMOUS_MIN_RESPONSES ? $n : null;
    }

    /**
     * ملخّص لكل حقل: للاختيارات توزيع الإجابات، ولغيرها عدد من أجاب.
     *
     * @return array<int, array{field: FormField, answered: int, distribution: array<string, int>}>
     */
    public function resultsSummary(FormTemplate $form, ?User $viewer = null): array
    {
        $submissions = $this->results($form, $viewer);
        $out = [];

        foreach ($form->fields as $field) {
            if (!$field->hasAnswer()) {
                continue; // ٢٩-١: القسم خارج الملخص
            }
            $answers = $submissions->flatMap->answers->where('field_id', $field->id);
            $distribution = [];

            if ($field->isAckItem()) {
                // ٢٩-١: لكل سؤال من الثلاثة عدد «نعم» و«لا» — «صحيح — لا» هو أول ما تقرؤه اللجنة
                foreach (FormField::ACK_QUESTIONS as $k => $q) {
                    foreach ([FormField::ACK_YES, FormField::ACK_NO] as $yn) {
                        $n = $answers->filter(fn ($a) => ($a->ackValue()[$k] ?? '') === $yn)->count();
                        if ($n > 0) $distribution[rtrim($q, '؟').' — '.$yn] = $n;
                    }
                }
            } elseif ($field->isTable()) {
                // لا توزيع للجدول — عدد من أجاب يكفي هنا؛ قراءة الخلايا في التعبئات
            } elseif ($field->isClaim()) {
                // قرار ٨٨: نعم / لا
                foreach ([FormField::CLAIM_YES => 'نعم', FormField::CLAIM_NO => 'لا'] as $k => $label) {
                    $n = $answers->filter(fn ($a) => ($a->claimValue()['claim'] ?? '') === $k)->count();
                    if ($n > 0) $distribution[$label] = $n;
                }
            } elseif ($field->needsOptions() || $field->field_type === FormField::TYPE_ACKNOWLEDGE) {
                foreach ($answers as $answer) {
                    foreach (preg_split('/،\s*/', $answer->display()) ?: [] as $part) {
                        $part = trim($part);
                        if ($part !== '') {
                            $distribution[$part] = ($distribution[$part] ?? 0) + 1;
                        }
                    }
                }
                arsort($distribution);
            }

            $out[] = ['field' => $field, 'answered' => $answers->count(), 'distribution' => $distribution];
        }

        return $out;
    }

    /** تصدير النتائج CSV (بعلامة الترتيب حتى يفتحها Excel بالعربية صحيحة). */
    public function exportCsv(FormTemplate $form, ?User $viewer = null): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $form->load('fields');
        $submissions = $this->results($form, $viewer);
        $filename = 'form-'.$form->id.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($form, $submissions) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            $fields = $form->fields->filter(fn (FormField $f) => $f->hasAnswer())->values(); // ٢٩-١: القسم لا عمود له
            $headers = ['#', 'المعبِّئ', 'وقت التعبئة'];
            foreach ($fields as $field) {
                $headers[] = $field->displayLabel();
            }
            fputcsv($out, $headers);

            foreach ($submissions as $submission) {
                $byField = $submission->answers->keyBy('field_id');
                // ٢٩-٢: الصف السري بلا رقم ولا اسم، وتاريخه باليوم
                $secret = $form->allows_anonymous && $submission->submitted_by_id === null;
                $row = [
                    $secret ? '' : $submission->id,
                    $submission->submittedBy?->name ?? ($form->allows_anonymous ? 'سري' : '—'),
                    $submission->submitted_at?->format($secret ? 'Y-m-d' : 'Y-m-d H:i'),
                ];
                foreach ($fields as $field) {
                    // get() لا [] : الحقل الاختياري بلا ردّ ليس له مفتاح في المجموعة
                    $row[] = $byField->get($field->id)?->display() ?? '';
                }
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
