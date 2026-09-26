<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 人物与阵营：一人一职 → 多归属。
 *
 * 原先 `characters.faction_id` 只装得下一个阵营，于是导入名单时只能「取最具体的那个」
 * （幽灵鲨取「深海猎人」，丢掉「阿戈尔」）。而这两条都是事实：她是深海猎人，
 * 深海猎人又属阿戈尔。丢掉的那一半不是冗余，是信息。
 *
 * 因此改成枢轴，并**撤掉 `faction_id` 这一列** —— 保留它就意味着同一条归属有两个出处：
 * 一个是列、一个是枢轴里的行，二者迟早会不一致，而页面上看不出哪个对。
 *
 * `sort_order` 记的是「哪一条更具体」：来源本身把归属分成国别 / 团体 / 小队三层，
 * 顺序照抄它，而不是在这里另立一套优先级。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('character_faction', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->foreignId('character_id')->comment('人物 ID')
                ->constrained('characters')->cascadeOnDelete();
            $table->foreignId('faction_id')->comment('阵营 ID')
                ->constrained('factions')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0)
                ->comment('同一人物内部的展示顺序，越小越具体（来源的层序：小队 → 团体 → 国别）');

            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            // 同一个人不得重复挂同一个阵营
            $table->unique(['character_id', 'faction_id']);
        });

        // 把既有的单一归属搬进枢轴 —— 在这次改造之前，它是唯一的真相来源
        DB::table('characters')
            ->whereNotNull('faction_id')
            ->orderBy('id')
            ->each(function (object $row): void {
                DB::table('character_faction')->insert([
                    'character_id' => $row->id,
                    'faction_id' => $row->faction_id,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('characters', function (Blueprint $table) {
            /*
             * 先拆索引，再拆外键与列。
             *
             * 建表时给 `faction_id` 单独加过一个索引，而删列不会顺带拆掉它：
             * SQLite 直接报「index … after drop column: no such column」，
             * MySQL 则可能留下一个指向不存在列的索引。两边都不该留这种东西。
             */
            if (Schema::hasIndex('characters', 'characters_faction_id_index')) {
                $table->dropIndex('characters_faction_id_index');
            }

            $table->dropConstrainedForeignId('faction_id');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->foreignId('faction_id')->nullable()->after('codename')
                ->comment('所属阵营 ID；留空表示阵营未知')
                ->constrained('factions')->nullOnDelete();

            $table->index('faction_id');
        });

        // 回滚时只还原「最具体的那一条」：多归属这个信息在原结构里根本装不下，
        // 与其随便挑一条塞回单列，不如就承认它丢了
        foreach (DB::table('character_faction')->orderBy('sort_order')->orderBy('id')->get() as $row) {
            DB::table('characters')
                ->where('id', $row->character_id)
                ->whereNull('faction_id')
                ->update(['faction_id' => $row->faction_id]);
        }

        Schema::dropIfExists('character_faction');
    }
};
