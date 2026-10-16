<?php

namespace App\Modules\Form\Controllers;

use App\Core\Rules\InAccountBuildings;
use App\Http\Controllers\Controller;
use App\Modules\Form\Models\FormAnswer;
use App\Modules\Form\Models\FormAssignment;
use App\Modules\Form\Models\FormField;
use App\Modules\Form\Models\FormSubmission;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Form\Services\FormNotifier;
use App\Modules\Form\Services\FormService;
use App\Modules\Form\Services\FormVisibilityService;
use App\Modules\Form\Services\RoundService;
use App\Modules\Form\Support\NonMaterialPackage;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Risk\Models\Risk;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * النماذج الرقمية: القائمة، البناء، التوليد من المخاطر، التكليف، التعبئة، التتبع، النتائج.
 *
 * المسارات محمية بصلاحيات `form.*`، والتعبئة بـ`FormPolicy::fill` (مكلَّف، مرة واحدة).
 * ٢٩-٠ (قرار ٨٧): القوالب عامة، وما يُعبَّأ يُقرأ بقاعدة الرؤية (`FormVisibilityService`) في العدّادات والمتابعة والنتائج والتصدير.
 */
class FormController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly FormService $forms,
        private readonly FormNotifier $notifier,
        private readonly FormVisibilityService $visibility,
    ) {}

    // ── القائمة ──

    public function index(Request $request)
    {
        $viewer = Auth::user();
        // ٢٩-٧: قوالب الطبقة الثانية (بطاقة الإدارة والإقرارات المولَّدة) في تبويبها «الطبقة الثانية» لا في القائمة العامة
        $query = FormTemplate::query()->whereNull('package_code')->withCount([
            'fields',
            'submissions' => fn ($q) => $this->visibility->apply($q, $viewer), // ٢٩-٠: ما يراه الحساب
            'assignments' => fn ($q) => $this->visibility->apply($q, $viewer),
        ])->latest('id');

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
            'facets'     => FormTemplate::selectRaw('form_type, COUNT(*) as n')->where('is_active', true)->whereNull('package_code')
                ->groupBy('form_type')->pluck('n', 'form_type')->all(),
            'activeType' => $type,
            'places'     => \App\Modules\Governance\Services\BuildingContext::placesFor(Auth::user()), // قرار ٨٤
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
            'places'   => \App\Modules\Governance\Services\BuildingContext::placesFor(Auth::user()), // قرار ٨٤
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
            'stats'       => $form->assignmentStats(Auth::user()),
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
            'places' => \App\Modules\Governance\Services\BuildingContext::placesFor(Auth::user()), // قرار ٨٤
        ]);
    }

    public function update(Request $request, FormTemplate $form)
    {
        $this->authorize('update', $form);
        $data = $this->validated($request);
        if ($form->submissions()->withoutGlobalScope('building')->exists()) {
            unset($data['allows_anonymous']); // ٢٩-٢: السرية لا تتبدّل بعد أول تعبئة — وعدٌ قُطع للمعبِّئين
        }
        $form->update($data);

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
            'places' => \App\Modules\Governance\Services\BuildingContext::placesFor(Auth::user()), // قرار ٨٤
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
            'places' => \App\Modules\Governance\Services\BuildingContext::placesFor(Auth::user()), // قرار ٨٤
            'stats'  => $form->assignmentStats(Auth::user()),
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
            'unit_id'    => InAccountBuildings::unitRules('required_if:mode,unit', 'nullable'), // ٢٩-٠ حارس الكتابة: الوحدة من مبانيك
            'place_id'   => InAccountBuildings::placeRules('required_if:mode,place', 'nullable'), // حارس الكتابة (تتمة قرار ٨٤): من مبانيك
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

        $viewer = Auth::user();
        $assignments = null;
        if (!$form->allows_anonymous) {
            // ٢٩-٢: في القالب السري العدد لا الأسماء — لا تُحمَّل صفوف المكلَّفين أصلاً؛ النظام يذكّر من لم يعبّئ
            $assignments = $form->assignments()->with(['assignedTo.profile', 'submission']);
            $this->visibility->apply($assignments, $viewer); // ٢٩-٠: ما يراه الحساب من التكليفات
            $assignments = $assignments
                ->orderByRaw("CASE status WHEN 'overdue' THEN 1 WHEN 'pending' THEN 2 ELSE 3 END")
                ->orderBy('due_date')
                ->paginate(50);
        }

        return view('modules.forms.tracking', [
            'form'        => $form,
            'assignments' => $assignments,
            'stats'       => $form->assignmentStats($viewer),
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
        $n = $this->forms->remindAllPending($form, Auth::user());

        return back()->with($n > 0 ? 'ok' : 'err', $n > 0 ? "أُرسل التذكير إلى {$n} مكلَّفاً." : 'لا أحد متأخر.');
    }

    // ── التعبئة ──

    public function fill(FormTemplate $form)
    {
        $this->authorize('fill', $form);
        $form->load('fields');

        // قرار ٨٨: ما يقرؤه النظام من السجل بلا سؤال (المخاطر/موثّق) يُعرض للمعبِّئ قراءةً
        $facts = [];
        foreach ($form->fields as $field) {
            if ($field->isClaim() && $field->claimDef()['from_register']) {
                $facts[$field->id] = \App\Modules\Form\Support\RegisterFacts::documentedRisksClaim(Auth::user()->profile?->organization_unit_id);
            }
        }

        // ٢٩-٤: التكليف المفتوح (أحدث جولة)؛ مسودته المحفوظة آلياً تعود إلى الشاشة؛ ورأس الجولة يملؤه النظام
        $assignment = $this->forms->openAssignment($form, Auth::id());
        $autosave = $assignment !== null && !$form->allows_anonymous; // لا مسودة في القالب السري: تربط الإجابة بالشخص
        if ($autosave && $assignment->draft !== null && !request()->session()->hasOldInput()) {
            request()->session()->flash('_old_input', ['fields' => $assignment->draftValues()]);
        }
        $header = null;
        if ($round = $assignment?->round) {
            $unit = $round->organizationUnit;
            $header = [
                'unit'      => $unit?->name ?? '—',
                'manager'   => $round->cardAssignment()?->assignedTo?->name ?? '—', // ٢٩-٥: مدير الإدارة لا المعبِّئ (الموظف في الإقرار)
                'employees' => $unit ? RoundService::employeeCount($unit) : 0,
                'round_no'  => $round->round_no,
            ];
        }

        return view('modules.forms.fill', [
            'form'       => $form,
            'assignment' => $assignment,
            'facts'      => $facts,
            'header'     => $header,
            'autosave'   => $autosave,
        ]);
    }

    /** ٢٩-٤: حفظ آلي للمسودة — للمكلَّف وتكليفه مفتوح؛ ولا مسودة في القالب السري. */
    public function saveDraft(Request $request, FormTemplate $form)
    {
        $this->authorize('fill', $form);
        abort_if($form->allows_anonymous, 422, 'لا مسودة في النموذج السري.');
        $assignment = $this->forms->openAssignment($form, Auth::id());
        abort_if(!$assignment || !$assignment->isOpen(), 404);
        $data = $request->validate(['fields' => ['nullable', 'array']]);

        try {
            $this->forms->saveDraft($assignment, $data['fields'] ?? []);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['saved_at' => now()->format('H:i')]);
    }

    public function submit(Request $request, FormTemplate $form)
    {
        $this->authorize('fill', $form);

        $fields = $form->fields()->get();
        $rules = [];
        $names = [];
        foreach ($fields as $field) {
            if (!$field->hasAnswer()) {
                continue; // ٢٩-١: القسم عنوان بلا إجابة
            }
            if ($field->isTable()) {
                // ٢٩-١: صفوف × أعمدة؛ الإلزام يُفحص بعد التطبيع (صف مملوء واحد، أو اختيار لكل صف ثابت)
                $rules["fields.{$field->id}"]     = ['nullable', 'array'];
                $rules["fields.{$field->id}.*"]   = ['nullable', 'array'];
                $rules["fields.{$field->id}.*.*"] = ['nullable', 'string', 'max:2000'];
                $names["fields.{$field->id}"] = $field->label;
                continue;
            }
            if ($field->isClaim()) {
                // قرار ٨٨: نعم/لا، و«نعم بلا أرني لا تُقبل» تُفحص بعد التحقق؛ ما يقرؤه النظام من السجل لا يُسأل
                $def = $field->claimDef();
                if ($def['from_register']) {
                    continue;
                }
                $rules["fields.{$field->id}"]          = ['nullable', 'array'];
                $rules["fields.{$field->id}.claim"]    = [$field->is_required ? 'required' : 'nullable', 'in:'.FormField::CLAIM_YES.','.FormField::CLAIM_NO];
                $rules["fields.{$field->id}.evidence"] = ['nullable', 'string', 'max:2000'];
                if ($def['attach']) {
                    $rules["photos.{$field->id}"] = ['nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg,webp', 'max:10240'];
                }
                $names["fields.{$field->id}.claim"]    = $field->label;
                $names["fields.{$field->id}.evidence"] = 'أرني';
                $names["photos.{$field->id}"]          = 'الوثيقة';
                continue;
            }
            if ($field->isLadder()) {
                // ٢٩-٥: أربع عبارات نعم/لا، و«لا ينطبق على عملي»، و«ما الواقع» — إلزامها عند «لا» على «أطبّقه» يُفحص بعد التحقق
                $rules["fields.{$field->id}"]    = ['nullable', 'array'];
                $rules["fields.{$field->id}.na"] = ['nullable', 'boolean'];
                foreach (array_keys(NonMaterialPackage::EMPLOYEE_STATEMENTS) as $k) {
                    $rules["fields.{$field->id}.{$k}"] = ['nullable', 'in:'.FormField::LADDER_YES.','.FormField::LADDER_NO];
                }
                $rules["fields.{$field->id}.note"] = ['nullable', 'string', 'max:2000'];
                $names["fields.{$field->id}"] = $field->label;
                $names["fields.{$field->id}.note"] = NonMaterialPackage::REALITY_LABEL;
                continue;
            }
            if ($field->isAckItem()) {
                // ٢٩-١: ثلاثة أسئلة نعم/لا وخانة «ما الواقع» — إلزامها عند أي «لا» يُفحص بعد التحقق
                $rules["fields.{$field->id}"] = ['nullable', 'array'];
                foreach (array_keys(FormField::ACK_QUESTIONS) as $k) {
                    $rules["fields.{$field->id}.{$k}"] = ['nullable', 'in:'.FormField::ACK_YES.','.FormField::ACK_NO];
                }
                $rules["fields.{$field->id}.note"] = ['nullable', 'string', 'max:2000'];
                $names["fields.{$field->id}"] = $field->label;
                $names["fields.{$field->id}.note"] = 'ما الواقع';
                continue;
            }
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

        $validator = Validator::make($request->all(), $rules, $messages, $names);
        $validator->after(function ($v) use ($fields, $request) {
            foreach ($fields as $field) {
                if ($field->isLadder()) {
                    // ٢٩-٥: بند بلا جواب ولا «لا ينطبق» يُرفض؛ و«لا» على «أطبّقه فعلاً» بلا «ما الواقع» تُرفض — أثمن نص في الحزمة
                    $lv = FormService::ladderValue($request->input("fields.{$field->id}"));
                    if ($lv === null || (!$lv['na'] && in_array('', [$lv['doc'], $lv['ann'], $lv['und'], $lv['app']], true))) {
                        if ($field->is_required) $v->errors()->add("fields.{$field->id}", 'أجب عن العبارات الأربع في «'.$field->label.'» أو اختر «'.NonMaterialPackage::NA_LABEL.'».');
                    } elseif (!$lv['na'] && $lv['app'] === FormField::LADDER_NO && $lv['note'] === '') {
                        $v->errors()->add("fields.{$field->id}.note", 'قلتَ إنك لا تطبّقه — اكتب ما الواقع في «'.$field->label.'».');
                    }
                    continue;
                }
                if ($field->isClaim() && !$field->claimDef()['from_register']) {
                    $c = FormService::claimValue($request->input("fields.{$field->id}"));
                    if ($c && $c['claim'] === FormField::CLAIM_YES && $c['evidence'] === '' && !$request->hasFile("photos.{$field->id}")) {
                        $v->errors()->add("fields.{$field->id}.evidence", 'نعم بلا «أرني» لا تُقبل: اكتب سطراً أو ارفع الوثيقة.');
                    }
                    continue;
                }
                if ($field->isTable() && $field->is_required) {
                    $rows = FormService::tableRows($field, $request->input("fields.{$field->id}"));
                    if ($rows === []) {
                        $v->errors()->add("fields.{$field->id}", 'أضف صفاً واحداً على الأقل في «'.$field->label.'».');
                        continue;
                    }
                    $def = $field->tableDef();
                    if ($def['rows'] !== []) {
                        foreach ($rows as $row) {
                            foreach ($def['columns'] as $c => $col) {
                                if ($col['type'] === 'select' && ($row[$c] ?? '') === '') {
                                    $v->errors()->add("fields.{$field->id}", 'اختر لكل صف في «'.$field->label.'».');
                                    break 2;
                                }
                            }
                        }
                    }
                }
                if ($field->isAckItem()) {
                    $ack = FormService::ackValue($request->input("fields.{$field->id}"));
                    if ($ack === null) {
                        if ($field->is_required) $v->errors()->add("fields.{$field->id}", 'أجب عن الأسئلة الثلاثة في «'.$field->label.'».');
                        continue;
                    }
                    $answers = [$ack['ok'], $ack['und'], $ack['app']];
                    if ($field->is_required && in_array('', $answers, true)) {
                        $v->errors()->add("fields.{$field->id}", 'أجب عن الأسئلة الثلاثة في «'.$field->label.'».');
                    }
                    if (in_array(FormField::ACK_NO, $answers, true) && $ack['note'] === '') {
                        $v->errors()->add("fields.{$field->id}.note", 'اكتب ما الواقع عند أي «لا» في «'.$field->label.'».');
                    }
                }
            }
        });
        $validator->validate();

        // ٢٩-٢: في القالب السري الإجابة سرية افتراضاً، وللمعبِّئ «أُعلن اسمي»
        $anonymous = $form->allows_anonymous && !$request->boolean('reveal_name');
        $submission = $this->forms->submit(
            $form,
            Auth::id(),
            $request->input('fields', []),
            $request->file('photos', []),
            $request->input('signatures', []),
            $request->ip(),
            $anonymous,
        );

        return redirect()->route('forms.submitted', $anonymous ? $form : [$form, 'submission' => $submission->id])
            ->with('ok', $anonymous ? 'شكراً — سُجّلت إجابتك سرياً.' : 'شكراً — سُجّلت تعبئتك.');
    }

    public function submitted(Request $request, FormTemplate $form)
    {
        $submission = null;
        $assignment = null;
        if ($request->filled('submission')) {
            $submission = FormSubmission::where('form_id', $form->id)
                ->where('id', (int) $request->query('submission'))
                ->where('submitted_by_id', Auth::id())
                ->firstOrFail();
        } else {
            // ٢٩-٢: التعبئة السرية لا رقم لها يُعاد — الدليل تكليفُه المكتمل (صندوق التكليف)
            $assignment = FormAssignment::where('form_id', $form->id)->where('assigned_to_id', Auth::id())
                ->where('status', FormAssignment::STATUS_COMPLETED)->firstOrFail();
        }

        return view('modules.forms.submitted', compact('form', 'submission', 'assignment'));
    }

    // ── النتائج ──

    public function results(FormTemplate $form)
    {
        $this->authorize('results', $form);
        $form->load('fields');

        $viewer = Auth::user();
        $hidden = $this->forms->hiddenBelowThreshold($form, $viewer); // ٢٩-٢: تحت خمسة مجيبين لا نتائج

        return view('modules.forms.results', [
            'form'        => $form,
            'submissions' => $hidden === null ? $this->forms->results($form, $viewer) : collect(),
            'summary'     => $hidden === null ? $this->forms->resultsSummary($form, $viewer) : [],
            'stats'       => $form->assignmentStats($viewer),
            'hidden'      => $hidden,
        ]);
    }

    public function export(FormTemplate $form)
    {
        $this->authorize('results', $form);
        if (($n = $this->forms->hiddenBelowThreshold($form, Auth::user())) !== null) {
            return redirect()->route('forms.results', $form)
                ->with('err', "أجاب {$n} — لا تصدير لنموذج سري تحت ".FormTemplate::ANONYMOUS_MIN_RESPONSES.' مجيبين.');
        }

        return $this->forms->exportCsv($form, Auth::user());
    }

    /**
     * عرض ملف رد (توقيع أو صورة أو وثيقة) — لمن يرى النتائج (ويرى وحدة التعبئة — ٢٩-٠)، أو لصاحب التعبئة،
     * أو (٢٩-٥) لموظف كُلّف بإقرار الجولة التي رُفعت فيها الوثيقة: ما شاركته إدارته يراه.
     */
    public function answerFile(FormTemplate $form, FormAnswer $answer)
    {
        $submission = $answer->submission; // النطاق العام بالمبنى يحجب تعبئة مبنى آخر فتصير 404
        abort_if(!$submission || $submission->form_id !== $form->id, 404);
        abort_unless(
            (Auth::user()->can('results', $form) && $this->visibility->canSeeSubmission(Auth::user(), $submission))
            || $submission->submitted_by_id === Auth::id()
            || $this->isAckRecipient(Auth::user(), $submission),
            403,
        );
        abort_unless($answer->hasFile(), 404, 'لا ملف.');

        return response(base64_decode($answer->file_data), 200, [
            'Content-Type'        => $answer->file_mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.rawurlencode($answer->file_name ?: 'answer').'"',
        ]);
    }

    // ── مساعدات ──

    /** ٢٩-٥: هل كُلّف هذا الحساب بإقرار الجولة التي جاءت منها التعبئة (بطاقة الإدارة)؟ */
    private function isAckRecipient(User $user, FormSubmission $submission): bool
    {
        if (!$submission->round_id) {
            return false;
        }

        return FormAssignment::withoutGlobalScope('building')->where('assigned_to_id', $user->id)->where('round_id', $submission->round_id)
            ->whereHas('form', fn ($q) => $q->where('package_code', NonMaterialPackage::ackCode((int) $submission->round_id)))
            ->exists();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title'                => ['required', 'string', 'max:200'],
            'description'          => ['nullable', 'string', 'max:2000'],
            'intro'                => ['nullable', 'string', 'max:8000'],
            'form_type'            => ['required', 'in:'.implode(',', array_keys(FormTemplate::TYPE_LABELS))],
            'allows_anonymous'     => ['nullable', 'boolean'], // ٢٩-٢
            'organization_unit_id' => ['nullable', 'integer', 'exists:organization_units,id'],
            'place_id'             => InAccountBuildings::placeRules('nullable'), // حارس الكتابة (تتمة قرار ٨٤): من مبانيك
        ], [], ['title' => 'عنوان النموذج', 'form_type' => 'نوع النموذج'])
            + ['allows_anonymous' => $request->boolean('allows_anonymous')];
    }

    private function validatedField(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'label'       => ['required', 'string', 'max:300'],
            'help_text'   => ['nullable', 'string', 'max:500'],
            'placeholder' => ['nullable', 'string', 'max:190'],
            'field_type'  => ['required', 'in:'.implode(',', array_keys(FormField::TYPE_LABELS))],
            'is_required' => ['nullable', 'boolean'],
            'options'     => ['nullable', 'string', 'max:5000'],
        ], [], ['label' => 'نص الحقل', 'field_type' => 'نوع الحقل', 'options' => 'الخيارات']);
        // ٢٩-١: الجدول يحتاج عموداً واحداً على الأقل في تعريفه
        $validator->after(function ($v) use ($request) {
            if ($request->input('field_type') === FormField::TYPE_TABLE
                && FormField::parseTableText((string) $request->input('options'))['columns'] === []) {
                $v->errors()->add('options', 'عرّف عموداً واحداً على الأقل: سطر لكل عمود «الاسم | نص».');
            }
        });

        return $validator->validate() + ['is_required' => $request->boolean('is_required')];
    }
}
