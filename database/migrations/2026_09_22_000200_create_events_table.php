<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 时间线的核心表。
 *
 * 最难解释、也最容易写错的是时间字段，注释里都写明了取舍原因；
 * 其余字段的注释重点说明「它在协作机制里承担什么职责」，
 * 而不是复述字段名 —— 后者对读表的人没有价值。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id()->comment('主键');

            // ---- 内容 ----
            $table->string('title')->comment('事件标题');
            $table->string('slug')->nullable()->unique()->comment('URL 用唯一标识');
            $table->text('summary')->comment('简要描述（必填）；列表与卡片展示用');
            $table->text('details')->nullable()->comment('详述；正文不做自动合并，两人同时改写时须人工裁决');
            $table->string('location')->nullable()->comment('发生地，如「维多利亚 · 伦蒂尼姆」');

            // ---- 游戏内纪元时间（区间语义） ----
            $table->string('date_display')->comment('游戏内纪年的原文，如「泰拉历1097年冬」。展示层只认此字段，绝不从索引反推格式化——那会伪造精度');
            $table->integer('start_index')->comment('TerraDate 网格索引（闭区间起点）：年*372 + (月-1)*31 + (日-1)。时间筛选按区间重叠而非包含，跨年季节因此能被「次年1月」命中');
            $table->integer('end_index')->comment('TerraDate 网格索引（闭区间终点，含）；等于 start_index 表示精确到日的单点');
            $table->string('date_precision')->default('day')->comment('时间精度（DatePrecision 枚举），决定区间宽度：day / month / season / year / range / relative / unknown');
            $table->string('date_confidence')->default('confirmed')->comment('时间可信度（DateConfidence 枚举）：confirmed 原文明写 / inferred 推断 / disputed 存疑 / unknown 未知');
            $table->foreignId('era_id')->nullable()
                ->comment('所属纪元 ID；与纪元区间不一致会被巡检标记为「时代错位」')
                ->constrained('eras')->nullOnDelete();
            $table->integer('sort_seq')->default(0)->comment('同一时间点的显式排序权重；整体排序为 (start_index, sort_seq, id)');

            // ---- 结构化关联 ----
            $table->foreignId('parent_event_id')->nullable()
                ->comment('上级事件 ID（归纳关系）；子事件早于父事件会被巡检标记')
                ->constrained('events')->nullOnDelete();
            $table->foreignId('caused_by_event_id')->nullable()
                ->comment('直接起因事件 ID；结果早于起因会被巡检标记为「因果倒置」（阻断级）')
                ->constrained('events')->nullOnDelete();

            // ---- 生命周期 / 协作 ----
            $table->string('status')->default('verified')->comment('条目状态（EventStatus 枚举）；disputed / deprecated 时正文冻结，普通编辑者只能提交标注建议');
            $table->unsignedInteger('version')->default(1)->comment('乐观锁版本号。每次写入以 WHERE version = ? 做 CAS，是「不丢更新」的唯一保证');
            $table->boolean('is_locked')->default(false)->comment('是否被审核员锁定；锁定后普通编辑者不可写');
            $table->foreignId('created_by')->nullable()
                ->comment('创建人')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()
                ->comment('最后修改人')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->comment('标记为「已校验」的时间');
            $table->foreignId('verified_by')->nullable()
                ->comment('执行校验的审核员')
                ->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');
            $table->softDeletes()->comment('软删除时间；非空表示已删除，版本历史与恢复能力仍然保留');

            // 主排序：时间 → 同日权重 → id
            $table->index(['start_index', 'sort_seq'], 'events_timeline_order_index');
            $table->index(['date_confidence', 'status']);
            $table->index('era_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
