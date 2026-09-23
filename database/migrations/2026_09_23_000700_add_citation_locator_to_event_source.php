<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 引文定位：把「引用可定位」从声明变成可校验的事实。
 *
 * 三件事：
 *
 *  1. `source_line` —— 引文在 `sources.raw_text` 中的**行号**。
 *     字符偏移（quote_offset）适合程序跳转，人核对时看的却是行号：
 *     出处页要说得出「原文第 5 行」，而不是「第 312 个字符」。
 *     两列同一坐标系（都相对 raw_text），因此永远同进同退。
 *
 *  2. `is_annotation` —— 「编者按」性质的引文。
 *     《大地巡旅》年表里 `[1083年 阿米娅出生]` 这类方括号条目是凯尔希的补充（书中有注），
 *     此前这层语义只能混在引文文本和详述里，既无法检索、也没法在界面上区分。
 *
 *  3. **修一个静默失效的唯一键**。原 `unique(event_id, source_id, stage_code)`
 *     在 `stage_code IS NULL` 时形同虚设 —— SQL 中 NULL 互不相等，唯一索引对含 NULL 的
 *     元组不做判重。而设定集类出处永远没有关卡号，于是「最需要防重的那批行」恰恰没被约束住。
 *     现改为 `(event_id, source_id, source_line)`：三列都 NOT NULL，
 *     约束不再可能被 NULL 绕过，同时行号本身就保证同一处引文不会被重复挂两次。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_source', function (Blueprint $table) {
            $table->unsignedInteger('source_line')->default(0)
                ->comment('引文在 sources.raw_text 中的行号（1 起算）；0 表示尚未定位');

            $table->boolean('is_annotation')->default(false)
                ->comment('引文是否为编者按性质的补充条目（《大地巡旅》年表中 [] 标注的凯尔希补充即属此类）');
        });

        // 自定义短名：自动生成的名字（含表前缀与四列）会超过 MySQL 的 64 字符索引名上限。
        Schema::table('event_source', function (Blueprint $table) {
            $table->unique(['event_id', 'source_id', 'source_line'], 'event_source_citation_unique');
        });

        // 旧键必须**最后**删，而且要先有新键在：MySQL 不允许删掉「外键正在使用」的索引
        // （event_id 的外键一直靠这个复合索引打头的那列支撑）。新键同样以 event_id 打头，
        // 因此可以顶替它满足外键要求，旧键才删得掉。
        // 也刻意传数组而不是索引名：Laravel 会把连接的表前缀算进自动索引名，
        // 硬编码索引名在换了 DB_PREFIX 的环境里会找不到索引。
        Schema::table('event_source', function (Blueprint $table) {
            $table->dropUnique(['event_id', 'source_id', 'stage_code']);
        });
    }

    public function down(): void
    {
        // 逆序同样成立：先恢复旧键，再删新键，最后删列。
        Schema::table('event_source', function (Blueprint $table) {
            $table->unique(['event_id', 'source_id', 'stage_code']);
        });

        Schema::table('event_source', function (Blueprint $table) {
            $table->dropUnique('event_source_citation_unique');
        });

        Schema::table('event_source', function (Blueprint $table) {
            $table->dropColumn(['source_line', 'is_annotation']);
        });
    }
};
