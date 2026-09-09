<?php

namespace App\Modules\Form\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Form\Models\FormAnswer;
use App\Modules\Form\Models\FormAssignment;
use App\Modules\Form\Models\FormField;
use App\Modules\Form\Models\FormSubmission;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Form\Services\FormNotifier;
use App\Modules\Form\Services\FormService;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Risk\Models\Risk;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * النماذج الرقمية: القائمة، البناء، التوليد من المخاطر، التكليف، التعبئة، التتبع، النتائج.
 *
 * المسارات محمية بصلاحيات `form.*`، والتعبئة بـ`FormPolicy::fill` (مكلَّف، مرة واحدة).
 */
class FormController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly FormService $forms,
        private readonly FormNotifier $notifier,
    ) {}

    // ── القائمة ──

    public function index(Request $request)
    {
        $query = FormTemplate::query()->withCount(['fields', 'submissions', 'assignments'])->latest('id');

        if (($type = $request->query('type')) && isset(FormTemplate::TYPE_LABELS[$type])) {
            $query->where('form_type', $type);
        }
        if ($request->filled('place_id')) {
            $query->where('place_id', (int) $request->query('place_id'));
        }
        if ($request->boolean('inactive')) {
            $query->where('is_active', false);
        } else {
            $query->where('is_active', true);
        }

        return view('modules.forms.index', [
            'forms'      => $query->paginate(25)->withQueryString(),
            'facets'     => FormTemplate::selectRaw('form_type, COUNT(*) as n')->where('is_active', true)
                ->groupBy('form_type')->pluck('n', 'form_type')->all(),
            'activeType' => $type,
            'places'     => Place::orderBy('sort')->get(['id', 'code', 'name']),
        ]);
    }

    /** «نماذجي»: ما كُلّف به المستخدم الحالي. تُفتح لكل من له حساب. */
    public function mine()
    {
        $assignments = FormAssignment::where('assigned_to_id', Auth::id())
            ->with(['form:id,title,form_type,is_active', 'submission:id,assignment_id,submitted_at'])
            ->orderByRaw("CASE status WHEN 'overdue' THEN 1 WHEN 'pending' THEN 2 ELSE 3 END")
            ->orderBy('due_date')
            ->get();

        return view('modules.forms.mine', compact('assignments'));
    }

    // ── البناء ──

    public function create()
    {
        $this->authorize('create', FormTemplate::class);

        return view('modules.forms.form', [
            'form'     => new FormTemplate(),
            'units'    => OrganizationUnit::orderBy('name')->get(['id', 'name']),
            'places'   => Place::orderBy('sort')->get(['id', 'code', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', FormTemplate::class);
        $form = $this->forms->createForm($this->validated($request), Auth::id());

        return redirect()->route('forms.show', $form)->with('ok', 'أُنشئ النموذج. أضف حقوله ثم كلّف به.');
    }

    public function show(FormTemplate $form)
    {
        $this->authorize('view', $form);
        $form->load(['fields', 'risks', 'place', 'organizationUnit', 'createdBy', 'sourceRisk']);

        return view('modules.forms.show', [
            'form'        => $form,
            'stats'       => $form->assignmentStats(),
            'fieldTypes'  => FormField::TYPE_LABELS,
            'canEditFields' => Auth::user()->can('editFields', $form),
        ]);
    }

    public function edit(FormTemplate $form)
    {
        $this->authorize('update', $form);

        return view('modules.forms.form', [
            'form'   => $form,
            'units'  => OrganizationUnit::orderBy('name')->get(['id', 'name']),
            'places' => Place::orderBy('sort')->get(['id', 'code', 'name']),
        ]);
    }

    public function update(Request $request, FormTemplate $form)
    {
        $this->authorize('update', $form);
        $form->update($this->validated($request));

        return redirect()->route('forms.show', $form)->with('ok', 'حُفظت التعديلات.');
    }

    public function toggleActive(FormTemplate $form)
    {
        $this->authorize('update', $form);
        $form->update(['is_active' => !$form->is_active]);

        return back()->with('ok', $form->is_active ? 'فُعّل النموذج.' : 'أُوقف النموذج (لا يُعبَّأ بعد الآن).');
    }

    // ── الحقول ──

    public function storeField(Request $request, FormTemplate $form)
    {
        $this->authorize('editFields', $form);
        $this->forms->addField($form, $this->validatedField($request));

        return back()->with('ok', 'أُضيف الحقل.');
    }

    public function updateField(Request $request, FormTemplate $form, FormField $field)
    {
        $this->authorize('editFields', $form);
        abort_if($field->form_id !== $form->id, 404);
        $this->forms->updateField($field, $this->validatedField($request));

        return back()->with('ok', 'حُدّث الحقل.');
    }

    public function destroyField(FormTemplate $form, FormField $field)
    {
        $this->authorize('editFields', $form);
        abort_if($field->form_id !== $form->id, 404);
        $field->delete();

        return back()->with('ok', 'حُذف الحقل.');
    }

    public function reorderFields(Request $request, FormTemplate $form)
    {
        $this->authorize('editFields', $form);
        $data = $request->validate(['order' => ['required', 'array'], 'order.*' => ['integer']]);
        $this->forms->reorderFields($form, $data['order']);

        return response()->json(['ok' => true]);
    }

    // ── التوليد من المخاطر ──

    public function generateForm(Request $request)
    {
        $this->authorize('create', FormTemplate::class);

        $risks = Risk::query()
            ->where('risk_type', 'active')
            ->whereNotIn('status', ['closed', 'rejected'])
            ->when($request->filled('place_id'), fn ($q) => $q->where('place_id', (int) $request->query('place_id')))
            ->with('place')
            ->orderByDesc('risk_score')
            ->limit(200)
            ->get(['id', 'code', 'title', 'risk_score', 'place_id', 'organization_unit_id']);

        return view('modules.forms.generate', [
            'risks'  => $risks,
            'places' => Place::orderBy('sort')->get(['id', 'code', 'name']),
        ]);
    }

    public function generate(Request $request)
    {
        $this->authorize('create', FormTemplate::class);

        $data = $request->validate([
            'title'       => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'risk_ids'    => ['required', 'array', 'min:1'],
            'risk_ids.*'  => ['integer', 'exists:risks,id'],
        ], [], ['title' => 'عنوان النموذج', 'risk_ids' => 'المخاطر']);

        try {
            $form = $this->forms->generateFromRisks(
                $data['risk_ids'], $data['title'], $data['description'] ?? null, Auth::id(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('err', $e->getMessage());
        }

        $this->notifier->generatedFromRisks($form, count($data['risk_ids']));

        return redirect()->route('forms.show', $form)
            ->with('ok', 'وُلّد النموذج بنوع «'.$form->getTypeLabel().'» من درجة أشدّ خطر. راجعه ثم كلّف به.');
    }

    // ── التكليف ──

    public function sendForm(FormTemplate $form)
    {
        $this->authorize('send', $form);

        $assigned = $form->assignments()->pluck('assigned_to_id')->all();

        return view('modules.forms.send', [
            'form'   => $form,
            'users'  => User::with('profile')->orderBy('name')->get()
                ->filter(fn ($u) => $u->profile?->is_active)
                ->reject(fn ($u) => in_array($u->id, $assigned, true))
                ->values(),
            'roles'  => \App\Core\Permissions\PermissionRegistry::ROLES,
            'units'  => OrganizationUnit::orderBy('name')->get(['id', 'name']),
            'places' => Place::orderBy('sort')->get(['id', 'code', 'name']),
            'stats'  => $form->assignmentStats(),
        ]);
    }

    public function send(Request $request, FormTemplate $form)
    {
        $this->authorize('send', $form);

        $data = $request->validate([
            'mode'       => ['required', 'in:users,role,unit,place'],
            'user_ids'   => ['required_if:mode,users', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'role'       => ['required_if:mode,role', 'nullable', 'string', 'max:60'],
            'unit_id'    => ['required_if:mode,unit', 'nullable', 'integer', 'exists:organization_units,id'],
            'place_id'   => ['required_if:mode,place', 'nullable', 'integer', 'exists:places,id'],
            'due_date'   => ['nullable', 'date', 'after_or_equal:today'],
        ], [], ['due_date' => 'المهلة', 'user_ids' => 'المكلَّفون']);

        $due = $data['due_date'] ?? null;
        $actor = Auth::id();

        $count = match ($data['mode']) {
            'users' => $this->forms->assignToUsers($form, $data['user_ids'], $due, $actor),
            'role'  => $this->forms->assignToRole($form, $data['role'], $due, $actor),
            'unit'  => $this->forms->assignToUnit($form, (int) $data['unit_id'], $due, $actor),
            'place' => $this->forms->assignToPlace($form, (int) $data['place_id'], $due, $actor),
        };

        return redirect()->route('forms.tracking', $form)->with(
            $count > 0 ? 'ok' : 'err',
            $count > 0 ? "كُلّف {$count} شخصاً وأُشعروا." : 'لا أحد جديد ينطبق عليه هذا المعيار.',
        );
    }

    public function tracking(FormTemplate $form)
    {
        $this->authorize('track', $form);

        return view('modules.forms.tracking', [
            'form'        => $form,
            'assignments' => $form->assignments()
                ->with(['assignedTo.profile', 'submission'])
                ->orderByRaw("CASE status WHEN 'overdue' THEN 1 WHEN 'pending' THEN 2 ELSE 3 END")
                ->orderBy('due_date')
                ->paginate(50),
            'stats'       => $form->assignmentStats(),
        ]);
    }

    public function remind(FormTemplate $form, FormAssignment $assignment)
    {
        $this->authorize('send', $form);
        abort_if($assignment->form_id !== $form->id, 404);

        $sent = $this->forms->remind($assignment);

        return back()->with($sent ? 'ok' : 'err', $sent ? 'أُرسل التذكير.' : 'التكليف مكتمل — لا تذكير.');
    }

    public function remindAll(FormTemplate $form)
    {
        $this->authorize('send', $form);
        $n = $this->forms->remindAllPending($form);

        return back()->with($n > 0 ? 'ok' : 'err', $n > 0 ? "أُرسل التذكير إلى {$n} مكلَّفاً." : 'لا أحد متأخر.');
    }

    // ── التعبئة ──

    public function fill(FormTemplate $form)
    {
        $this->authorize('fill', $form);
        $form->load('fields');

        return view('modules.forms.fill', [
            'form'       => $form,
            'assignment' => FormAssignment::where('form_id', $form->id)->where('assigned_to_id', Auth::id())->first(),
        ]);
    }

    public function submit(Request $request, FormTemplate $form)
    {
        $this->authorize('fill', $form);

        $fields = $form->fields()->get();
        $rules = [];
        $names = [];
        foreach ($fields as $field) {
            $key = match ($field->field_type) {
                FormField::TYPE_PHOTO     => "photos.{$field->id}",
                FormField::TYPE_SIGNATURE => "signatures.{$field->id}",
                default                   => "fields.{$field->id}",
            };
            $rules[$key] = match (true) {
                $field->field_type === FormField::TYPE_PHOTO     => [$field->is_required ? 'required' : 'nullable', 'file', 'image', 'max:5120'],
                $field->field_type === FormField::TYPE_SIGNATURE => [$field->is_required ? 'required' : 'nullable', 'string'],
                $field->field_type === FormField::TYPE_NUMBER    => [$field->is_required ? 'required' : 'nullable', 'numeric'],
                $field->field_type === FormField::TYPE_DATE      => [$field->is_required ? 'required' : 'nullable', 'date'],
                $field->field_type === FormField::TYPE_CHECKBOX  => [$field->is_required ? 'required' : 'nullable', 'array'],
                $field->field_type === FormField::TYPE_ACKNOWLEDGE => [$field->is_required ? 'accepted' : 'nullable'],
                default                                          => [$field->is_required ? 'required' : 'nullable', 'string', 'max:5000'],
            };
            $names[$key] = $field->label;
        }

        // رسائل خاصة بحقلي الإقرار والتوقيع؛ البقية من lang/ar/validation.php
        $messages = [];
        foreach ($fields as $field) {
            if ($field->field_type === FormField::TYPE_ACKNOWLEDGE) {
                $messages["fields.{$field->id}.accepted"] = 'يجب الإقرار قبل الإرسال.';
            }
            if ($field->field_type === FormField::TYPE_SIGNATURE) {
                $messages["signatures.{$field->id}.required"] = 'التوقيع مطلوب.';
            }
        }

        $request->validate($rules, $messages, $names);

        $submission = $this->forms->submit(
            $form,
            Auth::id(),
            $request->input('fields', []),
            $request->file('photos', []),
            $request->input('signatures', []),
            $request->ip(),
        );

        return redirect()->route('forms.submitted', [$form, 'submission' => $submission->id])
            ->with('ok', 'شكراً — سُجّلت تعبئتك.');
    }

    public function submitted(Request $request, FormTemplate $form)
    {
        $submission = FormSubmission::where('form_id', $form->id)
            ->where('id', (int) $request->query('submission'))
            ->where('submitted_by_id', Auth::id())
            ->firstOrFail();

        return view('modules.forms.submitted', compact('form', 'submission'));
    }

    // ── النتائج ──

    public function results(FormTemplate $form)
    {
        $this->authorize('results', $form);
        $form->load('fields');

        return view('modules.forms.results', [
            'form'        => $form,
            'submissions' => $this->forms->results($form),
            'summary'     => $this->forms->resultsSummary($form),
            'stats'       => $form->assignmentStats(),
        ]);
    }

    public function export(FormTemplate $form)
    {
        $this->authorize('results', $form);

        return $this->forms->exportCsv($form);
    }

    /** عرض ملف رد (توقيع أو صورة) — لمن يرى النتائج، أو لصاحب التعبئة. */
    public function answerFile(FormTemplate $form, FormAnswer $answer)
    {
        $submission = $answer->submission;
        abort_if(!$submission || $submission->form_id !== $form->id, 404);
        abort_unless(
            Auth::user()->can('results', $form) || $submission->submitted_by_id === Auth::id(),
            403,
        );
        abort_unless($answer->hasFile(), 404, 'لا ملف.');

        return response(base64_decode($answer->file_data), 200, [
            'Content-Type'        => $answer->file_mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.rawurlencode($answer->file_name ?: 'answer').'"',
        ]);
    }

    // ── مساعدات ──

    private function validated(Request $request): array
    {
        return $request->validate([
            'title'                => ['required', 'string', 'max:200'],
            'description'          => ['nullable', 'string', 'max:2000'],
            'intro'                => ['nullable', 'string', 'max:8000'],
            'form_type'            => ['required', 'in:'.implode(',', array_keys(FormTemplate::TYPE_LABELS))],
            'organization_unit_id' => ['nullable', 'integer', 'exists:organization_units,id'],
            'place_id'             => ['nullable', 'integer', 'exists:places,id'],
        ], [], ['title' => 'عنوان النموذج', 'form_type' => 'نوع النموذج']);
    }

    private function validatedField(Request $request): array
    {
        return $request->validate([
            'label'       => ['required', 'string', 'max:300'],
            'help_text'   => ['nullable', 'string', 'max:500'],
            'placeholder' => ['nullable', 'string', 'max:190'],
            'field_type'  => ['required', 'in:'.implode(',', array_keys(FormField::TYPE_LABELS))],
            'is_required' => ['nullable', 'boolean'],
            'options'     => ['nullable', 'string', 'max:2000'],
        ], [], ['label' => 'نص الحقل', 'field_type' => 'نوع الحقل']) + ['is_required' => $request->boolean('is_required')];
    }
}
