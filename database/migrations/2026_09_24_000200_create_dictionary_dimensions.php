<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 《大地巡旅》里三处**成体系、却一直没有落进 schema** 的维度。
 *
 *  1. **种族**（第四章「泰拉种族」）：书里是一章完整的种族志，而项目里
 *     `characters.race` 只是一个自由字符串 —— 写错字没有任何东西会拦下它。
 *     字典化之后，「按种族看人」成为可检索维度，而且写法不会各自漂移。
 *  2. **地理**（第五章各国的政区）：书里给出了三层结构（维多利亚的三个法理王国 → 郡 → 市 / 村镇；
 *     莱塔尼亚的九大区；乌萨斯的省与集团军属地），而项目里 `events.location` 是自由文本。
 *     地名树让「按地区层级聚合条目」成为可能。
 *  3. **词条**（贯穿全书的术语）：金律乐章、帝政主义、圣愚、提卡兹……在书里都有专门解释，
 *     此前只能塞进某条事件的详述里，读者无从按名索解。
 *
 * 另给人物加了「类型 / 头衔 / 在位区间」：书里满是君主与贵族（伊戈尔、赫尔昏佐伦、科西嘉一世…），
 * 他们与干员是两类实体 —— 干员有代号和干员页，历史人物有头衔和在位期。
 * 不区分的话，干员名单里会混进一堆几百年前的皇帝。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 种族字典
        Schema::create('races', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->string('name')->comment('种族名，如「德拉克」');
            $table->string('slug')->unique()->comment('URL 用唯一标识');
            $table->string('english')->nullable()->comment('书里并记的西文名，如 Draco');
            $table->text('description')->nullable()->comment('种族特征概要，取自《大地巡旅》第四章');
            $table->unsignedInteger('sort_order')->default(0)->comment('界面排序权重，越小越靠前');

            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');
        });

        // 地名树
        Schema::create('places', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->string('name')->comment('地名，如「伦蒂尼姆」');
            $table->string('slug')->unique()->comment('URL 用唯一标识');
            $table->foreignId('parent_id')->nullable()
                ->comment('上级地名 ID，用于表达政区层级（法理王国 → 郡 → 市 / 村镇）')
                ->constrained('places')->nullOnDelete();
            $table->string('kind')->default('settlement')
                ->comment('层级类型：nation 国家 / kingdom 法理王国 / region 大区 / province 省 / city 移动城市 / settlement 聚落 / landmark 地理实体');
            $table->foreignId('faction_id')->nullable()
                ->comment('所属政体；与阵营表的层级互为印证')
                ->constrained('factions')->nullOnDelete();
            $table->string('world')->default('terra')->comment('所属世界（World 枚举）：地理天然分世界，不做跨世界的地名树');
            $table->text('description')->nullable()->comment('说明');
            $table->unsignedInteger('sort_order')->default(0)->comment('界面排序权重，越小越靠前');

            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            $table->index('parent_id');
            $table->index(['world', 'kind']);
        });

        // 词条表
        Schema::create('terms', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->string('name')->comment('词条名，如「金律乐章」');
            $table->string('slug')->unique()->comment('URL 用唯一标识');
            $table->string('category')->default('term')
                ->comment('分类：term 术语 / concept 概念 / object 器物 / proper 专名');
            $table->text('definition')->comment('释义，取自《大地巡旅》相应章节');
            $table->string('origin')->nullable()->comment('书中的出处章节，供读者回查原文');
            $table->unsignedInteger('sort_order')->default(0)->comment('界面排序权重，越小越靠前');

            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');
        });

        // 人物的类型 / 头衔 / 在位区间
        Schema::table('characters', function (Blueprint $table) {
            $table->foreignId('race_id')->nullable()
                ->comment('种族字典 ID；有值时以它为准，为空则回落到 race 字符串（「未公开」这类非种族值不入字典）')
                ->constrained('races')->nullOnDelete();
            $table->string('kind')->default('operator')
                ->comment('人物类型：operator 干员 / historical 历史人物（君主、贵族、学者等）');
            $table->string('title')->nullable()->comment('头衔或职衔，如「乌萨斯皇帝」「铁公爵」「大主教」');
            $table->integer('reign_start_index')->nullable()->comment('在位 / 任职起始索引（TerraDate 网格）；只对在位者填写');
            $table->integer('reign_end_index')->nullable()->comment('在位 / 任职结束索引；为空表示至记录时仍在位');

            // 旧的自由字符串在种族字典落地后即失去意义：留着它会出现两处真相，
            // 而「两处真相」最终都会变成「一处对、一处漂移」。作者的原始口径是
            // 「不确定时留空，宁可缺失也不要写错」—— 留空在字典模型里就是 race_id 为 null。
            $table->dropColumn('race');
        });

        // 条目的发生地链接
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('place_id')->nullable()
                ->comment('发生地字典 ID。location 仍是展示原文，两者是「结构化链接 + 展示兜底」的关系')
                ->constrained('places')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['place_id']);
            $table->dropColumn('place_id');
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->dropForeign(['race_id']);
            $table->dropColumn(['race_id', 'kind', 'title', 'reign_start_index', 'reign_end_index']);
        });

        Schema::dropIfExists('terms');
        Schema::dropIfExists('places');
        Schema::dropIfExists('races');
    }
};
