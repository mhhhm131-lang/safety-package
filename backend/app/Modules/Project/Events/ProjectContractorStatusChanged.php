<?php

namespace App\Modules\Project\Events;

use App\Modules\Project\Models\ProjectContractor;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a ProjectContractor.qualification_status transition.
 *
 * Listeners cascade side effects: when a contractor becomes
 * post_approved, their work permits can move forward; when
 * suspended, active permits should be suspended too.
 */
class ProjectContractorStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ProjectContractor $projectContractor,
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly ?int $actorId = null,
    ) {}
}
