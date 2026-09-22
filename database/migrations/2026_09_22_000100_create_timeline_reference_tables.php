<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 时间线的「字典层」：阵营、纪元、人物、出处。
 *
 * 时间统一以 TerraDate 网格索引（integer）存储：
 *   index = year * 372 + (month - 1) * 31 + (day - 1)
 * 该网格单调递增且与公历月末/闰年无关，便于「区间包含」类查询；
 * 显示时永远回落到原始纪年文本 date_display，不做二次格式化，避免伪造精度。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 阵营（支持层级：罗德岛 > 精英干员 / 巴别塔）
        Schema::create('factions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('parent_id')->nullable()
                ->constrained('factions')->nullOnDelete();
            $table->string('full_name')->nullable();
            $table->string('color', 16)->default('#64748b');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('parent_id');
        });

        // 纪元 / 时期：把连续时间切成可检索的区段
        Schema::create('eras', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('subtitle')->nullable();
            $table->string('date_label');           // 展示用，如「泰拉历1096年末 – 1097年」
            $table->integer('start_index');         // TerraDate 网格索引（含）
            $table->integer('end_index');           // TerraDate 网格索引（含）
            $table->text('description')->nullable();
            $table->string('color', 16)->default('#38bdf8');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['start_index', 'end_index']);
        });

        // 人物
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('codename')->nullable();  // 干员代号
            $table->foreignId('faction_id')->nullable()
                ->constrained('factions')->nullOnDelete();
            $table->string('race')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('faction_id');
        });

        // 出处：主线章节 / 活动剧情 / 设定集章节等
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->default('event');        // SourceType
            $table->string('code')->nullable();              // 关卡号 / 章节号，如「7-18」「Vol.2 P.114」
            $table->string('chapter')->nullable();           // 章节名
            $table->integer('release_order')->default(0);    // 现实发布时间序，用于「按版本回放」
            $table->string('release_date')->nullable();
            $table->text('description')->nullable();
            $table->text('raw_text')->nullable();            // 供 AI 梳理与出处定位的原文
            $table->timestamps();

            $table->index(['type', 'release_order']);
        });

        // 标签（人工自由标注维度）
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('color', 16)->default('#8b5cf6');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
        Schema::dropIfExists('sources');
        Schema::dropIfExists('characters');
        Schema::dropIfExists('eras');
        Schema::dropIfExists('factions');
    }
};
