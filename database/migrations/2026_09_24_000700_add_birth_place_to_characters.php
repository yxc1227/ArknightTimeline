<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 人物的出身地。
 *
 * 两列并存，与条目的「发生地」同一条规矩：
 *
 *  - `birth_place` 是**照来源抄下来的写法**（「乌萨斯」「炎」「未公开」「北方大陆（自称）」）；
 *  - `birth_place_id` 是能对上的**地名树节点**，对不上就留空。
 *
 * 只存其中一列都不行：只存原文，读者点不进去；只存 ID，
 * 那么「未公开」这类写法会连记录都没有 —— 而「来源说了是未公开」与
 * 「我们还没录」是两件事，前者恰恰是应当保留的信息。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('birth_place')->nullable()->after('codename')
                ->comment('出身地：照来源抄下来的写法，可能是「未公开」这类非地名值');

            $table->foreignId('birth_place_id')->nullable()->after('birth_place')
                ->comment('出身地对应的地名节点；来源写法对不上字典时留空')
                ->constrained('places')->nullOnDelete();

            $table->index('birth_place_id');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('birth_place_id');
            $table->dropColumn('birth_place');
        });
    }
};
