<?php

namespace Tests\Feature\Worker;

use App\Models\User;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Worker\Models\TrainingTopic;
use App\Modules\Worker\Models\Trade;
use App\Modules\Worker\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;


/** منقول من اختبارات OHSMS بلا tenant (المرحلة ٦). ما حُذف: اختبارات عزل المستأجرين. */
class CompetencyControllerTest extends TestCase
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

    public function test_unauthenticated_redirected(): void
    {
        $this->get(route('competency.matrix'))->assertRedirect();
    }

    public function test_field_worker_forbidden(): void
    {
        $this->actingAsRole('field_worker');
        $this->get(route('competency.matrix'))->assertForbidden();
    }

    public function test_safety_coordinator_can_view_matrix(): void
    {
        $this->actingAsRole('safety_coordinator');
        $this->get(route('competency.matrix'))->assertOk();
    }

    public function test_trades_view_renders(): void
    {
        $this->actingAsRole('safety_coordinator');
        Trade::factory()->create();
        $this->get(route('competency.trades'))->assertOk();
    }

    public function test_toggle_requirement_validates_inputs(): void
    {
        $this->actingAsRole('safety_coordinator');
        $this->post(route('competency.toggle-requirement'), [])
            ->assertSessionHasErrors(['trade_id', 'training_topic_id']);
    }

    public function test_toggle_requirement_creates_then_deletes(): void
    {
        $this->actingAsRole('safety_coordinator');
        $trade = Trade::factory()->create();
        $topic = TrainingTopic::create([
            'name' => 'سلامة السقالات',
            'code' => 'SCAF-01',
            'category' => 'high_risk',
        ]);

        // first call → creates
        $response = $this->postJson(route('competency.toggle-requirement'), [
            'trade_id' => $trade->id,
            'training_topic_id' => $topic->id,
        ]);
        $response->assertOk();
        $response->assertJsonFragment(['action' => 'created']);
        $this->assertDatabaseHas('competency_requirements', [
            'trade_id' => $trade->id,
            'training_topic_id' => $topic->id,
            'source' => 'trade_based',
        ]);

        // second call → deletes
        $response = $this->postJson(route('competency.toggle-requirement'), [
            'trade_id' => $trade->id,
            'training_topic_id' => $topic->id,
        ]);
        $response->assertOk();
        $response->assertJsonFragment(['action' => 'deleted']);
        $this->assertDatabaseMissing('competency_requirements', [
            'trade_id' => $trade->id,
            'training_topic_id' => $topic->id,
        ]);
    }

    public function test_safety_committee_forbidden_from_toggle(): void
    {
        // toggle requires competency.manage which excludes safety_committee
        $this->actingAsRole('safety_committee');
        $this->post(route('competency.toggle-requirement'), [
            'trade_id' => 1,
            'training_topic_id' => 1,
        ])->assertForbidden();
    }

    public function test_worker_compliance_renders(): void
    {
        $user = $this->actingAsRole('safety_coordinator');
        $worker = Worker::factory()->create([
            'created_by_id' => $user->id,
        ]);

        $this->get(route('competency.worker', $worker))->assertOk();
    }

    public function test_contractor_compliance_renders(): void
    {
        $this->actingAsRole('safety_coordinator');
        $party = ExternalParty::factory()->create();

        $this->get(route('competency.contractor', $party))->assertOk();
    }
}
