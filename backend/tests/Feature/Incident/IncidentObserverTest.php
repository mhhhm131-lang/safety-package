<?php

namespace Tests\Feature\Incident;

use App\Core\StateMachine\Exceptions\TransitionException;
use App\Modules\Incident\Models\Incident;
use App\Modules\Incident\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * IncidentObserver يفرض آلة الحالة عند طبقة النموذج — مستقلاً عن IncidentService. أي مسار يكتب `status`
 * عبر Eloquent (متحكم، tinker، بذرة، $model->save()) يُمنع إن لم يكن الانتقال معرَّفاً في IncidentStateMachine.
 */
class IncidentObserverTest extends TestCase
{
    use RefreshDatabase, IncidentFixtures;

    public function test_observer_blocks_illegal_transition_on_direct_update(): void
    {
        $incident = $this->makeIncident(['status' => 'new']);

        $incident->status = 'closed';

        $this->expectException(TransitionException::class);
        // الرسالة عُرّبت في المرحلة ٨-٤ وصارت بأسماء الحالات لا بمفاتيحها
        $this->expectExceptionMessage('لا يصح الانتقال من «جديد» إلى «مغلق»');

        $incident->save();
    }

    public function test_observer_blocks_backwards_jump(): void
    {
        $incident = $this->makeIncident(['status' => 'resolved']);

        $incident->status = 'new';

        $this->expectException(TransitionException::class);
        $incident->save();
    }

    public function test_observer_allows_legal_transition_on_direct_update(): void
    {
        $incident = $this->makeIncident(['status' => 'new']);

        $incident->status = 'received';
        $incident->save();

        $this->assertSame('received', $incident->fresh()->status);
    }

    public function test_observer_rejects_unknown_status_on_create(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown incident status 'bogus'");

        $this->makeIncident(['status' => 'bogus']);
    }

    public function test_observer_defaults_null_status_to_new_on_create(): void
    {
        $incident = new Incident([
            'title' => 'بلا حالة', 'description' => '...', 'incident_type' => 'normal',
            'risk_id' => $this->makeRisk()->id, 'status' => null,
        ]);
        $incident->save();

        $this->assertSame('new', $incident->fresh()->status);
    }

    public function test_transitioning_through_service_stamps_timestamp_columns(): void
    {
        $admin = $this->makeUser('system_admin');

        $incident = $this->makeIncident(['status' => 'new']);

        /** @var IncidentService $service */
        $service = app(IncidentService::class);

        $service->transition($incident, $admin->id, 'receive', 'received');
        $service->transition($incident->fresh(), $admin->id, 'refer', 'referred');

        $fresh = $incident->fresh();
        $this->assertNotNull($fresh->received_at, 'received_at should be stamped');
        $this->assertNotNull($fresh->referred_at, 'referred_at should be stamped');
        $this->assertNull($fresh->closed_at);
        $this->assertSame(['receive', 'refer'], $fresh->events()->orderBy('id')->pluck('action')->all());
    }
}
