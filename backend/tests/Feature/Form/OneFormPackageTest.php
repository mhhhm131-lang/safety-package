<?php

namespace Tests\Feature\Form;

use App\Models\User;
use App\Modules\Form\Models\FormAnswer;
use App\Modules\Form\Models\FormField;
use App\Modules\Form\Models\FormSubmission;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Form\Services\FormService;
use App\Modules\Form\Support\NonMaterialPackage;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use Database\Seeders\NonMaterialFormsSeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Database\Seeders\RiskBookSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * قرار ٨٨ — ٢٩-٣ (ب): قالب واحد «بطاقة الإدارة» بسبعة بنود × أربع مراحل، كل مرحلة «ادعاء بدليل».
 * البوابة: القالب ببنوده ومراحله وتلميحاتها؛ الثمانية تُحذف؛ إعادة البذر لا تكرر ولا تكتب فوق المعدَّل؛
 * نعم بلا «أرني» تُرفض، نعم بسطر أو بوثيقة تُقبل، لا تُقبل؛ و«موثّق» المخاطر يقرؤه النظام من سجل الإدارة.
 */
class OneFormPackageTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;
    private User $mudir;
    private OrganizationUnit $unit;
    private FormService $forms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, RiskBookSeeder::class, NonMaterialFormsSeeder::class]);
        $this->unit = OrganizationUnit::where('is_active', true)->orderBy('id')->firstOrFail();
        $this->salama = $this->user('salama', 'system_admin');
        $this->mudir  = $this->user('mudir', 'department_manager', $this->unit->id);
        $this->forms  = app(FormService::class);
    }

    private function user(string $username, string $role, ?int $unitId = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'organization_unit_id' => $unitId]);
        return $u;
    }

    private function card(): FormTemplate
    {
        return FormTemplate::where('package_code', NonMaterialPackage::CODE)->firstOrFail();
    }

    /** @return array<string, FormField> «item.stage» ← الحقل */
    private function claims(FormTemplate $card): array
    {
        $out = [];
        foreach ($card->fields as $f) {
            if ($f->isClaim()) $out[$f->claimDef()['item'].'.'.$f->claimDef()['stage']] = $f;
        }
        return $out;
    }

    public function test_seeds_one_card_with_seven_items_and_twenty_eight_claims(): void
    {
        $this->assertSame(1, FormTemplate::whereNotNull('package_code')->count(), 'قالب واحد في الحزمة');
        $card = $this->card();
        $this->assertSame('بطاقة الإدارة', $card->title);
        $this->assertTrue($card->fillsAsUnit());

        $fields = $card->fields;
        $this->assertCount(35, $fields);
        $this->assertSame(7, $fields->where('field_type', FormField::TYPE_SECTION)->count());
        $this->assertSame(28, $fields->where('field_type', FormField::TYPE_CLAIM)->count());
        $this->assertSame(array_column(NonMaterialPackage::ITEMS, 'title'), $fields->where('field_type', FormField::TYPE_SECTION)->pluck('label')->values()->all());
        $this->assertSame(['موثّق', 'معلن', 'مفهوم', 'مطبَّق'], $fields->where('field_type', FormField::TYPE_CLAIM)->take(4)->pluck('label')->values()->all());
        $this->assertSame(range(1, 35), $fields->pluck('order')->all());
        $this->assertSame(28, $fields->where('feeds_ack', true)->count(), 'كل الادعاءات تغذّي الإقرار');

        $claims = $this->claims($card);
        $this->assertCount(28, $claims);
        $this->assertTrue($claims['goals.doc']->claimDef()['attach'], '«موثّق» يقبل وثيقة');
        $this->assertFalse($claims['goals.ann']->claimDef()['attach']);
        $this->assertSame('وسيلة الإعلان وتاريخها — بريد · اجتماع · لوحة · منصة', $claims['goals.ann']->claimDef()['hint']);
        $this->assertSame('هل يُطبَّق فعلاً؟', $claims['goals.app']->help_text);
        $this->assertTrue($claims['risks.doc']->claimDef()['from_register'], '«موثّق» المخاطر من السجل');
        $this->assertFalse($claims['risks.doc']->is_required);
        $this->assertSame(27, $fields->where('is_required', true)->count(), 'الـ٢٧ الباقية تُجاب نعم أو لا');
        $this->assertSame(0, $fields->whereIn('field_type', [FormField::TYPE_TABLE, FormField::TYPE_ACK_ITEM, FormField::TYPE_TEXTAREA])->count(), 'لا جداول ولا فقرات');
    }

    public function test_reseeding_removes_the_eight_and_keeps_screen_edits(): void
    {
        $eight = FormTemplate::create(['title' => 'قديم', 'form_type' => 'custom', 'package_code' => '٠٤']);
        $eight->fields()->create(['label' => 'x', 'field_type' => FormField::TYPE_TEXT, 'order' => 1]);
        $card = $this->card();
        $card->update(['title' => 'بطاقة الإدارة — معدَّلة']);
        $before = FormField::count();

        $this->seed(NonMaterialFormsSeeder::class);

        $this->assertNull(FormTemplate::where('package_code', '٠٤')->first(), 'الثمانية تُحذف');
        $this->assertSame(1, FormTemplate::whereNotNull('package_code')->count());
        $this->assertSame($before - 1, FormField::count());
        $this->assertSame('بطاقة الإدارة — معدَّلة', $card->fresh()->title);
    }

    /** نعم بلا «أرني» تُرفض؛ نعم بسطر تُقبل؛ نعم بوثيقة تُقبل؛ لا تُقبل؛ و«موثّق» المخاطر من السجل */
    public function test_manager_fill_requires_evidence_with_yes_and_reads_risks_from_register(): void
    {
        $card = $this->card();
        $claims = $this->claims($card);
        $this->actingAs($this->salama);
        $this->forms->assignToUsers($card, [$this->mudir->id], null, $this->salama->id);

        $this->actingAs($this->mudir);
        $page = $this->get(route('forms.fill', $card));
        $page->assertOk()->assertSee('أهداف الإدارة وارتباطها')->assertSee('هل يوجد مكتوباً؟')->assertSee('نعم تعني: أرني')
            ->assertSee('لا أخطار مفعّلة في سجل الإدارة')->assertSee('يقرؤه النظام من سجل الإدارة');

        $answers = [];
        foreach ($claims as $key => $f) {
            if ($f->claimDef()['from_register']) continue;
            $answers[$f->id] = ['claim' => 'no'];
        }
        $answers[$claims['goals.doc']->id] = ['claim' => 'yes', 'evidence' => ''];
        $this->post(route('forms.submit', $card), ['fields' => $answers])->assertSessionHasErrors("fields.{$claims['goals.doc']->id}.evidence");

        // خطر مفعّل للوحدة ← «موثّق» المخاطر نعم من السجل
        $cat = RiskCategory::where('name', 'الحريق والانفجار')->firstOrFail();
        Risk::create(['risk_type' => 'active', 'title' => 'غموض الدور', 'description' => 'اختبار', 'category_id' => $cat->id, 'organization_unit_id' => $this->unit->id, 'severity' => 3, 'likelihood' => 3, 'status' => 'active']);

        $answers[$claims['goals.doc']->id] = ['claim' => 'yes', 'evidence' => 'خطة الإدارة ٢٠٢٦ — معتمدة ١٤/٩'];
        $answers[$claims['goals.ann']->id] = ['claim' => 'yes', 'evidence' => 'اجتماع الإدارة ٢٠/٩'];
        $answers[$claims['rules.doc']->id] = ['claim' => 'yes', 'evidence' => ''];
        $this->post(route('forms.submit', $card), ['fields' => $answers, 'photos' => [$claims['rules.doc']->id => UploadedFile::fake()->createWithContent('procedures.pdf', "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF")]])
            ->assertSessionHasNoErrors()->assertRedirect();

        $s = FormSubmission::withoutGlobalScope('building')->firstOrFail();
        $by = $s->answers->keyBy('field_id');
        $this->assertSame(['claim' => 'yes', 'evidence' => 'خطة الإدارة ٢٠٢٦ — معتمدة ١٤/٩'], $by[$claims['goals.doc']->id]->claimValue());
        $this->assertSame('no', $by[$claims['goals.und']->id]->claimValue()['claim']);
        $this->assertTrue($by[$claims['rules.doc']->id]->hasFile(), 'الوثيقة المرفوعة محفوظة');
        $this->assertSame('procedures.pdf', $by[$claims['rules.doc']->id]->file_name);
        $this->assertSame('yes', $by[$claims['risks.doc']->id]->claimValue()['claim'], '«موثّق» المخاطر نعم من السجل لا من الشاشة');
        $this->assertStringContainsString('خطراً مفعّلاً', $by[$claims['risks.doc']->id]->claimValue()['evidence']);
        $this->assertSame(28, $s->answers->count());

        $this->actingAs($this->salama);
        $r = $this->get(route('forms.results', $card));
        $r->assertOk()->assertSee('خطة الإدارة ٢٠٢٦')->assertSee('procedures.pdf')->assertSee('خطراً مفعّلاً');
        $summary = collect($this->forms->resultsSummary($card, $this->salama))->keyBy(fn ($x) => $x['field']->id);
        $this->assertSame(1, $summary[$claims['goals.doc']->id]['distribution']['نعم']);
        $this->assertSame(1, $summary[$claims['goals.und']->id]['distribution']['لا']);
        $csv = $this->get(route('forms.export', $card))->streamedContent();
        $this->assertStringContainsString('نعم — خطة الإدارة ٢٠٢٦', $csv);
    }
}
