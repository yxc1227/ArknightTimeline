<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 词条的**世界归属**。
 *
 * 与阵营、种族不同，词条是可以分世界的：它是一批**释义**，而释义天然带着视角 ——
 * 「金律乐章」是莱塔尼亚的立国宪章，「协议」是塔卫二上的失落之物，
 * 把它们混在一页里，读者无从判断某个词属于哪边的历史。
 *
 * 因此这一列**可空**，且空值有明确含义：**两个世界通用**。
 * 「源石」这类概念两边都成立，强行归给某一方反而错；
 * 而把「通用」表达成空值，也比编一个 `world = 'both'` 的伪取值干净 ——
 * 后者迟早会被某个 `where('world', $w)` 漏掉。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->string('world')->nullable()
                ->comment('所属世界（World 枚举）；为空表示两个世界通用')
                ->after('category');

            $table->index('world');
        });
    }

    public function down(): void
    {
        Schema::table('terms', function (Blueprint $table) {
            $table->dropIndex(['world']);
            $table->dropColumn('world');
        });
    }
};
