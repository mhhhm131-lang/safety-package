<?php

namespace App\Modules\Emergency\Services;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Emergency\Models\EmergencyTeamMember;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Store\Models\InstituteDocument;
use Illuminate\Support\Facades\DB;

/**
 * الطبقة صفر للفريق الأولي: ملف المكان في اللوحة (وثيقة ipa-place) هو الحقيقة، وemergency_teams تُشتق منه لا العكس
 * (BACKEND.md ٥-٣، الخطوة ٤-٥). لا تعديل في اللوحة.
 *
 * صيغة الوثيقة (dashboard.html: placeGet/unitGet/saveUnit):
 *   { "HZ-06": { plans:{sa,saBy,ra,drill}, units: { "<uid>": { team:[{role,name,dept,phone,trained,trainer}×4], nom:{by,dept,date}, appr:{by,date}, hr:{date}, dept } } } }
 *   uid = رمز الإدارة في المكاتب الإدارية، أو "_" في بقية الأماكن (وحدة واحدة ترشّحها الإدارة المشغّلة).
 *   المرحلة ١٥-٣: staff = عدد موظفي الوحدة في المكان (فريق لكل ٢٥)، more = [{team,nom,appr,hr}…] الفرق بعد الأول؛ unit_key للفريق الثاني "<uid>#2".
 * الأدوار الأربعة بترتيبها: المنسق، المسعف، المنقذ، الإطفائي.
 */
class TeamSync
{
    public const KEY = 'ipa-place';

    private const ROLE_KEYS = ['coordinator', 'medic', 'rescuer', 'firefighter'];

    /** يعيد عدد الفرق المشتقة بعد المزامنة. */
    public function sync(): int
    {
        $doc = InstituteDocument::where('key', self::KEY)->first();
        $data = $doc ? json_decode($doc->data, true) : [];
        if (!is_array($data)) $data = [];

        $building = EmergencyBuilding::main();
        $places = Place::all()->keyBy('code');
        $units = OrganizationUnit::all()->keyBy('code');
        $accounts = User::query()->get(['id', 'username', 'name'])->keyBy(fn ($u) => mb_strtolower($u->username));

        return DB::transaction(function () use ($data, $building, $places, $units, $accounts) {
            $seen = [];
            foreach ($data as $hz => $p) {
                if (!is_array($p) || !isset($places[$hz])) continue;
                $place = $places[$hz];
                $unitsDoc = $p['units'] ?? [];
                // الصيغة القديمة (فريق واحد على مستوى المكان) — كما يهاجرها placeGet في اللوحة
                if (!is_array($unitsDoc) || !$unitsDoc) {
                    if (!empty($p['team'])) $unitsDoc = ['_' => ['team' => $p['team'], 'nom' => $p['nom'] ?? [], 'appr' => $p['appr'] ?? [], 'hr' => $p['hr'] ?? []]];
                    else continue;
                }
                foreach ($unitsDoc as $uid => $unit) {
                    if (!is_array($unit)) continue;
                    $uid = (string) $uid;
                    $unitCode = $uid !== '_' ? $uid : (string) ($unit['dept'] ?? $unit['nom']['dept'] ?? '');
                    $orgUnit = $unitCode !== '' ? ($units[$unitCode] ?? null) : null;
                    $label = $orgUnit?->name ?? ($uid === '_' ? 'الإدارة المشغّلة للمكان' : $uid);

                    // المرحلة ١٥-٣ (قرار ٤٣): فريق لكل ٢٥ موظفاً — الأول في جذر الوحدة كما كان، والباقي في more بترتيبه
                    $teamsDoc = array_merge([$unit], array_values(array_filter((array) ($unit['more'] ?? []), 'is_array')));
                    foreach ($teamsDoc as $k => $u) {
                        $teamRows = is_array($u['team'] ?? null) ? $u['team'] : [];
                        $named = array_values(array_filter($teamRows, fn ($t) => is_array($t) && (trim((string) ($t['name'] ?? '')) !== '' || trim((string) ($t['user'] ?? '')) !== '')));
                        $nom = $u['nom'] ?? [];
                        if (!$named && empty($nom['date'])) continue; // لا ترشيح بعد: لا فريق

                        $team = EmergencyTeam::firstOrNew(['source' => 'place_profile', 'place_id' => $place->id, 'unit_key' => $k ? $uid.'#'.($k + 1) : $uid]);
                        $team->fill([
                            'building_id' => $building?->id,
                            'name' => 'الفريق الأولي — '.$place->name.($hz === 'HZ-06' || $orgUnit ? ' · '.$label : '')
                                .($k ? ' · الفريق '.strtr((string) ($k + 1), ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']) : ''),
                            'team_type' => EmergencyTeam::TYPE_INITIAL,
                            'description' => 'مشتق من ملف المكان في اللوحة (ترشيح مدير الإدارة ← اعتماد مدير الشؤون الإدارية والهندسية ← إحالة للموارد البشرية)',
                            'shift' => 'all',
                            'is_active' => count($named) > 0,
                            'organization_unit_id' => $orgUnit?->id,
                            'readiness' => $this->readiness($u),
                            'synced_at' => now(),
                        ]);
                        $team->save();
                        $seen[] = $team->id;

                        // الأعضاء الأربعة بترتيب الأدوار — يُعاد بناؤهم من الوثيقة (لا تحرير هنا)
                        $keep = [];
                        foreach (self::ROLE_KEYS as $i => $roleKey) {
                            $t = is_array($teamRows[$i] ?? null) ? $teamRows[$i] : [];
                            // المرحلة ١٥-٤: اسم الدخول بجانب الاسم يربط العضو بحسابه فيُنبَّه بخطوته (بلا حساسية لحالة الحروف؛ غير الموجود يبقى بلا ربط)
                            $account = $accounts[mb_strtolower(trim((string) ($t['user'] ?? '')))] ?? null;
                            $name = trim((string) ($t['name'] ?? '')) ?: (string) ($account?->name ?? '');
                            if ($name === '') continue;
                            $member = EmergencyTeamMember::firstOrNew(['team_id' => $team->id, 'role_key' => $roleKey]);
                            $member->fill([
                                'user_id' => $account?->id,
                                'name' => $name,
                                'role' => $roleKey === 'coordinator' ? 'leader' : 'member',
                                'department' => trim((string) ($t['dept'] ?? '')) ?: null,
                                'phone' => trim((string) ($t['phone'] ?? '')) ?: null,
                                'trained_at' => $this->date($t['trained'] ?? null),
                                'trainer' => trim((string) ($t['trainer'] ?? '')) ?: null,
                                'is_available' => true,
                            ]);
                            $member->save();
                            $keep[] = $member->id;
                        }
                        EmergencyTeamMember::where('team_id', $team->id)->whereNotIn('id', $keep)->delete();
                    }
                }
            }
            // فرق اختفت من الوثيقة تُعطَّل (لا تُحذف: لها سجل في الحالات السابقة)
            EmergencyTeam::where('source', 'place_profile')->whereNotIn('id', $seen)->update(['is_active' => false, 'synced_at' => now()]);
            return count($seen);
        });
    }

    private function readiness(array $u): string
    {
        if (!empty($u['hr']['date'])) return 'referred';
        if (!empty($u['appr']['date'])) return 'approved';
        if (!empty($u['nom']['date'])) return 'nominated';
        return 'none';
    }

    private function date($v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') return null;
        try {
            return \Carbon\Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
