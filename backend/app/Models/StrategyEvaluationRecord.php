<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StrategyEvaluationRecord extends BaseModel
{
    protected $table = 'strategy_evaluations';

    protected function casts(): array
    {
        return [
            'raw_score' => 'float',
            'confluence_score' => 'float',
            'score_breakdown' => 'array',
            'evidence' => 'array',
            'confluence' => 'array',
            'gate' => 'array',
            'metadata' => 'array',
        ];
    }

    public function strategy(): BelongsTo
    {
        return $this->belongsTo(TradingStrategy::class, 'trading_strategy_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
