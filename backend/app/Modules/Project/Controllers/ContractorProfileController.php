<?php

namespace App\Modules\Project\Controllers;

use App\Core\Traits\AppliesOrgUnitScope;
use App\Http\Controllers\Controller;
use App\Modules\Project\Models\ContractorChannel;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Services\ContractorPreQualificationService;
use App\Modules\Project\Services\ContractorProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** ملف تأهيل المقاول: الفحوص الثمانية ودرجة الثقة، الإثراء من القنوات، رابط التعبئة؛ وإعدادات القنوات (بلا tenant). */
class ContractorProfileController extends Controller
{
    use AppliesOrgUnitScope;

    public function __construct(private readonly ContractorProfileService $profileService, private readonly ContractorPreQualificationService $qualService) {}

    public function show(ExternalParty $externalParty): View
    {
        $this->assertPartyAccess($externalParty->id);
        $profile = $this->profileService->getOrCreateProfile($externalParty);
        $assignment = $externalParty->projectAssignments()->latest()->first();
        $evaluation = $this->qualService->evaluate($externalParty, $assignment);
        $channelConfig = $this->profileService->getChannelConfig();
        $verifications = $externalParty->verifications()->latest('verified_at')->get();
        return view('modules.contractor_profile.show', compact('externalParty', 'profile', 'evaluation', 'channelConfig', 'verifications'));
    }

    /** تحديث حقول الملف يدوياً (تواريخ السجل والتأمين وISO…) — كانت تُملأ في OHSMS من القنوات فقط. */
    public function update(Request $request, ExternalParty $externalParty): RedirectResponse
    {
        $v = $request->validate([
            'cr_expiry_date' => 'nullable|date', 'insurance_policy_number' => 'nullable|string|max:100', 'insurance_provider' => 'nullable|string|max:150',
            'insurance_expiry_date' => 'nullable|date', 'iso_cert_number' => 'nullable|string|max:100', 'iso_cert_expiry_date' => 'nullable|date',
            'gosi_account_number' => 'nullable|string|max:50', 'etimad_entity_number' => 'nullable|string|max:50', 'muqawil_classification' => 'nullable|string|max:20',
        ]);
        $profile = $this->profileService->getOrCreateProfile($externalParty);
        $profile->fill($v);
        foreach (['cr' => 'cr_expiry_date', 'insurance' => 'insurance_expiry_date', 'iso_cert' => 'iso_cert_expiry_date', 'gosi' => 'gosi_account_number', 'etimad' => 'etimad_entity_number'] as $k => $f) {
            if ($profile->isDirty($f) && $profile->$f) $profile->{$k.'_verified_at'} = now();
        }
        $profile->save();
        return redirect()->route('external-parties.profile', $externalParty)->with('success', 'حُفظ ملف التأهيل.');
    }

    public function enrich(ExternalParty $externalParty): RedirectResponse
    {
        $updated = $this->profileService->enrich($externalParty);
        return redirect()->route('external-parties.profile', $externalParty)->with('success', "حُدّث {$updated} حقلاً من القنوات المفعّلة.");
    }

    public function generatePortalLink(ExternalParty $externalParty): RedirectResponse
    {
        $profile = $this->profileService->generatePortalToken($externalParty);
        $url = route('contractor-portal.show', ['token' => $profile->portal_link_token]);
        return redirect()->route('external-parties.profile', $externalParty)->with('portal_link', $url)
            ->with('success', 'أُنشئ رابط تعبئة المقاول (صالح ٧ أيام لمرة واحدة). أرسله للمقاول ليرفع مستنداته.');
    }

    public function channelSettings(): View
    {
        $channelConfig = $this->profileService->getChannelConfig();
        return view('modules.contractor_profile.channel_settings', compact('channelConfig'));
    }

    public function saveChannelSettings(Request $request): RedirectResponse
    {
        $settings = [];
        foreach (ContractorChannel::ALL_TYPES as $type) {
            $settings[$type] = [
                'enabled' => $request->boolean("channels.{$type}.enabled"),
                'priority' => (int) $request->input("channels.{$type}.priority", 50),
                ...($request->filled("channels.{$type}.api_key") ? ['config_json' => [
                    'api_key' => $request->input("channels.{$type}.api_key"),
                    'base_url' => $request->input("channels.{$type}.base_url"),
                ]] : []),
            ];
        }
        $this->profileService->configureChannels($settings, auth()->id());
        return redirect()->route('settings.contractor-channels')->with('success', 'حُفظت إعدادات قنوات التحقق.');
    }
}
