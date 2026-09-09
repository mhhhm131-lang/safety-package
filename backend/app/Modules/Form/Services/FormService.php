<?php

namespace App\Modules\Form\Services;

use App\Modules\Form\Models\FormAnswer;
use App\Modules\Form\Models\FormAssignment;
use App\Modules\Form\Models\FormField;
use App\Modules\Form\Models\FormSubmission;
use App\Modules\Form\Models\FormTemplate;
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
 */
class FormService
{
    public function __construct(private readonly FormNotifier $notifier) {}

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

    /** الخيارات تصل سطراً لكل خيار من الشاشة؛ الأنواع التي لا تحتاجها تُفرَّغ. */
    private function normalizeOptions(array $data): ?array
    {
        $type = $data['field_type'] ?? null;
        if (!in_array($type, FormField::TYPES_WITH_OPTIONS, true)) {
            return null;
        }
        $raw = $data['options'] ?? null;
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

        $existing = FormAssignment::where('form_id', $form->id)
            ->whereIn('assigned_to_id', $userIds)
            ->pluck('assigned_to_id')
            ->all();

        $created = [];
        DB::transaction(function () use ($form, $userIds, $existing, $dueDate, $actorId, $source, $sourceValue, &$created) {
            foreach ($userIds as $userId) {
                if (in_array($userId, $existing, true)) {
                    // تكليف قائم: تُحدَّث المهلة فقط، ولا يُنشأ تكليف ثانٍ
                    if ($dueDate) {
                        FormAssignment::where('form_id', $form->id)
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

    /** تذكير كل من لم يعبّئ. يعيد عددهم. */
    public function remindAllPending(FormTemplate $form): int
    {
        $count = 0;
        foreach ($form->assignments()->whereIn('status', [FormAssignment::STATUS_PENDING, FormAssignment::STATUS_OVERDUE])->get() as $a) {
            $count += $this->remind($a) ? 1 : 0;
        }

        return $count;
    }

    // ── التعبئة ──

    /**
     * حفظ تعبئة. `$values` مفاتيحها معرّفات الحقول (`fields[<id>]` من الشاشة — مفتاح واحد لا اثنان).
     * `$files` للصور، و`$signatures` لبيانات التوقيع المرسوم (data URL).
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
    ): FormSubmission {
        $fields = $form->fields()->get()->keyBy('id');

        return DB::transaction(function () use ($form, $userId, $values, $files, $signatures, $ip, $fields) {
            $assignment = FormAssignment::where('form_id', $form->id)
                ->where('assigned_to_id', $userId)
                ->first();

            $submission = FormSubmission::create([
                'form_id'         => $form->id,
                'assignment_id'   => $assignment?->id,
                'submitted_by_id' => $userId,
                'submitted_at'    => now(),
                'submitted_ip'    => $ip,
            ]);

            foreach ($fields as $fieldId => $field) {
                $answer = new FormAnswer(['submission_id' => $submission->id, 'field_id' => $fieldId]);

                if ($field->field_type === FormField::TYPE_SIGNATURE) {
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

    /** هل عبّأ هذا المستخدم النموذج مسبقاً؟ */
    public function hasSubmitted(FormTemplate $form, int $userId): bool
    {
        return FormSubmission::where('form_id', $form->id)->where('submitted_by_id', $userId)->exists();
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

    /** @return Collection<int, FormSubmission> */
    public function results(FormTemplate $form): Collection
    {
        return FormSubmission::where('form_id', $form->id)
            ->with(['answers.field', 'submittedBy'])
            ->latest('submitted_at')
            ->get();
    }

    /**
     * ملخّص لكل حقل: للاختيارات توزيع الإجابات، ولغيرها عدد من أجاب.
     *
     * @return array<int, array{field: FormField, answered: int, distribution: array<string, int>}>
     */
    public function resultsSummary(FormTemplate $form): array
    {
        $submissions = $this->results($form);
        $out = [];

        foreach ($form->fields as $field) {
            $answers = $submissions->flatMap->answers->where('field_id', $field->id);
            $distribution = [];

            if ($field->needsOptions() || $field->field_type === FormField::TYPE_ACKNOWLEDGE) {
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
    public function exportCsv(FormTemplate $form): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $form->load('fields');
        $submissions = $this->results($form);
        $filename = 'form-'.$form->id.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($form, $submissions) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            $headers = ['#', 'المعبِّئ', 'وقت التعبئة'];
            foreach ($form->fields as $field) {
                $headers[] = $field->label;
            }
            fputcsv($out, $headers);

            foreach ($submissions as $submission) {
                $byField = $submission->answers->keyBy('field_id');
                $row = [
                    $submission->id,
                    $submission->submittedBy?->name ?? '—',
                    $submission->submitted_at?->format('Y-m-d H:i'),
                ];
                foreach ($form->fields as $field) {
                    // get() لا [] : الحقل الاختياري بلا ردّ ليس له مفتاح في المجموعة
                    $row[] = $byField->get($field->id)?->display() ?? '';
                }
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
