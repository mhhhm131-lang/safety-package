<?php

namespace Tests\Feature\Project;

use App\Models\User;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Project\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\PlacesSeeder;
use Tests\TestCase;


/** منقول من اختبارات OHSMS بلا tenant (المرحلة ٦). ما حُذف: اختبارات عزل المستأجرين. */
class ProjectControllerTest extends TestCase
{
    use RefreshDatabase;


    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        UserProfile::create(['user_id' => $user->id, 'role' => $role, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function makeProject(int $userId, array $overrides = []): Project
    {
        return Project::factory()->create(array_merge([
            'created_by_id' => $userId,
        ], $overrides));
    }

    public function test_unauthenticated_redirected_from_index(): void
    {
        $this->get(route('projects.index'))->assertRedirect();
    }

    public function test_field_worker_forbidden(): void
    {
        $this->actingAsRole('field_worker');
        $this->get(route('projects.index'))->assertForbidden();
    }

    public function test_safety_coordinator_can_view_index(): void
    {
        $this->actingAsRole('safety_coordinator');
        $this->get(route('projects.index'))->assertOk();
    }

    public function test_safety_coordinator_forbidden_from_create(): void
    {
        // project.create is restricted to system_admin/system_staff
        $this->actingAsRole('safety_coordinator');
        $this->get(route('projects.create'))->assertForbidden();
    }

    public function test_system_admin_can_create(): void
    {
        $this->actingAsRole('system_admin');
        $this->get(route('projects.create'))->assertOk();
    }

    public function test_store_validation(): void
    {
        $this->actingAsRole('system_admin');
        $this->post(route('projects.store'), [])
            ->assertSessionHasErrors(['name', 'place_id']);
    }

    public function test_store_creates_project(): void
    {
        $admin = $this->actingAsRole('system_admin');
        $this->post(route('projects.store'), [
            'place_id' => \App\Modules\Governance\Models\Place::idByCode('HZ-06'), // المعهد: المكان إلزامي
            'name' => 'مشروع توسعة المبنى A',
            'code' => 'PRJ-EXT-001',
            'description' => 'توسعة الجناح الشمالي',
            'status' => 'active',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonths(6)->toDateString(),
        ])->assertRedirect();

        $this->assertDatabaseHas('projects', [
            'name' => 'مشروع توسعة المبنى A',
            'code' => 'PRJ-EXT-001',
        ]);
    }

    public function test_show_renders_project(): void
    {
        $user = $this->actingAsRole('safety_coordinator');
        $project = $this->makeProject($user->id);
        $this->get(route('projects.show', $project))->assertOk();
    }


    public function test_update_persists_changes(): void
    {
        $admin = $this->actingAsRole('system_admin');
        $project = $this->makeProject($admin->id, ['name' => 'قديم']);

        $this->put(route('projects.update', $project), [
            'place_id' => \App\Modules\Governance\Models\Place::idByCode('HZ-06'),
            'name' => 'محدّث',
            'description' => 'وصف جديد',
        ])->assertRedirect(route('projects.show', $project));

        $this->assertSame('محدّث', $project->fresh()->name);
    }

    public function test_dashboard_renders(): void
    {
        $user = $this->actingAsRole('safety_coordinator');
        $project = $this->makeProject($user->id);
        $this->get(route('projects.dashboard', $project))->assertOk();
    }

    public function test_manhour_store_validation(): void
    {
        $admin = $this->actingAsRole('system_admin');
        $project = $this->makeProject($admin->id);

        $this->post(route('projects.manhours.store', $project), [])
            ->assertSessionHasErrors(['date', 'workers_count', 'hours_worked']);
    }

    public function test_manhour_store_creates_record(): void
    {
        $admin = $this->actingAsRole('system_admin');
        $project = $this->makeProject($admin->id);

        $this->post(route('projects.manhours.store', $project), [
            'date' => now()->toDateString(),
            'workers_count' => 25,
            'hours_worked' => 200,
        ])->assertRedirect();

        $this->assertDatabaseHas('manhour_logs', [
            'project_id' => $project->id,
            'workers_count' => 25,
            'hours_worked' => 200,
        ]);
    }
}
