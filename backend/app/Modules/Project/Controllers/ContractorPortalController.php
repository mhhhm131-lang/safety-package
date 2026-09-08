<?php

namespace App\Modules\Project\Controllers;

use App\Core\Services\NotificationService;
use App\Http\Controllers\Controller;
use App\Modules\Project\Models\ContractorProfile;
use App\Modules\Project\Models\ExternalPartyDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * رابط التعبئة بلا حساب (من OHSMS): المقاول يفتح الرابط برمزه (٧ أيام، مرة واحدة) ويرفع مستنداته.
 * الملفات base64 في القاعدة (قرص Render مؤقت). يُنبَّه مسؤول السلامة بعد الرفع.
 */
class ContractorPortalController extends Controller
{
    public function show(string $token): View
    {
        $profile = ContractorProfile::where('portal_link_token', $token)->where('portal_link_expires_at', '>', now())->first();
        if (!$profile) {
            abort(404, 'الرابط غير صالح أو انتهت صلاحيته.');
        }
        if ($profile->portal_link_used_at) {
            abort(410, 'استُخدم هذا الرابط مسبقاً.');
        }
        $contractor = $profile->externalParty;
        return view('modules.contractor_profile.portal', compact('profile', 'contractor'));
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        $profile = ContractorProfile::where('portal_link_token', $token)->where('portal_link_expires_at', '>', now())->whereNull('portal_link_used_at')->firstOrFail();
        $contractor = $profile->externalParty;
        $request->validate([
            'documents' => 'required|array|min:1',
            'documents.*.file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'documents.*.document_type' => 'required|in:cr,license,insurance,safety_cert,iso_cert,other',
            'documents.*.expiry_date' => 'nullable|date|after:today',
        ]);
        foreach ($request->file('documents', []) as $i => $fileData) {
            $doc = new ExternalPartyDocument([
                'external_party_id' => $contractor->id,
                'name' => $fileData['file']->getClientOriginalName(),
                'document_type' => $request->input("documents.{$i}.document_type"),
                'expiry_date' => $request->input("documents.{$i}.expiry_date") ?: null,
                'is_verified' => false, // مسؤول السلامة يوثّق بعد المراجعة
                'source_channel' => 'portal_link',
                'uploaded_by_id' => null,
                'created_at' => now(),
            ]);
            $doc->attachUpload($fileData['file']);
            $doc->save();
        }
        $profile->update(['portal_link_used_at' => now()]);
        app(NotificationService::class)->notifyRoles(['system_admin', 'system_staff'], 'contractor.documents',
            'مستندات جديدة من المقاول: '.$contractor->name, 'رُفعت عبر رابط التعبئة — تحتاج توثيقاً.', '/app/external-parties/'.$contractor->id.'/documents');
        return redirect()->route('contractor-portal.done', $token)->with('success', 'رُفعت المستندات. سيراجعها مسؤول السلامة.');
    }

    public function done(string $token): View
    {
        $profile = ContractorProfile::where('portal_link_token', $token)->first();
        if (!$profile) {
            abort(404, 'الرابط غير صالح.');
        }
        return view('modules.contractor_profile.portal_done');
    }
}
