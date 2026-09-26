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
 *
 * 关于列注释：MySQL / PostgreSQL 会生成原生 COMMENT，SQLite 的语法器没有
 * modifyComment，会**静默跳过**（因此测试用的 :memory: 库不受影响）。
 *
 * 两个书写约定：
 *  1. 外键列的 comment() 必须写在 constrained() 之前 —— constrained() 会返回
 *     ForeignKeyDefinition 而不是 ColumnDefinition，写在后面注释会挂到外键对象上并丢失。
 *  2. timestamps() 不支持逐个字段加注释，因此展开为两个显式列。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 阵营（支持层级：罗德岛 > 精英干员 / 巴别塔）
        Schema::create('factions', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->string('name')->comment('阵营名称，如「罗德岛」');
            $table->string('slug')->unique()->comment('URL 用唯一标识');
            $table->foreignId('parent_id')->nullable()
                ->comment('上级阵营 ID，用于建立层级；按母阵营筛选时会向下包含子阵营的条目')
                ->constrained('factions')->nullOnDelete();
            $table->string('full_name')->nullable()->comment('阵营全称，如「罗德岛制药公司」');
            $table->string('color', 16)->default('#64748b')->comment('界面标识色（十六进制）');
            $table->text('description')->nullable()->comment('阵营说明');
            $table->unsignedInteger('sort_order')->default(0)->comment('界面排序权重，越小越靠前');

            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            $table->index('parent_id');
        });

        // 纪元 / 时期：把连续时间切成可检索的区段
        Schema::create('eras', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->string('name')->comment('纪元名称，如「切尔诺伯格事变与龙门危机」');
            $table->string('slug')->unique()->comment('URL 用唯一标识');
            $table->string('subtitle')->nullable()->comment('副标题，补充一句话特征');
            $table->string('date_label')->comment('纪元区间的展示文本；仅用于展示，排序一律以 start_index / end_index 为准');
            $table->integer('start_index')->comment('TerraDate 网格索引（闭区间起点）：年*372 + (月-1)*31 + (日-1)');
            $table->integer('end_index')->comment('TerraDate 网格索引（闭区间终点，含）');
            $table->text('description')->nullable()->comment('纪元说明');
            $table->string('color', 16)->default('#38bdf8')->comment('界面标识色；纪元配色采用单一色族的递进梯度');
            $table->unsignedInteger('sort_order')->default(0)->comment('界面排序权重，决定时间线上的纪元分组顺序');

            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            $table->index(['start_index', 'end_index']);
        });

        // 人物
        Schema::create('characters', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->string('name')->comment('人物名称');
            $table->string('slug')->unique()->comment('URL 用唯一标识');
            $table->string('codename')->nullable()->comment('干员代号；与名称不同时用于区分同一人物的两个称呼');
            $table->foreignId('faction_id')->nullable()
                ->comment('所属阵营 ID；留空表示阵营未知')
                ->constrained('factions')->nullOnDelete();
            $table->string('race')->nullable()->comment('种族；不确定时留空，宁可缺失也不要写错');
            $table->text('description')->nullable()->comment('人物说明');
            $table->unsignedInteger('sort_order')->default(0)->comment('界面排序权重，越小越靠前');

            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            $table->index('faction_id');
        });

        // 出处：主线章节 / 活动剧情 / 设定集章节等
        Schema::create('sources', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->string('name')->comment('出处名称，如「主线 · 第七章「苦海」」');
            $table->string('slug')->unique()->comment('URL 用唯一标识');
            $table->string('type')->default('event')->comment('载体类型（SourceType 枚举）：main_story / side_story / event / operator_record / artbook / setting / anime / other');
            $table->string('code')->nullable()->comment('编号：关卡号或书内编号，如「7-18」「Vol.1」');
            $table->string('chapter')->nullable()->comment('章节 / 分卷名');
            $table->integer('release_order')->default(0)->comment('现实发布顺序，用于「按版本回放」剧情');
            $table->string('release_date')->nullable()->comment('现实发布时间');
            $table->text('description')->nullable()->comment('出处说明');
            $table->text('raw_text')->nullable()->comment('原文语料：AI 抽取与「引用可定位」校验的基准，引文字符偏移即相对此文本计算');

            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            $table->index(['type', 'release_order']);
        });

        // 标签（人工自由标注维度）
        Schema::create('tags', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->string('name')->comment('标签名称，如「战役」');
            $table->string('slug')->unique()->comment('URL 用唯一标识');
            $table->string('color', 16)->default('#8b5cf6')->comment('界面标识色（十六进制）');
            $table->text('description')->nullable()->comment('标签说明');

            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');
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
