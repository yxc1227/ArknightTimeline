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
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');          // 对应 events.version
            $table->string('action');                    // RevisionAction
            $table->string('origin')->default('human');  // ChangeOrigin
            $table->json('changed_fields')->nullable();  // ['title','start_index',...]
            $table->json('snapshot')->nullable();        // 写入后的完整快照
            $table->json('base_snapshot')->nullable();   // 写入前的快照（三方合并的 base）
            $table->text('comment')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ai_proposal_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['event_id', 'version']);
            $table->index(['event_id', 'created_at']);
        });

        Schema::create('annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type')->default('comment');  // AnnotationType
            $table->string('field')->nullable();         // 针对哪个字段（纠错时用）
            $table->text('body');
            $table->json('suggested_patch')->nullable(); // 字段级补丁建议
            $table->string('status')->default('open');   // open / accepted / rejected / resolved
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status']);
        });

        Schema::create('event_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();       // 客户端持有的租约令牌
            $table->string('reason')->default('editing');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('expires_at');
        });

        Schema::create('ai_proposals', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id')->nullable();        // 同一次梳理任务的批次
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('era_id')->nullable()->constrained('eras')->nullOnDelete();
            $table->string('status')->default('pending'); // ProposalStatus

            $table->string('title');
            $table->text('summary');
            $table->string('date_display');
            $table->integer('start_index')->nullable();
            $table->integer('end_index')->nullable();
            $table->string('date_precision')->default('unknown');
            $table->string('date_confidence')->default('inferred');
            $table->string('location')->nullable();

            $table->json('characters')->nullable();      // [{name, role}]
            $table->json('factions')->nullable();        // [{name, role}]
            $table->json('tags')->nullable();
            $table->json('evidence')->nullable();        // [{quote, offset, matched:bool}]
            $table->json('validation')->nullable();      // 四层校验结果明细
            $table->unsignedTinyInteger('confidence')->default(50); // 0-100

            // ---- 驱动信息（可复现 / 可回放） ----
            $table->string('driver')->nullable();        // heuristic / openai-compatible ...
            $table->string('model')->nullable();
            $table->string('prompt_hash', 64)->nullable();
            $table->json('raw_payload')->nullable();     // 模型原始返回

            $table->foreignId('duplicate_of_event_id')->nullable()
                ->constrained('events')->nullOnDelete();
            $table->foreignId('applied_event_id')->nullable()
                ->constrained('events')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('batch_id');
        });

        Schema::create('timeline_anomalies', function (Blueprint $table) {
            $table->id();
            $table->string('type');                          // AnomalyType
            $table->string('severity')->default('warning');  // AnomalySeverity
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_event_id')->nullable()
                ->constrained('events')->nullOnDelete();
            $table->foreignId('ai_proposal_id')->nullable()
                ->constrained('ai_proposals')->cascadeOnDelete();
            $table->string('fingerprint')->unique();         // 去重指纹，避免重复告警
            $table->text('message');
            $table->json('context')->nullable();
            $table->string('status')->default('open');       // open / ignored / resolved
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

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
