<?php

namespace App\Modules\Project\Events;

use App\Modules\Project\Models\ProjectContractor;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired the first time a contractor is attached to a project.
 *
 * Listeners materialise the qualification permits (pre + post) and
 * their checklists so the contractor's journey is queued up as soon
 * as the row exists — nothing manual.
 */
class ProjectContractorCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ProjectContractor $projectContractor,
        public readonly ?int $actorId = null,
    ) {}
}
