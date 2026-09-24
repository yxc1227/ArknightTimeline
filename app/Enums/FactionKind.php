<?php

namespace App\Enums;

/**
 * 阵营的类型。
 *
 * 这个维度要回答的是一个很具体的问题：**「它是不是一个组织」**。
 *
 *  - **政体**（维多利亚、乌萨斯帝国）与**地域**（文明环带）在地名树里各有一个节点，
 *    资料集里应当出现在「地名」页 —— 它们有疆域、有层级、有可归属的条目；
 *  - **组织**（企业、团体、武装、机构）才是「组织」一词通常指的东西：
 *    有成员、有归属，但没有边界。
 *
 * 不区分的话，「组织」页会变成一张什么都往里塞的表，而读者无法回答
 * 「终末地工业是什么」与「萨米是什么」是不是同一类问题。
 */
enum FactionKind: string
{
    case Polity = 'polity';           // 政体：国家、城邦
    case Territory = 'territory';     // 地域：地区性实体（历史原因登记在阵营表里）
    case Enterprise = 'enterprise';   // 企业：公司、厂商、事务所
    case Society = 'society';         // 团体：结社、运动、匪帮、松散团体
    case Military = 'military';       // 武装：军队、敌对武装势力
    case Agency = 'agency';           // 机构：政府部门、委员会、组织内部部门
    case Other = 'other';             // 尚未归类

    public function label(): string
    {
        return match ($this) {
            self::Polity => '政体',
            self::Territory => '地域',
            self::Enterprise => '企业',
            self::Society => '团体',
            self::Military => '武装',
            self::Agency => '机构',
            self::Other => '未归类',
        };
    }

    /**
     * 资料集「组织」页收录的类型。
     *
     * 政体与地域**不在其列**（见类注释）。这个判断只有一处，
     * 因为「哪些算组织」是这套分类唯一真正需要一致的地方 ——
     * 散落成多个 in_array 之后，迟早会有一处漏掉一个类型。
     */
    public function isOrganization(): bool
    {
        return ! in_array($this, [self::Polity, self::Territory], true);
    }

    /**
     * 组织页的分组顺序：由「有实体产出的」到「未归类」。
     *
     * @return list<self>
     */
    public static function organizationOrder(): array
    {
        return [self::Enterprise, self::Society, self::Military, self::Agency, self::Other];
    }

    /**
     * 组织页收录的类型取值（供查询使用）。
     *
     * @return list<string>
     */
    public static function organizationValues(): array
    {
        return array_map(
            fn (self $kind) => $kind->value,
            array_values(array_filter(self::cases(), fn (self $kind) => $kind->isOrganization())),
        );
    }
}
