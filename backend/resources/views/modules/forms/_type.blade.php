@php
  // شارة نوع النموذج — ألوان موحّدة: كلما اشتدّ الإثبات المطلوب، اشتدّ اللون.
  $map = [
    'awareness' => 'info', 'confirmation' => 'primary', 'declaration' => 'success',
    'declaration_witnessed' => 'danger', 'survey' => 'secondary', 'custom' => 'light text-dark border',
  ];
@endphp
<span class="badge bg-{{ $map[$type] ?? 'secondary' }}" data-form-type="{{ $type }}">
  {{ \App\Modules\Form\Models\FormTemplate::TYPE_LABELS[$type] ?? $type }}
</span>
