<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 事件的多对多关系：出处、人物、阵营、标签。
 * 全部使用「关系表 + 元数据」而非 JSON 列，以便按维度检索与索引。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 事件 ←→ 出处。同一事件可被多个出处记录（互为佐证或互相矛盾）
        Schema::create('event_source', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('chapter')->nullable();     // 具体章节 / 关卡
            $table->string('stage_code')->nullable();  // 如「7-18」
            $table->text('quote')->nullable();         // 原文佐证片段
            $table->integer('quote_offset')->nullable(); // 原文中的字符偏移，可回溯定位
            $table->boolean('is_primary')->default(false); // 主要出处
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['event_id', 'source_id', 'stage_code']);
            $table->index('source_id');
        });

        // 事件 ←→ 人物
        Schema::create('event_character', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('support'); // protagonist / support / mentioned
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'character_id']);
            $table->index('character_id');
        });

        // 事件 ←→ 阵营
        Schema::create('event_faction', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('faction_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('involved'); // instigator / involved / victim
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'faction_id']);
            $table->index('faction_id');
        });

        // 事件 ←→ 标签
        Schema::create('event_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tagged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

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
