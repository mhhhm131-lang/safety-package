<?php

namespace Tests\Feature\Project;

use App\Models\User;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Project\Models\ExternalParty;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;


/** منقول من اختبارات OHSMS بلا tenant (المرحلة ٦). ما حُذف: اختبارات عزل المستأجرين. */
class ExternalPartyControllerTest extends TestCase
{
    use RefreshDatabase;


    protected function setUp(): void
    {
        parent::setUp();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        UserProfile::create(['user_id' => $user->id, 'role' => $role, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function makeParty(int $userId, array $overrides = []): ExternalParty
    {
        return ExternalParty::factory()->create(array_merge([
            'created_by_id' => $userId,
        ], $overrides));
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'شركة المقاولات المتحدة',
            'name_en' => 'United Contracting Co.',
            'party_type' => 'contractor',
            'contact_person' => 'أحمد علي',
            'email' => 'contact@united.example',
            'phone' => '0501112233',
            'cr_number' => 'CR-12345',
        ], $overrides);
    }

    // ---- Auth & RBAC ----

    public function test_unauthenticated_redirected(): void
    {
        $this->get(route('external-parties.index'))->assertRedirect();
    }

    public function test_field_worker_forbidden(): void
    {
        $this->actingAsRole('field_worker');
        $this->get(route('external-parties.index'))->assertForbidden();
    }

    public function test_safety_coordinator_can_view_index(): void
    {
        $this->actingAsRole('safety_coordinator');
        $this->get(route('external-parties.index'))->assertOk();
    }

    public function test_safety_coordinator_forbidden_from_create(): void
    {
        // external_party.create restricted to system_admin/system_staff
        $this->actingAsRole('safety_coordinator');
        $this->get(route('external-parties.create'))->assertForbidden();
    }

    public function test_system_admin_can_create(): void
    {
        $this->actingAsRole('system_admin');
        $this->get(route('external-parties.create'))->assertOk();
    }

    // ---- Store / validation ----

    public function test_store_requires_name_and_party_type(): void
    {
        $this->actingAsRole('system_admin');
        $this->post(route('external-parties.store'), [])
            ->assertSessionHasErrors(['name', 'party_type']);
    }

    public function test_store_creates_party(): void
    {
        $admin = $this->actingAsRole('system_admin');
        $this->post(route('external-parties.store'), $this->validPayload())
            ->assertRedirect(); // المعهد: إلى صفحة الطرف

        $this->assertDatabaseHas('external_parties', [
            'name' => 'شركة المقاولات المتحدة',
            'created_by_id' => $admin->id,
        ]);
    }

    // ---- Show / Tenant isolation ----

    public function test_show_renders_party(): void
    {
        $admin = $this->actingAsRole('system_admin');
        $party = $this->makeParty($admin->id);
        $this->get(route('external-parties.show', $party))->assertOk();
    }


    public function test_update_persists_changes(): void
    {
        $admin = $this->actingAsRole('system_admin');
        $party = $this->makeParty($admin->id, ['name' => 'قديم']);

        $this->put(route('external-parties.update', $party), $this->validPayload([
            'name' => 'محدّث',
        ]))->assertRedirect(route('external-parties.show', $party));

        $this->assertSame('محدّث', $party->fresh()->name);
    }

    // ---- Documents ----

    public function test_documents_view_renders(): void
    {
        $admin = $this->actingAsRole('system_admin');
        $party = $this->makeParty($admin->id);
        $this->get(route('external-parties.documents', $party))->assertOk();
    }

    public function test_add_document_validates_type_enum(): void
    {
        $admin = $this->actingAsRole('system_admin');
        $party = $this->makeParty($admin->id);

        $this->post(route('external-parties.documents.store', $party), [
            'name' => 'doc',
            'document_type' => 'invalid_type',
        ])->assertSessionHasErrors('document_type');
    }

    public function test_add_document_creates_record(): void
    {
        $admin = $this->actingAsRole('system_admin');
        $party = $this->makeParty($admin->id);

        $this->post(route('external-parties.documents.store', $party), [
            'name' => 'شهادة السجل التجاري',
            'document_type' => 'cr',
            'expiry_date' => now()->addYear()->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('external_party_documents', [
            'external_party_id' => $party->id,
            'name' => 'شهادة السجل التجاري',
            'document_type' => 'cr',
            'uploaded_by_id' => $admin->id,
        ]);
    }

    // ---- Evaluations ----

    public function test_evaluation_store_calculates_overall_score(): void
    {
        $coordinator = $this->actingAsRole('safety_coordinator');
        $party = $this->makeParty($coordinator->id);

        $this->post(route('external-parties.evaluation.store', $party), [
            'period_from' => now()->subMonths(1)->toDateString(),
            'period_to' => now()->toDateString(),
            'safety_score' => 90,
            'compliance_score' => 80,
            'quality_score' => 70,
        ])->assertRedirect(route('external-parties.show', $party));

        // overall = (90 + 80 + 70) / 3 = 80.0
        $this->assertDatabaseHas('external_party_evaluations', [
            'external_party_id' => $party->id,
            'safety_score' => 90,
            'overall_score' => '80.0',
            'evaluated_by_id' => $coordinator->id,
        ]);
    }

    public function test_evaluation_validates_period_order(): void
    {
        $coordinator = $this->actingAsRole('safety_coordinator');
        $party = $this->makeParty($coordinator->id);

        $this->post(route('external-parties.evaluation.store', $party), [
            'period_from' => now()->toDateString(),
            'period_to' => now()->subDay()->toDateString(),
            'safety_score' => 80,
            'compliance_score' => 80,
            'quality_score' => 80,
        ])->assertSessionHasErrors('period_to');
    }

    public function test_evaluation_score_range_validation(): void
    {
        $coordinator = $this->actingAsRole('safety_coordinator');
        $party = $this->makeParty($coordinator->id);

        $this->post(route('external-parties.evaluation.store', $party), [
            'period_from' => now()->subDay()->toDateString(),
            'period_to' => now()->toDateString(),
            'safety_score' => 150, // out of range
            'compliance_score' => 80,
            'quality_score' => 80,
        ])->assertSessionHasErrors('safety_score');
    }
}
