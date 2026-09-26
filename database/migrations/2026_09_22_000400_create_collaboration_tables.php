<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 多人协作层：
 *  - event_revisions     全量版本快照（审计 / 三方合并 / 回滚）
 *  - annotations         标注与纠错建议
 *  - event_locks         编辑租约（软锁，不阻塞但可见）
 *  - ai_proposals        AI 产出暂存区（AI 永不直接写 events）
 *  - timeline_anomalies  一致性异常收件箱
 *
 * 这一层的注释重点写清「它在协作机制里防住了什么」，
 * 因为这些字段的语义无法从名字推断（例如 fingerprint 为何必须是唯一索引）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_revisions', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->foreignId('event_id')->comment('所属事件')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->comment('对应 events.version；与 event_id 组成唯一索引，保证「一个版本号只对应一次写入」');
            $table->string('action')->comment('版本动作（RevisionAction 枚举）：created / updated / deleted / restored / merged / annotated');
            $table->string('origin')->default('human')->comment('变更来源（ChangeOrigin 枚举）；origin = ai 表示该版本由 AI 提案经人工放行产生');
            $table->json('changed_fields')->nullable()->comment('本次改动的字段名数组，用于版本历史展示与冲突分析');
            $table->json('snapshot')->nullable()->comment('写入后的完整快照（含关系）；回滚与三方合成都以它为依据');
            $table->json('base_snapshot')->nullable()->comment('写入前的快照，即三方合并的 base；缺失时冲突判定会退化为保守策略');
            $table->text('comment')->nullable()->comment('本次编辑备注，写明改动意图');
            $table->foreignId('user_id')->nullable()
                ->comment('操作人；AI 产出的版本记审核人 ——「谁为这条内容负责」必须有答案')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('ai_proposal_id')->nullable()->comment('来源 AI 提案 ID；人工直接编辑时为空');
            $table->string('ip_address', 45)->nullable()->comment('操作来源 IP，审计用');
            $table->timestamp('created_at')->nullable()->comment('写入时间；版本记录只增不改，因此没有 updated_at');

            $table->unique(['event_id', 'version']);
            $table->index(['event_id', 'created_at']);
        });

        Schema::create('annotations', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->foreignId('event_id')->comment('被标注的事件')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()
                ->comment('标注人；为空表示未登录访客提交（标注对所有人开放，纠错门槛必须低）')
                ->constrained('users')->nullOnDelete();
            $table->string('type')->default('comment')->comment('标注类型（AnnotationType）：comment 备注 / correction 纠错 / question 存疑 / verification 复核');
            $table->string('field')->nullable()->comment('针对的具体字段名（纠错时用），如 date_display');
            $table->text('body')->comment('标注正文');
            $table->json('suggested_patch')->nullable()->comment('字段级补丁建议（JSON）；审核员可据此一键应用');
            $table->string('status')->default('open')->comment('处置状态：open 待处理 / accepted 已采纳 / rejected 已驳回 / resolved 已解决');
            $table->foreignId('resolved_by')->nullable()
                ->comment('处置人')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->comment('处置时间');

            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            $table->index(['event_id', 'status']);
        });

        Schema::create('event_locks', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->foreignId('event_id')->unique()
                ->comment('被锁定的条目；唯一索引保证一个条目同时只有一份编辑租约')
                ->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->comment('持有租约的编辑者')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique()->comment('租约令牌（客户端持有）；续租与释放都需校验此令牌');
            $table->string('reason')->default('editing')->comment('租约用途');
            $table->timestamp('expires_at')->comment('租约到期时间；到期自动回收，避免出现「关掉标签页就永久锁死」的僵尸锁');

            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            $table->index('expires_at');
        });

        Schema::create('ai_proposals', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->uuid('batch_id')->nullable()->comment('同一次梳理任务的批次号，用于按批审核与事后复盘');
            $table->foreignId('source_id')->nullable()
                ->comment('梳理所用的出处')
                ->constrained()->nullOnDelete();
            $table->foreignId('era_id')->nullable()
                ->comment('目标纪元，用于一致性预检（时代错位）')
                ->constrained('eras')->nullOnDelete();
            $table->string('status')->default('pending')->comment('提案状态（ProposalStatus）。AI 产出只落本表，必须人工放行才会写入 events');

            $table->string('title')->comment('提炼出的事件标题');
            $table->text('summary')->comment('提炼出的事件描述');
            $table->string('date_display')->comment('提炼出的纪年原文');
            $table->integer('start_index')->nullable()->comment('解析出的 TerraDate 网格索引起点；解析失败为空，此时禁止直接入库');
            $table->integer('end_index')->nullable()->comment('解析出的 TerraDate 网格索引终点');
            $table->string('date_precision')->default('unknown')->comment('时间精度（DatePrecision 枚举）');
            $table->string('date_confidence')->default('inferred')->comment('时间可信度（DateConfidence 枚举）；AI 产出默认 inferred，不冒充已确证');
            $table->string('location')->nullable()->comment('提炼出的发生地');

            $table->json('characters')->nullable()->comment('关联人物（JSON）：[{name, role}]');
            $table->json('factions')->nullable()->comment('关联阵营（JSON）：[{name, role}]');
            $table->json('tags')->nullable()->comment('建议标签（JSON）');
            $table->json('evidence')->nullable()->comment('出处引文（JSON）：[{quote, offset, matched}]。matched 表示该引文能否在原文中定位，是防幻觉的主闸门');
            $table->json('validation')->nullable()->comment('四层校验结果明细（JSON）：结构 / 时间可解析 / 出处可定位 / 一致性预检');
            $table->unsignedTinyInteger('confidence')->default(50)->comment('模型自评置信度 0-100；低于阈值会在审核面板强提示');

            // ---- 驱动信息（可复现 / 可回放） ----
            $table->string('driver')->nullable()->comment('抽取驱动名（heuristic / openai-compatible …），用于复现与跨驱动对比');
            $table->string('model')->nullable()->comment('模型标识');
            $table->string('prompt_hash', 64)->nullable()->comment('提示词与输入的摘要，用于判断两条提案是否来自同一次调用');
            $table->json('raw_payload')->nullable()->comment('模型原始返回（JSON），保留以便事后排查解析问题');

            $table->foreignId('duplicate_of_event_id')->nullable()
                ->comment('疑似重复的既有条目；审核时可选择合并而非新建')
                ->constrained('events')->nullOnDelete();
            $table->foreignId('applied_event_id')->nullable()
                ->comment('采纳后生成的条目；为空表示尚未入库')
                ->constrained('events')->nullOnDelete();
            $table->text('review_note')->nullable()->comment('审核记录；驳回理由会作为 prompt 调优的反馈数据保留');
            $table->foreignId('reviewed_by')->nullable()
                ->comment('审核人')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->comment('审核时间');

            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            $table->index(['status', 'created_at']);
            $table->index('batch_id');
        });

        Schema::create('timeline_anomalies', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->string('type')->comment('异常类型（AnomalyType）：因果倒置 / 时代错位 / 出处矛盾 / 疑似重复 / 子早于父 / 单日过载 / 锚点失效');
            $table->string('severity')->default('warning')->comment('级别（AnomalySeverity）；error 为阻断级，会挡住「标记为已校验」');
            $table->foreignId('event_id')->comment('命中的条目')->constrained()->cascadeOnDelete();
            $table->foreignId('related_event_id')->nullable()
                ->comment('关联条目，如因果倒置中的起因事件')
                ->constrained('events')->nullOnDelete();
            $table->foreignId('ai_proposal_id')->nullable()
                ->comment('若该异常源自某条 AI 提案，记录其 ID，便于复盘「哪批产出更容易出问题」')
                ->constrained('ai_proposals')->cascadeOnDelete();
            $table->string('fingerprint')->unique()->comment('去重指纹（类型+条目+关联+提案）。唯一索引保证同一问题不重复告警，巡检因此是收敛的而非累积的');
            $table->text('message')->comment('人类可读的异常说明');
            $table->json('context')->nullable()->comment('上下文（JSON），如引文、相似度、时间差等判定依据');
            $table->string('status')->default('open')->comment('处置状态：open / ignored / resolved；本轮巡检未复现的 open 异常会被自动销案');
            $table->foreignId('resolved_by')->nullable()
                ->comment('处置人')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable()->comment('处置时间');

            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            $table->index(['status', 'severity']);
            $table->index('event_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_anomalies');
        Schema::dropIfExists('ai_proposals');
        Schema::dropIfExists('event_locks');
        Schema::dropIfExists('annotations');
        Schema::dropIfExists('event_revisions');
    }
};
