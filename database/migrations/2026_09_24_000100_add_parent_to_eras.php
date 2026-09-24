<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 纪元的上级「时代」。
 *
 * 两种粒度服务两件事：
 *
 *  - **纪元**（既有）是**分桶**用的：条目必须落进恰好一个纪元，筛选、分组、时间轴都按它走；
 *  - **时代**（本次新增）是**分期**用的：《大地巡旅》年表的标题就写着「结晶时代 —— 泰拉历 797 年至今」，
 *    那是书里自己给出的分期名，比任何自造的说法都可靠。它是给人读的标签，不是条目的容器。
 *
 * 这解释了为什么父子区间会**故意重叠**：父的区间 = 子的并集。原先那条
 * 「同一世界内纪元区间不重叠」的断言因此改为**同级不重叠**
 * （见 SeederIntegrityTest），并补上一条「子的区间必须落在父之内」。
 *
 * 也因此，父级不参与三处「挑一个桶」的逻辑：条目自动归属、时间轴色带、
 * 筛选下拉 —— 它们要的都是**叶子**纪元，否则条目会被塞进一个只是标签的时代里。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eras', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()
                ->comment('上级「时代」ID；为空表示它本身就是一个时代。时代只做分期标签，条目只挂叶子纪元')
                ->constrained('eras')->nullOnDelete();

            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('eras', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['parent_id']);
            $table->dropColumn('parent_id');
        });
    }
};
