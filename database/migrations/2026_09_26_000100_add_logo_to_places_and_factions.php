<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 地名与组织的徽记。
 *
 * 与人物头像（`characters.avatar`）同一套规矩，理由也相同：
 *
 *  1. 存**相对路径**而不是外键或 URL —— 徽记本体是本地资产
 *     （`public/assets/emblems/`），数据库只记「这一条用的是哪一枚」；
 *  2. 目录按**世界**分（terra / talos），不按来源站分：来源（从哪个维基抓的）
 *     是采集期的事，运行期读者只关心这枚徽记属于哪个世界；
 *  3. 留空是常态。维基只给了 47 枚徽记，而库里有 164 个地名与 147 个阵营
 *     —— 大部分实体根本没有官方徽记，页面据此退回纯文字，而不是渲染碎图。
 *
 * 两个表都要这一列，因为同一个实体可能两边都有行：地名表里的「维多利亚」
 * 是它的疆域，阵营表里的「维多利亚」是它的政体，两行说的是同一个维多利亚，
 * 徽记也就该在两处都出现（关联时按名字各查一次，自然落到两边，不是特例）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->string('logo')->nullable()->after('kind')
                ->comment('徽记相对路径（public/ 下，如 assets/emblems/terra/维多利亚.png）；无图时留空');
        });

        Schema::table('factions', function (Blueprint $table) {
            $table->string('logo')->nullable()->after('color')
                ->comment('徽记相对路径（public/ 下，如 assets/emblems/terra/罗德岛.png）；无图时留空');
        });
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->dropColumn('logo');
        });

        Schema::table('factions', function (Blueprint $table) {
            $table->dropColumn('logo');
        });
    }
};
