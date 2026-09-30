<?php

namespace App\Modules\Governance\Inbox;

use App\Core\Inbox\Task;
use App\Core\Inbox\TaskSource;
use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Support\Collection;

/**
 * المرحلة ٢٠-٤-ب (قرار ٥٢): «حساب ينتظر اعتمادك» — لمن يملك اعتماد الحسابات (مسؤول السلامة).
 * زر «اعتمد» يفعّل الحساب، و«أعِده» يرجعه إلى من سجّله؛ والسبب من قائمة المستخدمين.
 */
class AccountApprovalTasks implements TaskSource
{
    public function tasksFor(User $user): Collection
    {
        // ٢٧-ب (قرار ٦٧): حساب أعاده مسؤول السلامة ← يصل من سجّله بسبب الإعادة؛ تصحيحه يعيده للاعتماد
        $returned = UserProfile::where('pending_by_id', $user->id)->whereNull('pending_since')->whereNotNull('return_note')->where('is_active', false)
            ->with('user')->get()->toBase()->filter(fn (UserProfile $p) => $p->user)
            ->map(fn (UserProfile $p) => new Task(
                key: 'account:'.$p->user_id.':returned',
                module: 'الحسابات',
                question: 'الحساب «'.$p->user->name.'» ('.PermissionRegistry::getRoleDisplayName($p->role).') أُعيد إليك: '.$p->return_note.' — صحّحه وأعد تقديمه',
                primary: ['label' => 'صحّحه', 'url' => route('app.users.edit', $p->user_id, false)],
                detailsUrl: route('app.users.edit', $p->user_id, false),
                createdAt: $p->updated_at,
            ))->values();
        if (!PermissionRegistry::hasPermission($user->role(), 'system.users.approve')) return $returned;
        return $returned->merge(UserProfile::whereNotNull('pending_since')->with(['user', 'pendingBy'])->orderBy('pending_since')->get()
            ->filter(fn (UserProfile $p) => $p->user)
            ->map(fn (UserProfile $p) => new Task(
                key: 'account:'.$p->user_id,
                module: 'الحسابات',
                question: $p->user->name.' — '.PermissionRegistry::getRoleDisplayName($p->role).($p->job_title ? ' · '.$p->job_title : '')
                    .' — سجّله '.($p->pendingBy?->name ?? 'النظام').($p->pendingBy ? ' ('.$p->pendingBy->roleName().')' : '').' وينتظر اعتمادك'
                    .($p->pending_note ? ' · '.$p->pending_note : ''),
                primary: ['label' => 'اعتمد', 'url' => route('app.users.approve', $p->user_id, false), 'method' => 'POST'],
                secondary: ['label' => 'أعِده', 'url' => route('app.users.return', $p->user_id, false), 'method' => 'POST'],
                place: $p->building?->name,
                detailsUrl: route('app.users.edit', $p->user_id, false),
                createdAt: $p->pending_since,
            ))->values());
    }
}
