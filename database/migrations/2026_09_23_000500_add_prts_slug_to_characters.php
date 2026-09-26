<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 干员简介的对外链接：为 `characters` 增加 PRTS 维基页面名的显式覆盖。
 *
 * 为什么不直接拿 `name` 拼 URL：
 *
 *  · 本仓库的人物名是本项目自己的写法（例如「管理员」「佩丽卡」），
 *    而 PRTS 的页面标题属于**别人维护的命名空间** —— 两者的对应关系
 *    不是我们能假定的，猜错的结果是跳到一个不存在的页面；
 *  · 有些同名条目在维基上需要消歧义后缀，这不是能从名字推出来的。
 *
 * 因此策略是：**默认可推导（用 name），但必须可覆盖**。
 * 留空表示「按名字拼」，需要时在种子数据或后台里指定即可。
 *
 * 简介本身用的是既有的 `characters.description` 列，不新增字段 ——
 * 那是同一个概念（人物说明），并存只会让人不知道该读哪一个。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('prts_slug')->nullable()
                ->comment('PRTS 维基的页面标题；留空表示按人物名称推导。仅用于拼接外链，不是引用')
                ->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('prts_slug');
        });
    }
};
