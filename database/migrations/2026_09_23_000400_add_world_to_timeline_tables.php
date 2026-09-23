<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 给时间线引入「世界」维度（泰拉 / 塔卫二）。
 *
 * 这是加入《明日方舟：终末地》塔卫二时间线的前提，而不是一个可选的筛选项：
 *
 *   · 排序键 `index = year * 372 + (month-1) * 31 + (day-1)` 是没有量纲的整数网格，
 *     **只在同一纪年体系内部可比**。泰拉历 1097 年与塔罗斯历 5 年之间不存在
 *     任何可比较的关系，混在一条 ORDER BY 里会得到一个看似正常、实则无意义的顺序。
 *   · 更具体的后果：泰拉第一个纪元「远古 · 前纪元」覆盖 -186000 ~ 371627，
 *     塔罗斯历 5 年的索引 1860 恰好落在其中 —— 纪元自动归属会把塔卫二的事件
 *     归进泰拉纪元，全程不报任何错。
 *
 * 因此凡是做区间比较的地方（纪元归属、纪元区间不重叠、时间轴刻度、年代分布）
 * 都必须先按世界分组。
 *
 * `world` 默认 `terra`：历史数据与未指定世界的写入全部落在泰拉，迁移不需要回填。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eras', function (Blueprint $table) {
            $table->string('world', 32)->default('terra')
                ->comment('所属世界（World 枚举）：terra 泰拉 / talos 塔卫二。纪元区间只允许在同一世界内比较')
                ->after('slug');
            $table->index('world', 'eras_world_index');
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->string('world', 32)->default('terra')
                ->comment('该出处主要记述的世界（World 枚举）；仅用于筛选与分组。跨世界引用不禁止：一份泰拉设定集完全可以提到塔卫二')
                ->after('slug');
            $table->index('world', 'sources_world_index');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->string('world', 32)->default('terra')
                ->comment('所属世界（World 枚举）。不同世界的纪年数值不可比，因此时间线查询与区间比较必须先按本列隔离')
                ->after('id');

            // 复合索引把 world 放在最前：时间线的每一条查询都会带 world 条件。
            // 只按 start_index 建索引，会让优化器先跨世界扫描再过滤。
            $table->index(['world', 'start_index', 'sort_seq'], 'events_world_timeline_order_index');
        });

        // 旧的单世界索引被上面那条取代：没有任何查询会在缺少 world 条件的情况下
        // 按 start_index 排序了，留着它只会增加写入成本
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('events_timeline_order_index');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->index(['start_index', 'sort_seq'], 'events_timeline_order_index');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('events_world_timeline_order_index');
            $table->dropColumn('world');
        });

        Schema::table('eras', function (Blueprint $table) {
            $table->dropIndex('eras_world_index');
            $table->dropColumn('world');
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->dropIndex('sources_world_index');
            $table->dropColumn('world');
        });
    }
};
