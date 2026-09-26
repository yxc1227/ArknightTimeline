<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 人物也要分世界。
 *
 * 上一轮把阵营 / 人物当作「跨世界共享词典」处理，理由是罗德岛同时存在于
 * 两个世界的历史里 —— 但那条理由对**阵营**成立，对**人物**不成立：
 * 组织可以是跨世界的（罗德岛制药同时也是终末地工业的组建方之一），
 * 而一个人物的档案只属于一个世界。阿米娅是泰拉的干员，佩丽卡是塔卫二的干员，
 * 把两份名单混在一个列表里，读者没法判断某个名字该去哪一边找资料。
 *
 * 因此：
 *   · `characters` 增加 `world`（默认 terra，历史数据不需要回填）；
 *   · 外链也从「PRTS 专用」改成**按世界各自的权威维基** ——
 *     `prts_slug` 随之改名为 `wiki_slug`（它现在可能指向 fz.wiki，
 *     继续叫 prts 就是主动误导）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('world', 32)->default('terra')
                ->comment('所属世界（World 枚举）：terra 泰拉 / talos 塔卫二。人物档案只属于一个世界')
                ->after('slug');
            $table->index('world', 'characters_world_index');
        });

        Schema::table('characters', function (Blueprint $table) {
            // 「默认可推导（用 name），但必须可覆盖」这条策略不变，
            // 只是覆盖对象从一个维基变成了「该世界自己的维基」
            $table->renameColumn('prts_slug', 'wiki_slug');
        });

        Schema::table('characters', function (Blueprint $table) {
            // renameColumn 不带 comment 会清空注释（与 change() 同理），
            // 而迁移注释守卫会因此失败 —— 必须显式补回
            $table->string('wiki_slug')->nullable()
                ->comment('该世界权威维基的条目名；留空表示按人物名称推导。仅用于拼接外链，不是引用')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->renameColumn('wiki_slug', 'prts_slug');
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->string('prts_slug')->nullable()
                ->comment('PRTS 维基的页面标题；留空表示按人物名称推导。仅用于拼接外链，不是引用')
                ->change();
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->dropIndex('characters_world_index');
            $table->dropColumn('world');
        });
    }
};
