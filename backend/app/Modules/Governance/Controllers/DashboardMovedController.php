<?php

namespace App\Modules\Governance\Controllers;

use App\Core\Permissions\PermissionRegistry;
use App\Http\Controllers\Controller;
use App\Modules\Governance\Models\Place;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * المرحلة ١٩-٧ (قرار ٤٨): «العمل اليومي» مخفية. الملف باقٍ في المستودع بلا تعديل ولا يُنسخ إلى public/،
 * وهذا المسار يستقبل الروابط القديمة (إشعارات سابقة، مفضّلات) ويحوّلها إلى مقابلها في الخلفية.
 * الوسم (#place=HZ-xx&sys=form:k) لا يصل الخادم، فتحمل الصفحة خريطة الأماكن ويحوّل المتصفح.
 */
class DashboardMovedController extends Controller
{
    public function __invoke(Request $request): View
    {
        $map = ['places' => [], 'home' => '/app'];
        foreach (Place::all() as $p) {
            $map['places'][$p->code] = '/app/places/'.$p->id.'/file';
        }
        if (PermissionRegistry::hasPermission($request->user()->role(), 'system.org')) {
            $map['depts'] = '/app/org';
        }
        return view('governance.dashboard_moved', ['map' => $map]);
    }
}
