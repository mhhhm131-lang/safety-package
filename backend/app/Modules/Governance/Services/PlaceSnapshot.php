<?php

namespace App\Modules\Governance\Services;

use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Governance\Models\Place;
use App\Modules\Incident\Models\Incident;
use App\Modules\Permit\Models\Permit;
use App\Modules\Store\Services\InspectionDocReader;

/**
 * المرحلة ٢٥-٢ (قرار ٦٤): مصدر واحد لأرقام «حال الآن» لكل مكان — ستة أرقام من المصادر القائمة، بلا جدول جديد:
 * بلاغات شاغلين مفتوحة (Incident خارج TERMINAL) · بلاغات فحص مفتوحة ومتأخرها (placeTiles) · جولات مستحقة
 * (اتحاد dueRounds لأدوار الواجهة الأربعة بلا تكرار) · حالات طارئة مفتوحة (open، لا تمرين) · تصاريح فعالة.
 * النطاق من ScopeService: الموظف مكانه، الفني ما يغطيه، مدير الفرع فرعه، القيادة الكل.
 */
final class PlaceSnapshot
{
    /** المفاتيح الستة بترتيب الأعمدة: [العنوان، هل الرقم تحذير حين يزيد عن صفر] */
    public const KEYS = [
        'incidents' => ['بلاغات شاغلين', false],
        'reports'   => ['بلاغات فحص', false],
        'overdue'   => ['متأخر', true],
        'rounds'    => ['جولات مستحقة', false],
        'emergency' => ['حالات طارئة', true],
        'permits'   => ['تصاريح فعالة', false],
    ];

    private const DECISION = ['fm', 'adm', 'exec', 'safety'];

    /**
     * @return array{all:bool,decision:bool,places:array<string,array{code:string,id:int,name:string,file:string,n:array<string,int>,links:array<string,?string>}>,total:array<string,int>,links:array<string,?string>}
     */
    public static function forUser(User $user): array
    {
        $scope = ScopeService::forUser($user);
        $decision = in_array(PermissionRegistry::uiRole($user->role()), self::DECISION, true);
        $places = $scope->isAll() ? Place::orderBy('sort')->get() : $scope->places();
        $out = self::forPlaces($places, $decision);
        $out['all'] = $scope->isAll();
        return $out;
    }

    /** @param iterable<Place> $places */
    public static function forPlaces(iterable $places, bool $decision): array
    {
        $places = collect($places)->values();
        $ids = $places->pluck('id')->all();

        $incidents = Incident::query()->whereNotIn('status', Incident::TERMINAL)->whereIn('place_id', $ids)
            ->selectRaw('place_id, COUNT(*) as c')->groupBy('place_id')->pluck('c', 'place_id')->all();
        $emergencies = EmergencyIncident::query()->open()->where('is_drill', false)->whereIn('place_id', $ids)
            ->selectRaw('place_id, COUNT(*) as c')->groupBy('place_id')->pluck('c', 'place_id')->all();
        $permits = Permit::query()->where('status', Permit::STATUS_ACTIVE)->whereIn('place_id', $ids)
            ->selectRaw('place_id, COUNT(*) as c')->groupBy('place_id')->pluck('c', 'place_id')->all();
        $tiles = InspectionDocReader::placeTiles();
        $rounds = self::dueRoundsByPlace();

        $rows = [];
        $total = array_fill_keys(array_keys(self::KEYS), 0);
        foreach ($places as $p) {
            $n = [
                'incidents' => (int) ($incidents[$p->id] ?? 0),
                'reports'   => (int) ($tiles[$p->code]['open'] ?? 0),
                'overdue'   => (int) ($tiles[$p->code]['od'] ?? 0),
                'rounds'    => (int) ($rounds[$p->code] ?? 0),
                'emergency' => (int) ($emergencies[$p->id] ?? 0),
                'permits'   => (int) ($permits[$p->id] ?? 0),
            ];
            foreach ($n as $k => $v) $total[$k] += $v;
            // ٢٦-٧: مربع «مركز السلامة» يفتح صفحة المركز الواحدة (فيها بنود المكان بأسفلها بالمعرّفات نفسها)
            $file = $p->code === 'HZ-00' ? route('emergency.dashboard') : route('app.places.units.file', $p);
            $rows[$p->code] = ['code' => $p->code, 'id' => $p->id, 'name' => $p->name, 'file' => $file, 'n' => $n, 'links' => self::links($p->code, $file)];
        }

        // روابط الإجمالي: مكان واحد = روابطه؛ وإلا قوائم المبنى (بلاغات الفحص لأدوار القرار وحدها)
        if (count($rows) === 1) {
            $links = reset($rows)['links'];
        } else {
            $links = self::links(null, null);
            if (!$decision) $links['reports'] = $links['overdue'] = null;
        }

        return ['all' => false, 'decision' => $decision, 'places' => $rows, 'total' => $total, 'links' => $links];
    }

    /** الجولات المستحقة في كل مكان لكل أدوار الواجهة، بلا تكرار (مكان × نظام × دورية × مهمة) */
    private static function dueRoundsByPlace(): array
    {
        $seen = [];
        foreach (array_keys(InspectionDocReader::WHO_ROLE) as $ui) {
            foreach (InspectionDocReader::dueRounds($ui) as $r) {
                $seen[$r['hz'].'|'.$r['k'].'|'.$r['freq'].'|'.$r['task']] = $r['hz'];
            }
        }
        return array_count_values($seen);
    }

    /** رابط قائمة كل عمود: بالمكان إن حُدد، وإلا المبنى كله */
    private static function links(?string $code, ?string $file): array
    {
        $q = fn (array $extra) => array_filter(['place' => $code] + $extra);
        return [
            'incidents' => route('incidents.index', $q(['status' => 'open'])),
            'reports'   => $file ? $file.'#pfReports' : route('app.places.units.hub', ['k' => 'open']),
            'overdue'   => $file ? $file.'#pfReports' : route('app.places.units.hub', ['k' => 'od']),
            'rounds'    => $file ? $file.'#pfSystems' : route('app.home').'#inboxList',
            'emergency' => route('emergency.incidents.index', $q([])),
            'permits'   => route('permits.index', $q(['status' => 'active'])),
        ];
    }
}
