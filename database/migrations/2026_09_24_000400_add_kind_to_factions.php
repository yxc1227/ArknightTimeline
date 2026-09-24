<?php

use App\Enums\FactionKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 阵营的**类型**。
 *
 * `factions` 表里其实混着两类不同的东西：
 *
 *  1. **政体与地域**（维多利亚、乌萨斯帝国、文明环带）—— 它们在地名树里各有一个节点；
 *  2. **组织**（企业、社团、武装、机构）—— 莱茵生命、整合运动、黑钢国际……
 *
 * 不区分的话，资料集的「组织」页会变成一张什么都往里塞的表，
 * 读者也就没法回答「终末地工业是什么」与「萨米是什么」是不是同一类问题。
 *
 * 默认值取 `other` 而不是 `polity`：新出现的阵营在没人工归类之前应当**显眼地**
 * 落在「未归类」里，而不是悄悄混进政体、或悄悄混进组织。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factions', function (Blueprint $table) {
            $table->string('kind')->default(FactionKind::Other->value)
                ->comment('类型：polity 政体 / territory 地域 / enterprise 企业 / society 团体 / military 武装 / agency 机构 / other 未归类')
                ->after('full_name');

            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::table('factions', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
