<?php

namespace Tests\Feature\Incident;

use App\Modules\Incident\Models\Incident;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * منقول من OHSMS بلا tenant. الفروق المعهدية: النموذج العام يطلب المكان والوصف (العنوان يُشتق)،
 * الخطر إلزامي إلا في السري، كل بلاغ ضيف له رمز تتبع، لا نقطة assign (حلّت محلها refer بـ field_worker_id)،
 * وبلا فني معروف يتوقف البلاغ عند «وصل المركز».
 */
class IncidentControllerTest extends TestCase
{
    use RefreshDatabase, IncidentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
    }

    // ===========================================================
    // الصفحات العامة (بلا دخول)
    // ===========================================================

    public function test_normal_form_renders_publicly(): void
    {
        $this->get('/incident/normal')->assertOk()->assertSee('name="risk_id"', false);
    }

    public function test_normal_store_requires_title_and_description(): void
    {
        // المعهد: العنوان اختياري (يُشتق من الخطر والمكان)؛ الإلزامي: الوصف والمكان والخطر
        $this->post('/incident/normal', [])
            ->assertSessionHasErrors(['description', 'risk_id'])
            ->assertSessionDoesntHaveErrors('title');
        // المكان إلزامي في النموذج (required في الواجهة) — لكن IncidentController::store يتحقق منه nullable:
        // ضيف يتجاوز الواجهة يرسل بلاغاً بلا مكان فلا يوجَّه آلياً. يبقى هذا التأكيد حتى يُحسم في المتحكم.
        $this->post('/incident/normal', [])->assertSessionHasErrors('place_id');
    }

    public function test_normal_store_creates_incident_publicly(): void
    {
        $risk = $this->makeRisk();

        $response = $this->post('/incident/normal', [
            'title' => 'سقوط أداة من ارتفاع',
            'description' => 'سقطت أداة من الطابق الثاني',
            'place_id' => $this->placeId('HZ-06'),
            'risk_id' => $risk->id,
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('/incident/success', $response->headers->get('Location'));
        // لا فني معروف للمكان ولا على الخطر → يتوقف عند «وصل المركز» (لا يُحال آلياً بلا فني)
        $this->assertDatabaseHas('incidents', [
            'title' => 'سقوط أداة من ارتفاع',
            'incident_type' => 'normal',
            'risk_id' => $risk->id,
            'status' => 'received',
        ]);
        $incident = Incident::first();
        $this->assertNull($incident->incident_field_team_id);
        $this->assertNull($incident->actor_id);
        // بلاغ ضيف: رمز تتبع ويظهر في وجهة التحويل
        $this->assertNotEmpty($incident->secret_tracking_code);
        $this->assertStringContainsString($incident->secret_tracking_code, $response->headers->get('Location'));
    }

    public function test_urgent_form_renders_publicly(): void
    {
        $this->get('/incident/urgent')->assertOk();
    }

    public function test_urgent_store_creates_urgent_incident(): void
    {
        $risk = $this->makeRisk();

        $response = $this->post('/incident/urgent', [
            'title' => 'حريق صغير في الورشة',
            'description' => 'اندلع حريق في زاوية الورشة',
            'place_id' => $this->placeId('HZ-06'),
            'risk_id' => $risk->id,
        ]);
        $response->assertRedirect();
        $this->assertStringContainsString('/incident/success', $response->headers->get('Location'));

        $this->assertDatabaseHas('incidents', [
            'incident_type' => 'urgent',
            'title' => 'حريق صغير في الورشة',
        ]);
    }

    public function test_secret_form_renders_publicly(): void
    {
        $this->get('/incident/secret')->assertOk();
    }

    public function test_secret_store_returns_tracking_code(): void
    {
        // الخطر اختياري في السري
        $response = $this->post('/incident/secret', [
            'description' => 'وصف البلاغ السري',
            'place_id' => $this->placeId('HZ-06'),
            'secrecy_reason' => 'حماية المُبلّغ',
        ]);

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/incident/success', $location);

        $incident = Incident::where('incident_type', 'secret')->latest('id')->first();
        $this->assertNotNull($incident);
        $this->assertNotNull($incident->secret_tracking_code);
        $this->assertNotNull($incident->secret_key);
        $this->assertNull($incident->risk_id);
        $this->assertStringContainsString($incident->secret_tracking_code, $location);
        // السري بلا سجل تدقيق
        $this->assertDatabaseMissing('audit_logs', ['model_name' => 'Incident']);
    }

    public function test_secret_track_with_valid_code_finds_incident(): void
    {
        $incident = $this->makeIncident([
            'incident_type' => 'secret',
            'risk_id' => null,
            'secret_key' => 'sk_test',
            'secret_tracking_code' => 'TRK12345',
        ]);

        $this->post('/incident/track', ['tracking_code' => 'TRK12345'])->assertOk()->assertSee($incident->code);
        $this->get('/incident/track?code=TRK12345')->assertOk()->assertSee($incident->code);

        // المعهد: التتبع بالرمز لكل الأنواع لا للسري فقط
        $normal = $this->makeIncident(['secret_tracking_code' => 'NRM12345']);
        $this->post('/incident/track', ['tracking_code' => 'nrm12345'])->assertOk()->assertSee($normal->code);
    }

    public function test_secret_track_with_invalid_code_returns_error(): void
    {
        $response = $this->post('/incident/track', ['tracking_code' => 'NOPENOPENO']);
        $response->assertSessionHasErrors('tracking_code');
    }

    // ===========================================================
    // القائمة والتفاصيل بعد الدخول + الأدوار
    // ===========================================================

    public function test_unauthenticated_user_redirected_from_index(): void
    {
        $this->get(route('incidents.index'))->assertRedirect('/login');
    }

    public function test_field_worker_can_view_index(): void
    {
        // الفني له incident.list ليرى طابور بلاغاته
        $this->actingAsRole('field_worker');
        $this->get(route('incidents.index'))->assertOk();
    }

    public function test_system_admin_can_view_index(): void
    {
        $this->actingAsRole('system_admin');
        $this->get(route('incidents.index'))->assertOk();
    }

    public function test_safety_committee_can_view_index(): void
    {
        $this->actingAsRole('safety_committee');
        $this->get(route('incidents.index'))->assertOk();
    }

    public function test_show_blocks_user_who_lacks_visibility(): void
    {
        // مدير قسم له incident.list لكن خدمة الرؤية تحجب ما ليس في وحدته ولا معيَّناً عليه
        $this->actingAsRole('section_manager');
        $incident = $this->makeIncident();

        $this->get(route('incidents.show', $incident))->assertForbidden();
    }

    public function test_system_admin_can_view_any_incident(): void
    {
        $this->actingAsRole('system_admin');
        $incident = $this->makeIncident();

        $this->get(route('incidents.show', $incident))->assertOk();
    }

    public function test_field_worker_of_same_place_can_view_incident(): void
    {
        // المعهد: فني المكان (user_profiles.place_id) يرى بلاغات مكانه، وفني مكان آخر لا
        $incident = $this->makeIncident(['place_id' => $this->placeId('HZ-06')]);

        $this->actingAsRole('field_worker', 'HZ-06');
        $this->get(route('incidents.show', $incident))->assertOk();

        $this->actingAsRole('field_worker', 'HZ-01');
        $this->get(route('incidents.show', $incident))->assertForbidden();
    }

    // ===========================================================
    // صلاحيات إجراءات المعالجة
    // ===========================================================

    public function test_field_worker_can_now_manage(): void
    {
        // الفني له incident.manage ليبدأ المعالجة ويعلّم «عولج»
        $this->actingAsRole('field_worker');
        $incident = $this->makeIncident();

        // النقطة متاحة للفني (وإن رفضتها آلة الحالة)
        $this->post(route('incidents.beginWork', $incident))->assertStatus(302);
    }

    public function test_safety_coordinator_can_access_manage_endpoints(): void
    {
        $this->actingAsRole('safety_coordinator');
        $incident = $this->makeIncident();

        // المنسق يصل إلى نقطة التحقق (الوصول لا النتيجة)
        $this->post(route('incidents.verify', $incident))->assertStatus(302);
    }

    // ===========================================================
    // دورة الحياة عبر HTTP
    // ===========================================================

    public function test_admin_can_assign_and_add_notes_via_http(): void
    {
        $admin = $this->actingAsRole('system_admin');
        $assignee = $this->makeUser('field_worker');

        $incident = $this->makeIncident();

        // الإحالة يدوية من المركز إلى فني (حلّت محل assign)
        $this->post(route('incidents.refer', $incident), [
            'field_worker_id' => $assignee->id,
        ])->assertSessionHas('success');
        $incident->refresh();
        $this->assertSame($assignee->id, $incident->assigned_to_id);
        $this->assertSame($assignee->id, $incident->incident_field_team_id);
        $this->assertSame($admin->id, $incident->assigned_by_id);
        $this->assertNotNull($incident->assigned_at);
        $this->assertSame('forwarded', $incident->status);
        $this->assertDatabaseHas('incident_events', ['incident_id' => $incident->id, 'action' => 'assign']);

        // أي دور له incident.list يضيف ملاحظة
        $this->post(route('incidents.addNote', $incident), [
            'note' => 'تم استلام البلاغ من قسم السلامة',
        ])->assertSessionHas('success');
        $this->assertDatabaseHas('incident_events', [
            'incident_id' => $incident->id,
            'action' => 'note',
        ]);
    }

    public function test_assign_validates_assigned_to_exists(): void
    {
        $this->actingAsRole('system_admin');
        $incident = $this->makeIncident();

        $this->post(route('incidents.refer', $incident), [
            'field_worker_id' => 999999,
        ])->assertSessionHasErrors('field_worker_id');
    }

    public function test_link_risk_validates_risk_exists(): void
    {
        $this->actingAsRole('system_admin');
        $incident = $this->makeIncident();

        $this->post(route('incidents.linkRisk', $incident), [
            'risk_id' => 999999,
        ])->assertSessionHasErrors('risk_id');
    }

    public function test_add_note_requires_note_text(): void
    {
        $this->actingAsRole('system_admin');
        $incident = $this->makeIncident();

        $this->post(route('incidents.addNote', $incident), [])
            ->assertSessionHasErrors('note');
    }

    // ===========================================================
    // تصدير CSV
    // ===========================================================

    public function test_export_returns_csv_stream(): void
    {
        $this->actingAsRole('system_admin');
        $this->makeIncident(['title' => 'incident-export-test']);

        $response = $this->get(route('incidents.export'));
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $this->assertStringContainsString('incident-export-test', $content);
        $this->assertStringContainsString('الرقم,العنوان', $content);
    }
}
