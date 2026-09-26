<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI 梳理（AI 补全）
    |--------------------------------------------------------------------------
    |
    | AI 输出永远不会直接写进 events 表，而是落到 ai_proposals 等待人工放行。
    | 这里只配置「怎么调用模型」，不配置「能不能绕过审核」——那条约束写在
    | AiEventSynthesizer / ProposalApplier 的代码里，不在配置层暴露开关。
    |
    */

    'ai' => [
        // heuristic：纯规则的离线抽取，零依赖零成本，作为默认与兜底
        // openai-compatible：任意兼容 OpenAI Chat Completions 的服务
        'driver' => env('TIMELINE_AI_DRIVER', 'heuristic'),

        'endpoint' => env('TIMELINE_AI_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
        'api_key' => env('TIMELINE_AI_KEY'),
        'model' => env('TIMELINE_AI_MODEL', 'gpt-4o-mini'),
        'timeout' => (int) env('TIMELINE_AI_TIMEOUT', 60),

        // 单次梳理最多产出多少条提案，防止模型一次性把整本书吐出来难以审核
        'max_candidates' => (int) env('TIMELINE_AI_MAX_CANDIDATES', 25),

        // 每次梳理注入上下文时携带的既有事件条数（去重参考）
        'context_event_limit' => (int) env('TIMELINE_AI_CONTEXT_LIMIT', 60),

        // 低于该置信度的提案直接落为低分档，审核面板会强提示
        'low_confidence_threshold' => (int) env('TIMELINE_AI_LOW_CONFIDENCE', 55),
    ],

    /*
    |--------------------------------------------------------------------------
    | 人物与干员
    |--------------------------------------------------------------------------
    |
    | 本仓库只维护**与时间线相关的一行简介**，人物档案的权威内容一律外链到
    | 该世界自己的维基 —— 与其在这里维护一份必然过时、又无法追溯出处的副本，
    | 不如把读者送到真正在维护它的地方。
    |
    | 「各自的」这三个字是关键：泰拉的干员档案在 PRTS，终末地的人员档案在
    | 终末地 WIKI（fz.wiki）。两者是不同的社区、不同的条目体系，
    | 用同一个 base 拼链接必然有一半是错的。
    |
    | 做成配置而不是写死在模型里：换镜像站、换语言版本、对方改路径都不需要改代码。
    |
    */

    'character' => [
        'wikis' => [
            // 泰拉：PRTS（玩家共同维护的《明日方舟》中文维基）
            'terra' => [
                'base' => env('TIMELINE_WIKI_BASE', 'https://prts.wiki/w/'),
                'label' => env('TIMELINE_WIKI_LABEL', 'PRTS 维基'),
            ],
            // 塔卫二：终末地 WIKI（社区维护的《明日方舟：终末地》百科）
            // 人物条目统一挂在 /wiki/干员/ 命名空间下（实际形如 /wiki/干员/佩丽卡），
            // 因此 base 以 /wiki/干员/ 结尾，拼接时再对名字做 rawurlencode。
            // 注意尾巴的「干员/」不是我们能猜的 —— 由用户提供的真实链接反查确认，
            // 漏掉它就会 404。
            'talos' => [
                'base' => env('TIMELINE_TALOS_WIKI_BASE', 'https://www.fz.wiki/wiki/干员/'),
                'label' => env('TIMELINE_TALOS_WIKI_LABEL', '终末地 WIKI'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 一致性巡检阈值
    |--------------------------------------------------------------------------
    */

    'consistency' => [
        // 标题相似度超过该值且时间区间重叠 → 判为疑似重复
        'duplicate_similarity' => (float) env('TIMELINE_DUPLICATE_SIMILARITY', 0.82),

        // 同一节点的条目数超过该值 → 单日过载提示
        'overloaded_day_threshold' => (int) env('TIMELINE_OVERLOADED_DAY', 12),

        // 出处引文中的年份与条目年份相差超过该值 → 出处矛盾告警
        'source_year_tolerance' => (int) env('TIMELINE_SOURCE_YEAR_TOLERANCE', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | 协作
    |--------------------------------------------------------------------------
    */

    'collaboration' => [
        // 编辑租约时长（秒），前端每 60s 续租
        'lease_seconds' => (int) env('TIMELINE_LEASE_SECONDS', 300),

        // 每页时间线条目数
        'per_page' => (int) env('TIMELINE_PER_PAGE', 40),

        // 单条事件最多保留的版本数（超出后裁剪最旧的，但永远保留首版）
        'revision_keep' => (int) env('TIMELINE_REVISION_KEEP', 60),
    ],

];
