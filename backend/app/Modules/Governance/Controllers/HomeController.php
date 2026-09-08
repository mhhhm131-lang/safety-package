<?php

namespace App\Modules\Governance\Controllers;

use App\Core\Permissions\PermissionRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** الصفحة الأولى بعد الدخول لشاشات الوحدات: روابط ما يملك المستخدم صلاحيته. */
class HomeController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $role = $user->role();
        $cards = [];
        if (PermissionRegistry::uiRole($role)) {
            $cards[] = ['title' => 'العمل اليومي', 'desc' => 'اللوحة: البلاغات والتصعيد، مهامي، ملف المكان', 'url' => '/dashboard.html', 'icon' => 'bi-speedometer2'];
        }
        if (PermissionRegistry::hasPermission($role, 'system.users')) {
            $cards[] = ['title' => 'المستخدمون', 'desc' => 'الحسابات والأدوار', 'url' => route('app.users.index'), 'icon' => 'bi-people'];
        }
        if (PermissionRegistry::hasPermission($role, 'system.org')) {
            $cards[] = ['title' => 'الهيكل التنظيمي', 'desc' => 'الإدارات والأقسام وأماكنها', 'url' => route('app.org.index'), 'icon' => 'bi-diagram-3'];
        }
        if (PermissionRegistry::hasPermission($role, 'system.settings')) {
            $cards[] = ['title' => 'الأماكن', 'desc' => 'الأماكن التسعة (٨+١)', 'url' => route('app.places.index'), 'icon' => 'bi-geo-alt'];
        }
        if (PermissionRegistry::hasPermission($role, 'system.audit')) {
            $cards[] = ['title' => 'سجل التدقيق', 'desc' => 'من فعل ماذا ومتى', 'url' => route('app.audit'), 'icon' => 'bi-journal-text'];
        }
        $cards[] = ['title' => 'الوثائق', 'desc' => 'المنظومة: الخطط والبطاقات والنماذج — مفتوحة للجميع', 'url' => '/index.html', 'icon' => 'bi-folder2-open'];

        return view('governance.home', compact('cards'));
    }
}
