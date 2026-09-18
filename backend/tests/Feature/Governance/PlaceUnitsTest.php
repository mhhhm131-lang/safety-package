<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\PlaceUnit;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ١٨-٣ (قرار ٤٧): وحدات الأماكن — صاحب المكان يُدخل وحداته مرة واحدة.
 * مدير المرافق ← كل ما يخص المبنى (والقاعات معه)؛ مدير الإدارة ← إدارته فقط (الدور والموقع)؛ مسؤول السلامة ← الكل؛ الفني قراءة.
 */
class PlaceUnitsTest extends TestCase
{
    use RefreshDatabase;

    private User $salama; private User $marafiq; private User $mudir; private User $mudir2; private User $fani;
    private Place $halls; private Place $offices; private Place $electric;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class]);
        $this->halls = Place::where('code', 'HZ-07')->first();
        $this->offices = Place::where('code', 'HZ-06')->first();
        $this->electric = Place::where('code', 'HZ-02')->first();
        $this->salama = $this->user('salama', 'system_admin');
        $this->marafiq = $this->user('marafiq', 'facilities_manager');
        $this->mudir = $this->user('mudir', 'department_manager', 'fin');
        $this->mudir2 = $this->user('mudir2', 'department_manager', 'it');
        $this->fani = $this->user('fani', 'field_worker');
    }

    private function user(string $username, string $role, ?string $unitCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true,
            'organization_unit_id' => $unitCode ? OrganizationUnit::where('code', $unitCode)->value('id') : null]);
        return $u;
    }

    /** مدير المرافق يضيف قاعة تدريب وغرفة كهرباء؛ نوع لا يخص المكان يُرفض؛ الفني يقرأ ولا يعدّل. */
    public function test_facilities_manager_adds_units_and_wrong_type_is_refused(): void
    {
        $this->actingAs($this->marafiq)->get("/app/places/{$this->halls->id}/units")->assertOk()->assertSee('أضف وحدة')->assertSee('قاعة تدريب');
        $this->actingAs($this->marafiq)->post("/app/places/{$this->halls->id}/units", ['type' => 'training_hall', 'name' => '٣١٢', 'floor' => '٣', 'capacity' => 25])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('place_units', ['place_id' => $this->halls->id, 'type' => 'training_hall', 'name' => '٣١٢', 'floor' => '٣', 'capacity' => 25]);
        $this->actingAs($this->marafiq)->post("/app/places/{$this->electric->id}/units", ['type' => 'electrical_room', 'name' => 'غرفة كهرباء ٢', 'floor' => 'القبو'])->assertSessionHas('success');
        // قاعة في مكان الكهرباء: مرفوض
        $this->actingAs($this->marafiq)->post("/app/places/{$this->electric->id}/units", ['type' => 'training_hall', 'name' => '٩'])->assertSessionHasErrors('type');
        // الفني يقرأ ولا يعدّل
        $this->actingAs($this->fani)->get("/app/places/{$this->halls->id}/units")->assertOk()->assertSee('٣١٢')->assertDontSee('أضف وحدة');
        $this->actingAs($this->fani)->post("/app/places/{$this->halls->id}/units", ['type' => 'training_hall', 'name' => '٤٠٠'])->assertForbidden();
        // المدخل يعرض العدّ
        $this->actingAs($this->marafiq)->get('/app/places/units')->assertOk()->assertSee('data-place="HZ-07"', false);
        $this->actingAs($this->marafiq)->get('/app')->assertOk()->assertSee('data-intent="places"', false); // ١٩-٤: النية صارت «الأماكن» لكل أدوار الواجهة
    }

    /** لصق جدول: ثلاث قاعات دفعة واحدة، والتكرار يحدّث لا يكرّر. */
    public function test_paste_three_halls_at_once(): void
    {
        $rows = "٣٠١، ٣، ٢٥\n٣٠٢\t٣\t٢٥\nقاعة الملك عبدالعزيز، الأرضي، ٥٠٠\n\n";
        $this->actingAs($this->marafiq)->post("/app/places/{$this->halls->id}/units/paste", ['type' => 'training_hall', 'rows' => $rows])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(3, PlaceUnit::where('place_id', $this->halls->id)->count());
        $this->assertDatabaseHas('place_units', ['name' => '٣٠٢', 'floor' => '٣', 'capacity' => 25]);
        $this->assertDatabaseHas('place_units', ['name' => 'قاعة الملك عبدالعزيز', 'capacity' => 500]);
        $this->actingAs($this->marafiq)->post("/app/places/{$this->halls->id}/units/paste", ['type' => 'training_hall', 'rows' => "٣٠١، ٣، ٣٠"]);
        $this->assertSame(3, PlaceUnit::where('place_id', $this->halls->id)->count());
        $this->assertDatabaseHas('place_units', ['name' => '٣٠١', 'capacity' => 30]);
        // الإدارات لا تُلصق
        $this->actingAs($this->salama)->post("/app/places/{$this->offices->id}/units/paste", ['type' => 'department', 'rows' => 'x'])->assertSessionHasErrors('type');
    }

    /** المكاتب: الإدارات من الهيكل؛ مدير الإدارة يرى إدارته فقط ويُدخل دورها وموقعها، ولا يعدّل إدارة غيره؛ مدير المرافق لا يعدّل الإدارات. */
    public function test_department_manager_sets_only_his_department_floor(): void
    {
        $fin = OrganizationUnit::where('code', 'fin')->first(); $it = OrganizationUnit::where('code', 'it')->first();
        $h = $this->actingAs($this->mudir)->get("/app/places/{$this->offices->id}/units")->assertOk()->getContent();
        $this->assertStringContainsString('data-dept="fin"', $h);
        $this->assertStringNotContainsString('data-dept="it"', $h);
        $this->actingAs($this->mudir)->post("/app/places/{$this->offices->id}/units", ['type' => 'department', 'organization_unit_id' => $fin->id, 'name' => 'x', 'floor' => '٦', 'location' => 'الجناح الشرقي'])->assertSessionHas('success');
        $this->assertDatabaseHas('place_units', ['type' => 'department', 'organization_unit_id' => $fin->id, 'name' => $fin->name, 'floor' => '٦', 'location' => 'الجناح الشرقي']);
        $this->actingAs($this->mudir)->post("/app/places/{$this->offices->id}/units", ['type' => 'department', 'organization_unit_id' => $it->id, 'name' => 'x', 'floor' => '٢'])->assertForbidden();
        $this->actingAs($this->marafiq)->post("/app/places/{$this->offices->id}/units", ['type' => 'department', 'organization_unit_id' => $it->id, 'name' => 'x', 'floor' => '٢'])->assertForbidden();
        // مسؤول السلامة يرى الكل ويعدّل الكل
        $this->actingAs($this->salama)->get("/app/places/{$this->offices->id}/units")->assertOk()->assertSee('data-dept="it"', false)->assertSee('data-dept="fin"', false);
        $this->actingAs($this->salama)->post("/app/places/{$this->offices->id}/units", ['type' => 'department', 'organization_unit_id' => $it->id, 'name' => 'x', 'floor' => '٢'])->assertSessionHas('success');
        // نية «موقع إدارتي في مكانها» تفتح مكان إدارته
        $this->actingAs($this->mudir)->get('/app')->assertOk()->assertSee('موقع إدارتي في مكانها')->assertSee('href="'.url("/app/places/{$this->offices->id}/units").'"', false);
    }

    /** التعديل والإزالة: الإزالة تخفي ولا تحذف. */
    public function test_update_and_soft_remove(): void
    {
        $this->actingAs($this->marafiq)->post("/app/places/{$this->halls->id}/units", ['type' => 'event_hall', 'name' => 'قاعة الاحتفالات الكبرى', 'capacity' => 500]);
        $u = PlaceUnit::first();
        $this->actingAs($this->marafiq)->put("/app/places/{$this->halls->id}/units/{$u->id}", ['name' => 'قاعة الاحتفالات الكبرى', 'floor' => 'الأرضي', 'capacity' => 550])->assertSessionHas('success');
        $this->assertSame(550, $u->fresh()->capacity);
        $this->actingAs($this->fani)->delete("/app/places/{$this->halls->id}/units/{$u->id}")->assertForbidden();
        $this->actingAs($this->marafiq)->delete("/app/places/{$this->halls->id}/units/{$u->id}")->assertSessionHas('success');
        $this->assertFalse($u->fresh()->is_active);
        $this->actingAs($this->marafiq)->get("/app/places/{$this->halls->id}/units")->assertOk()->assertDontSee('data-unit="'.$u->id.'"', false);
    }
}
