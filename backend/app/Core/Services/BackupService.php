<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\DB;

/**
 * نسخة احتياطية من القاعدة (المرحلة ٨-٣).
 *
 * **لماذا التفريغ بـPHP لا `pg_dump`:** حاوية الخدمة على Render لا تحمل أدوات Postgres،
 * ولا سطر أوامر فيها أصلاً. هذا التفريغ يعمل على SQLite محلياً وPostgres على المنشور
 * بالاستعلامات وحدها، بلا برنامج خارجي.
 *
 * **وأين تُحفظ:** الخطة المجانية بلا قرص دائم — كل ما يُكتب في الحاوية يزول عند إعادة
 * النشر أو التشغيل. فالنسخة التي **تبقى** هي التي يُنزّلها المستخدم من الشاشة ويحفظها
 * عنده. الأمر المجدول يبقي آخر سبع نسخ داخل الحاوية كشبكة أمان قصيرة الأجل، ويصير
 * نسخاً حقيقياً لو أُضيف قرص أو بيئة أخرى.
 */
class BackupService
{
    /** الجداول التي لا معنى لنسخها: مؤقتة أو تخص جلسة. */
    private const SKIP = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs',
        'password_reset_tokens', 'personal_access_tokens'];

    public function directory(): string
    {
        return storage_path('app/private/backups');
    }

    public function filename(): string
    {
        return 'ipa-safety-'.now()->format('Ymd-His').'.sql';
    }

    /** أسماء الجداول المنسوخة بترتيب ثابت. */
    public function tables(): array
    {
        $rows = match (DB::connection()->getDriverName()) {
            'sqlite' => DB::select("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"),
            'pgsql'  => DB::select("SELECT tablename AS name FROM pg_tables WHERE schemaname='public' ORDER BY tablename"),
            default  => [],
        };

        return collect($rows)->pluck('name')
            ->reject(fn ($t) => in_array($t, self::SKIP, true) || str_starts_with($t, 'sqlite_'))
            ->values()->all();
    }

    /**
     * يكتب التفريغ سطراً سطراً في دالة الكتابة المعطاة، فلا تُحمَّل القاعدة في الذاكرة.
     *
     * @param callable(string): void $write
     * @return array{tables: int, rows: int}
     */
    public function dump(callable $write): array
    {
        $driver = DB::connection()->getDriverName();
        $write("-- نسخة احتياطية — منظومة السلامة والصحة المهنية، معهد الإدارة العامة\n");
        $write('-- '.now()->format('Y-m-d H:i')." بتوقيت الرياض · المحرك: {$driver}\n");
        $write("-- الاستعادة: نفّذ هذا الملف على قاعدة فارغة بعد الترحيلات.\n\n");

        $tables = $this->tables();
        $rowCount = 0;

        foreach ($tables as $table) {
            $write("\n-- {$table}\n");
            $n = 0;

            DB::table($table)->orderBy($this->orderColumn($table))->chunk(500, function ($rows) use ($table, $write, &$n, &$rowCount) {
                foreach ($rows as $row) {
                    $data = (array) $row;
                    $columns = implode(', ', array_map(fn ($c) => $this->quoteIdent($c), array_keys($data)));
                    $values = implode(', ', array_map(fn ($v) => $this->quoteValue($v), array_values($data)));
                    $write("INSERT INTO {$this->quoteIdent($table)} ({$columns}) VALUES ({$values});\n");
                    $n++;
                    $rowCount++;
                }
            });

            $write("-- صفوف: {$n}\n");
        }

        $write("\n-- انتهى: ".count($tables)." جدولاً، {$rowCount} صفاً.\n");

        return ['tables' => count($tables), 'rows' => $rowCount];
    }

    /** يكتب نسخة على القرص ويعيد مسارها. */
    public function writeToDisk(): array
    {
        $dir = $this->directory();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir.DIRECTORY_SEPARATOR.$this->filename();
        $handle = fopen($path, 'w');
        $stats = $this->dump(fn (string $line) => fwrite($handle, $line));
        fclose($handle);

        return $stats + ['path' => $path, 'bytes' => filesize($path)];
    }

    /**
     * يُبقي أحدث `$keep` نسخة ويحذف ما قبلها.
     *
     * @return array<string> الملفات المحذوفة
     */
    public function prune(int $keep = 7): array
    {
        $files = $this->existing();
        $extra = array_slice($files, $keep);

        foreach ($extra as $file) {
            @unlink($file['path']);
        }

        return array_column($extra, 'name');
    }

    /** النسخ الموجودة، الأحدث أولاً. */
    public function existing(): array
    {
        $dir = $this->directory();
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (glob($dir.DIRECTORY_SEPARATOR.'ipa-safety-*.sql') ?: [] as $path) {
            $files[] = ['name' => basename($path), 'path' => $path,
                'bytes' => filesize($path), 'at' => filemtime($path)];
        }

        usort($files, fn ($a, $b) => $b['at'] <=> $a['at']);

        return $files;
    }

    /** عمود ترتيب موجود في الجدول — `chunk` يشترط ترتيباً ثابتاً. */
    private function orderColumn(string $table): string
    {
        foreach (['id', 'key', 'code'] as $candidate) {
            if (DB::getSchemaBuilder()->hasColumn($table, $candidate)) {
                return $candidate;
            }
        }

        return DB::getSchemaBuilder()->getColumnListing($table)[0] ?? 'id';
    }

    private function quoteIdent(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    private function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'".str_replace("'", "''", (string) $value)."'";
    }
}
