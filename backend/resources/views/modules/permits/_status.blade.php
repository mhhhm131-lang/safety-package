@php
  // شارة حالة التصريح — ألوان موحّدة في كل الشاشات.
  $map = [
    'draft' => 'secondary', 'submitted' => 'info', 'under_review' => 'warning text-dark',
    'safety_approved' => 'primary', 'approved' => 'success', 'conditional' => 'warning text-dark',
    'active' => 'success', 'completed' => 'secondary', 'expired' => 'secondary',
    'cancelled' => 'danger', 'rejected' => 'danger', 'suspended' => 'warning text-dark',
  ];
  $cls = $map[$status] ?? 'secondary';
@endphp
<span class="badge bg-{{ $cls }}" data-status="{{ $status }}">{{ \App\Modules\Permit\Models\Permit::STATUS_LABELS[$status] ?? $status }}</span>
