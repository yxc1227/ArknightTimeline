<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 事件的多对多关系：出处、人物、阵营、标签。
 * 全部使用「关系表 + 元数据」而非 JSON 列，以便按维度检索与索引。
 *
 * 约定：外键列的 comment() 一律写在 constrained() 之前 ——
 * constrained() 会返回 ForeignKeyDefinition，写在它后面的注释会挂到外键对象上并丢失。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 事件 ←→ 出处。同一事件可被多个出处记录（互为佐证或互相矛盾）
        Schema::create('event_source', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->foreignId('event_id')->comment('所属事件')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->comment('出处')->constrained()->cascadeOnDelete();
            $table->string('chapter')->nullable()->comment('该出处内的具体章节，用于告诉审核人去哪里核对');
            $table->string('stage_code')->nullable()->comment('关卡号，如「7-18」');
            $table->text('quote')->nullable()->comment('原文佐证片段。必须能在 sources.raw_text 中定位，否则视为「无出处支撑」');
            $table->integer('quote_offset')->nullable()->comment('引文在 sources.raw_text 中的字符偏移，用于一键回跳原文');
            $table->boolean('is_primary')->default(false)->comment('是否为主要出处；同一事件存在互相矛盾的多个出处时用于区分主次');
            $table->unsignedInteger('sort_order')->default(0)->comment('展示排序权重');

            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            $table->unique(['event_id', 'source_id', 'stage_code']);
            $table->index('source_id');
        });

        // 事件 ←→ 人物
        Schema::create('event_character', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->foreignId('event_id')->comment('所属事件')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->comment('相关人物')->constrained()->cascadeOnDelete();
            $table->string('role')->default('support')->comment('该人物在本事件中的角色：protagonist 主角 / support 支援 / mentioned 仅提及');
            $table->text('note')->nullable()->comment('补充说明');

            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            $table->unique(['event_id', 'character_id']);
            $table->index('character_id');
        });

        // 事件 ←→ 阵营
        Schema::create('event_faction', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->foreignId('event_id')->comment('所属事件')->constrained()->cascadeOnDelete();
            $table->foreignId('faction_id')->comment('相关阵营')->constrained()->cascadeOnDelete();
            $table->string('role')->default('involved')->comment('该阵营在本事件中的立场：instigator 发起方 / involved 参与方 / victim 受害方');
            $table->text('note')->nullable()->comment('补充说明');

            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            $table->unique(['event_id', 'faction_id']);
            $table->index('faction_id');
        });

        // 事件 ←→ 标签
        Schema::create('event_tag', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->foreignId('event_id')->comment('所属事件')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->comment('标签')->constrained()->cascadeOnDelete();
            $table->foreignId('tagged_by')->nullable()
                ->comment('打标签的人')
                ->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            $table->unique(['event_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_tag');
        Schema::dropIfExists('event_faction');
        Schema::dropIfExists('event_character');
        Schema::dropIfExists('event_source');
    }
};
