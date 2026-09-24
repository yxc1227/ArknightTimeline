<?php

namespace App\Enums;

/**
 * 人物类型：这个人在这份年表里是什么身份。
 *
 *  - **干员**：可操作，有代号与干员页；这份名单服务的是「这支队伍里有谁」；
 *  - **历史人物**：书里的君主、贵族与学者，以及远古的传说存在。有头衔与在位期；
 *  - **剧情人物**：**现代但不是干员**的人 —— 组织的创办者、地方上的办事人
 *    （黑钢国际的克里夫、企鹅物流的大帝）。他们既没有干员页，也不是「几百年前的人」。
 *
 * 分档不是为了分类学上的整齐：干员名单要回答的是「谁在出勤」，
 * 把几百年前的皇帝与当代的组织创办者混进去，读者就分不清谁还在名单上。
 * 第三档是导入 PRTS 名单时才浮出来的 —— 在那之前，这两位只能挤在「历史人物」里，
 * 名不副实却无处可去。
 */
enum CharacterKind: string
{
    case Operator = 'operator';
    case Historical = 'historical';
    case Npc = 'npc';

    public function label(): string
    {
        return match ($this) {
            self::Operator => '干员',
            self::Historical => '历史人物',
            self::Npc => '剧情人物',
        };
    }
}
