<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();

            // ---- 内容 ----
            $table->string('title');
            $table->string('slug')->nullable()->unique();
            $table->text('summary');                 // 简要描述（必填）
            $table->text('details')->nullable();     // 详述
            $table->string('location')->nullable();  // 发生地，如「维多利亚·伦蒂尼姆」

            // ---- 游戏内纪元时间（区间语义） ----
            $table->string('date_display');                 // 原始纪年文本，永远直接展示
            $table->integer('start_index');                 // TerraDate 网格索引
            $table->integer('end_index');                   // = start_index 表示单点
            $table->string('date_precision')->default('day');    // DatePrecision
            $table->string('date_confidence')->default('confirmed'); // DateConfidence
            $table->foreignId('era_id')->nullable()->constrained('eras')->nullOnDelete();
            $table->integer('sort_seq')->default(0);        // 同日并列时的显式排序权重

            // ---- 结构化关联 ----
            $table->foreignId('parent_event_id')->nullable()
                ->constrained('events')->nullOnDelete();     // 上级事件（归纳关系）
            $table->foreignId('caused_by_event_id')->nullable()
                ->constrained('events')->nullOnDelete();     // 直接起因（因果校验用）

            // ---- 生命周期 / 协作 ----
            $table->string('status')->default('verified');   // EventStatus
            $table->unsignedInteger('version')->default(1);  // 乐观锁版本号（CAS 依据）
            $table->boolean('is_locked')->default(false);    // 审核员锁定的条目
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

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
