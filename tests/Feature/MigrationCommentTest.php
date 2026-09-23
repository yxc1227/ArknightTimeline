<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 数据库备注（列注释）的守卫。
 *
 * 目标：整个 schema 无遗漏地自描述 —— 读表的人不必回头翻迁移或问人。
 * 两条独立的检查：
 *
 *  1. **静态检查**：逐个迁移文件比对「列定义数」与「comment 数」。
 *     任何驱动下都会执行，因此能真正挡住「新增列忘了写注释」。
 *  2. **运行时检查**：查 information_schema 确认注释真的落到了库里。
 *     只有 MySQL / PostgreSQL 会执行 —— SQLite 语法器没有 modifyComment，
 *     注释会被**静默跳过**（这正是测试用 :memory: 库不受影响的原因）。
 *     运行时检查是只读的，刻意不使用 RefreshDatabase，
 *     以免有人在 .env 指向真实 MySQL 的情况下跑测试把库清空。
 */
class MigrationCommentTest extends TestCase
{
    /** 全部业务表 + 骨架表；顺序无关，仅用于拼 SQL 的 IN 列表（常量内容，无注入风险）。 */
    private const TABLES = [
        'users', 'password_reset_tokens', 'sessions',
        'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs',
        'factions', 'eras', 'characters', 'sources', 'tags',
        'events', 'event_source', 'event_character', 'event_faction', 'event_tag',
        'event_revisions', 'annotations', 'event_locks', 'ai_proposals', 'timeline_anomalies',
        'source_user',
    ];

    /**
     * 会创建列的 Blueprint 方法。`->` 前缀是必要的：
     * 否则 `unsignedInteger` 会被 `integer` 抢先匹配，列数被重复计入。
     */
    private const COLUMN_METHODS = 'id|string|text|mediumText|longText|integer|bigInteger|unsignedInteger'
        .'|unsignedSmallInteger|unsignedTinyInteger|tinyInteger|smallInteger|boolean|json|jsonb|uuid'
        .'|timestamp|date|softDeletes|foreignId|rememberToken|decimal|float|double|binary|enum';

    public function test_every_column_definition_in_migrations_carries_a_comment(): void
    {
        $files = glob(database_path('migrations/*.php')) ?: [];

        $this->assertNotEmpty($files, '未找到任何迁移文件');

        foreach ($files as $file) {
            $source = file_get_contents($file) ?: '';
            $name = basename($file);

            $columns = preg_match_all('/->('.self::COLUMN_METHODS.')\(/', $source);
            $comments = substr_count($source, '->comment(');

            $this->assertSame(
                $columns,
                $comments,
                "{$name} 中有 {$columns} 个列定义但只有 {$comments} 个 comment()，"
                .'每个字段都必须带数据库备注（外键的 comment 要写在 constrained() 之前）',
            );

            // timestamps() 一次性创建 created_at / updated_at，无法逐个加注释，
            // 因此项目里统一展开为两个显式列。这里把它作为硬约束守住。
            $this->assertStringNotContainsString(
                '->timestamps(',
                $source,
                "{$name} 使用了 timestamps()，它无法携带列注释；请展开为两个显式 timestamp 列",
            );
        }
    }

    public function test_every_expected_table_is_covered_by_the_check(): void
    {
        // 防止有人加了新表却忘了把它加进 self::TABLES（那会让运行时检查出现盲区）
        $created = [];
        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            $source = file_get_contents($file) ?: '';
            preg_match_all("/Schema::create\(\s*'([a-z_]+)'/", $source, $matches);
            $created = array_merge($created, $matches[1]);
        }

        $missing = array_diff(array_unique($created), self::TABLES);

        $this->assertSame([], array_values($missing), '以下表未纳入注释检查范围：'.implode('、', $missing));
    }

    public function test_live_database_has_no_column_without_a_comment(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'mysql') {
            $this->markTestSkipped(
                '列注释仅 MySQL / PostgreSQL 支持，当前驱动为 '.$connection->getDriverName().'（SQLite 会静默跳过注释）'
            );
        }

        if (! Schema::hasTable('events')) {
            $this->markTestSkipped('数据库尚未迁移，无法校验列注释');
        }

        $tables = "'".implode("','", self::TABLES)."'";

        $rows = DB::select(
            "select table_name, column_name
             from information_schema.columns
             where table_schema = database()
               and table_name in ({$tables})
               and (column_comment is null or column_comment = '')
             order by table_name, ordinal_position"
        );

        $missing = array_map(fn ($row) => $row->table_name.'.'.$row->column_name, $rows);

        $this->assertSame([], $missing, '以下列在数据库中缺少备注：'.implode('、', $missing));
    }
}
