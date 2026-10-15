<?php
// يُشغَّل من tinker على القاعدة المحلية: قالب بالأنواع الثلاثة الجديدة مكلَّف به الموظف المعطى. يطبع رقم القالب.
use App\Modules\Form\Models\FormField;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Form\Services\FormService;

$empId = (int) getenv('EMP_ID');
$adminId = (int) getenv('ADMIN_ID');
$svc = app(FormService::class);
$form = FormTemplate::create(['title' => 'تجربة ٢٩-١ — بطاقة الإدارة', 'form_type' => FormTemplate::TYPE_SURVEY, 'created_by_id' => $adminId,
    'description' => 'قالب تجربة للأنواع الثلاثة: قسم، جدول، بند إقرار. يُحذف بعد التجربة.']);
$svc->addField($form, ['label' => 'أولاً: المخاطر والفرص', 'field_type' => FormField::TYPE_SECTION, 'help_text' => 'سجّل ما هو قائم فعلاً لا ما يُفترض']);
$svc->addField($form, ['label' => 'جدول المخاطر والفرص', 'field_type' => FormField::TYPE_TABLE, 'is_required' => true,
    'options' => "الخطر أو الفرصة | نص\nالبُعد | اختيار | معرفي، نفسي، فكري، اجتماعي، تنظيمي\nالإجراء | نص"]);
$svc->addField($form, ['label' => 'ثانياً: آليات الهدف الأول', 'field_type' => FormField::TYPE_SECTION]);
$svc->addField($form, ['label' => 'الآليات السبع × المراحل', 'field_type' => FormField::TYPE_TABLE, 'is_required' => true,
    'options' => "الآلية | ثابت\nموثّق | اختيار | نعم، جزئياً، لا\nمعلن | اختيار | نعم، جزئياً، لا\nآلية الضمان | نص\n+ خطة تربط دور الفرد بالرؤية\n+ السياسات والنظم المرتبطة بالعمل\n+ المخاطر والفرص"]);
$svc->addField($form, ['label' => 'الإدارة وثّقت خطتها وأعلنتها', 'field_type' => FormField::TYPE_ACK_ITEM, 'is_required' => true]);
$svc->assignToUsers($form, [$empId], null, $adminId);
echo "FORM_ID=" . $form->id . "\n";
