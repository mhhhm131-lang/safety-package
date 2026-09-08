<?php

namespace App\Core\Traits;

/**
 * يسجّل إنشاء/تعديل/حذف النموذج في سجل التدقيق تلقائياً (من OHSMS؛ هناك لم يُستخدم في أي نموذج، وعندنا على كل نماذج الحوكمة).
 */
trait HasAuditLog
{
    public static function bootHasAuditLog(): void
    {
        static::created(fn ($m) => static::logAudit($m, 'created'));
        static::updated(fn ($m) => static::logAudit($m, 'updated'));
        static::deleted(fn ($m) => static::logAudit($m, 'deleted'));
    }

    protected static function logAudit($model, string $action): void
    {
        if (!app()->bound('audit.logger')) {
            return;
        }
        $label = method_exists($model, 'auditLabel') ? $model->auditLabel() : ($model->name ?? $model->title ?? '');
        $changes = $action === 'updated' ? array_keys($model->getChanges()) : [];
        $changes = array_values(array_diff($changes, ['updated_at', 'password', 'remember_token']));
        $desc = trim(class_basename($model).' #'.$model->getKey().' '.$label.($changes ? ' ['.implode(', ', $changes).']' : ''));

        app('audit.logger')->log(request(), $action, class_basename($model), (int) $model->getKey(), $desc, auth()->id());
    }
}
