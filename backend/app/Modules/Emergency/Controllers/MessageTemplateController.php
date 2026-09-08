<?php

namespace App\Modules\Emergency\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Emergency\Models\EmergencyMessageTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageTemplateController extends Controller
{
    /**
     * List all templates (global + tenant-specific)
     */
    public function index(Request $request): JsonResponse
    {
        $category = $request->get('category');
        $query = EmergencyMessageTemplate::query()->active()->ordered();

        if ($category) {
            $query->forCategory($category);
        }

        $templates = $query->get();

        return response()->json([
            'success' => true,
            'data' => $templates->map(fn($t) => [
                'id' => $t->id,
                'code' => $t->code,
                'name' => $t->name,
                'category' => $t->category,
                'category_label' => $t->getCategoryLabel(),
                'category_icon' => $t->getCategoryIcon(),
                'category_color' => $t->getCategoryColor(),
                'title_ar' => $t->title_ar,
                'title_en' => $t->title_en,
                'message_ar' => $t->message_ar,
                'message_en' => $t->message_en,
                'variables' => $t->variables ?? [],
                'default_channels' => $t->default_channels,
                'severity' => $t->severity,
                'severity_label' => $t->getSeverityLabel(),
                'severity_color' => $t->getSeverityColor(),
                'is_global' => (bool) $t->is_builtin,
                'is_active' => $t->is_active,
            ]),
            'categories' => EmergencyMessageTemplate::getCategories(),
        ]);
    }

    /**
     * Get templates by category
     */
    public function byCategory(string $category): JsonResponse
    {
        $templates = EmergencyMessageTemplate::forCategory($category);

        return response()->json([
            'success' => true,
            'data' => $templates->map(fn($t) => [
                'id' => $t->id,
                'code' => $t->code,
                'name' => $t->name,
                'title_ar' => $t->title_ar,
                'message_ar' => $t->message_ar,
                'variables' => $t->variables ?? [],
                'severity' => $t->severity,
            ]),
        ]);
    }

    /**
     * Get a single template
     */
    public function show(EmergencyMessageTemplate $template): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $template->id,
                'code' => $template->code,
                'name' => $template->name,
                'category' => $template->category,
                'category_label' => $template->getCategoryLabel(),
                'title_ar' => $template->title_ar,
                'title_en' => $template->title_en,
                'message_ar' => $template->message_ar,
                'message_en' => $template->message_en,
                'variables' => $template->variables ?? [],
                'default_channels' => $template->default_channels,
                'severity' => $template->severity,
                'auto_trigger' => $template->auto_trigger,
                'is_global' => (bool) $template->is_builtin,
                'is_active' => $template->is_active,
            ],
        ]);
    }

    /**
     * Render a template with variables
     */
    public function render(Request $request, EmergencyMessageTemplate $template): JsonResponse
    {
        $validated = $request->validate([
            'variables' => 'nullable|array',
            'lang' => 'nullable|in:ar,en',
        ]);

        $rendered = $template->render(
            $validated['variables'] ?? [],
            $validated['lang'] ?? 'ar'
        );

        return response()->json([
            'success' => true,
            'data' => $rendered,
        ]);
    }

    /**
     * Create a tenant-specific template
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:emergency_message_templates,code',
            'name' => 'required|string|max:200',
            'category' => 'required|in:fire,earthquake,medical,security,weather,chemical,evacuation,drill,all_clear,custom',
            'title_ar' => 'required|string|max:200',
            'title_en' => 'nullable|string|max:200',
            'message_ar' => 'required|string|max:2000',
            'message_en' => 'nullable|string|max:2000',
            'variables' => 'nullable|array',
            'default_channels' => 'nullable|array',
            'severity' => 'nullable|in:low,medium,high,critical',
            'auto_trigger' => 'nullable|boolean',
        ]);

        $validated['is_builtin'] = false;
        $validated['is_active'] = true;

        $template = EmergencyMessageTemplate::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء القالب',
            'data' => [
                'id' => $template->id,
                'code' => $template->code,
            ],
        ], 201);
    }

    /**
     * Update a tenant-specific template
     */
    public function update(Request $request, EmergencyMessageTemplate $template): JsonResponse
    {
        if ($template->is_builtin) {
            return response()->json(['success' => false, 'message' => 'القوالب المضمّنة لا تُعدَّل؛ أنشئ قالباً خاصاً'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:200',
            'title_ar' => 'sometimes|string|max:200',
            'title_en' => 'nullable|string|max:200',
            'message_ar' => 'sometimes|string|max:2000',
            'message_en' => 'nullable|string|max:2000',
            'variables' => 'nullable|array',
            'default_channels' => 'nullable|array',
            'severity' => 'nullable|in:low,medium,high,critical',
            'auto_trigger' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        $template->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث القالب',
        ]);
    }

    /**
     * Delete a tenant-specific template
     */
    public function destroy(EmergencyMessageTemplate $template): JsonResponse
    {
        if ($template->is_builtin) {
            return response()->json(['success' => false, 'message' => 'القوالب المضمّنة لا تُحذف'], 403);
        }

        $template->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف القالب',
        ]);
    }

    /**
     * Get quick-send templates (for mobile app)
     */
    public function quickSend(): JsonResponse
    {
        $templates = EmergencyMessageTemplate::query()
        ->active()
        ->whereIn('category', ['fire', 'evacuation', 'security', 'all_clear'])
        ->ordered()
        ->get();

        return response()->json([
            'success' => true,
            'data' => $templates->map(fn($t) => [
                'id' => $t->id,
                'code' => $t->code,
                'name' => $t->name,
                'category' => $t->category,
                'icon' => $t->getCategoryIcon(),
                'color' => $t->getCategoryColor(),
                'title' => $t->title_ar,
                'requires_variables' => !empty($t->variables),
                'variables' => $t->variables ?? [],
            ]),
        ]);
    }
}
