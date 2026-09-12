<?php

namespace App\Core\Inbox;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * مصدر مهام: كل وحدة تُجيب «ما ينتظر هذا الشخص عندي الآن؟» بإعادة استخدام استعلاماتها القائمة.
 * المصدر يحرس نفسه بالصلاحية، ولا يُرسل إشعاراً ولا يكتب شيئاً.
 */
interface TaskSource
{
    /** @return Collection<int, Task> */
    public function tasksFor(User $user): Collection;
}
