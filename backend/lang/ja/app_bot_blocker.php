<?php

return [

    'policies' => [
        'allow_all' => [
            'title' => 'すべてのAIボットを許可',
            'description' => 'AIクローラーはブロックされません。',
        ],
        'block_training' => [
            'title' => 'AI学習ボットをブロック',
            'description' => 'コンテンツをAIモデルの学習に利用するボットを停止します。ChatGPT検索やPerplexityなど、訪問者を送ってくれるAI検索エンジンは引き続き機能します。',
        ],
        'block_all' => [
            'title' => 'すべてのAIボットをブロック',
            'description' => 'AI検索結果からのアクセスを送ってくれるボットも含め、既知のすべてのAIボットをブロックします。',
        ],
    ],

];
