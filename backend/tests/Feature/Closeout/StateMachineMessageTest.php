<?php

namespace Tests\Feature\Closeout;

use App\Core\StateMachine\Exceptions\TransitionException;
use App\Core\StateMachine\StateMachine;
use App\Modules\Emergency\StateMachines\EmergencyStateMachine;
use App\Modules\Incident\StateMachines\IncidentStateMachine;
use App\Modules\Permit\StateMachines\PermitStateMachine;
use App\Modules\Project\StateMachines\ProjectContractorStateMachine;
use App\Modules\Risk\StateMachines\RiskStateMachine;
use Tests\TestCase;

/**
 * المرحلة ٨-٤ — تعريب رسائل آلة الحالة.
 *
 * الخلل (وُجد أثناء بوابة ٧-ب): المستخدم العربي يرى
 * «Cannot transition from 'field_received' to 'received'» — لغة أجنبية ومفاتيح داخلية.
 */
class StateMachineMessageTest extends TestCase
{
    public function test_message_is_arabic_with_state_names(): void
    {
        $message = (new IncidentStateMachine())->refusalMessage('field_received', 'received');

        $this->assertStringContainsString('استلمه الفني', $message);
        $this->assertStringContainsString('وصل المركز', $message);
        $this->assertStringNotContainsString('Cannot transition', $message);
        $this->assertStringNotContainsString('field_received', $message);
    }

    public function test_message_lists_what_is_actually_available(): void
    {
        $message = (new PermitStateMachine())->refusalMessage('draft', 'active');

        $this->assertStringContainsString('مسودة', $message);
        $this->assertStringContainsString('المتاح من هنا', $message);
        $this->assertStringContainsString('مقدَّم', $message);
    }

    public function test_validate_throws_the_arabic_message(): void
    {
        $this->expectException(TransitionException::class);
        $this->expectExceptionMessageMatches('/لا يصح الانتقال/u');

        (new IncidentStateMachine())->validate('closed', 'new');
    }

    public function test_every_module_state_machine_speaks_arabic(): void
    {
        $cases = [
            [new IncidentStateMachine(), 'closed', 'new'],
            [new PermitStateMachine(), 'draft', 'active'],
            [new EmergencyStateMachine(), 'ended', 'active'],
            [new RiskStateMachine(), 'closed', 'draft'],
            [new ProjectContractorStateMachine(), 'draft', 'post_approved'],
        ];

        foreach ($cases as [$machine, $from, $to]) {
            $message = $machine->refusalMessage($from, $to);
            $this->assertStringStartsWith('لا يصح الانتقال', $message, $machine::class);
            $this->assertStringNotContainsString('Cannot transition', $message, $machine::class);
        }
    }

    public function test_a_dead_end_state_says_so(): void
    {
        // حالة بلا انتقالات: الرسالة تقولها بدل أن تعرض قائمة فارغة
        $machine = new StateMachine(['a' => ['b' => ['any']]], ['a' => 'ألف', 'b' => 'باء']);

        $this->assertStringContainsString('لا انتقال متاح', $machine->refusalMessage('b', 'a'));
    }

    public function test_unknown_state_falls_back_to_its_key(): void
    {
        $machine = new StateMachine(['a' => ['b' => ['any']]]);

        $this->assertSame('a', $machine->label('a'));
        $this->assertStringContainsString('«a»', $machine->refusalMessage('b', 'a'));
    }
}
