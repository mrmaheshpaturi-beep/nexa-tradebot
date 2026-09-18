<?php

namespace App\Strategies;

use App\Contracts\TradingStrategyPlugin;
use App\Strategies\Plugins\AdxTrendStrategy;
use App\Strategies\Plugins\BollingerBreakoutStrategy;
use App\Strategies\Plugins\BollingerMeanReversionStrategy;
use App\Strategies\Plugins\BreakoutStrategy;
use App\Strategies\Plugins\EmaPullbackStrategy;
use App\Strategies\Plugins\EmaTrendStrategy;
use App\Strategies\Plugins\MacdMomentumStrategy;
use App\Strategies\Plugins\MarketStructureStrategy;
use App\Strategies\Plugins\MtfTrendStrategy;
use App\Strategies\Plugins\RsiMomentumStrategy;
use App\Strategies\Plugins\SupportResistanceReactionStrategy;
use App\Strategies\Plugins\VolatilityExpansionStrategy;
use InvalidArgumentException;

class StrategyRegistry
{
    /** @var array<string, TradingStrategyPlugin> */
    private array $plugins = [];

    public function __construct()
    {
        foreach ([
            new EmaTrendStrategy,
            new EmaPullbackStrategy,
            new RsiMomentumStrategy,
            new MacdMomentumStrategy,
            new BollingerMeanReversionStrategy,
            new BollingerBreakoutStrategy,
            new AdxTrendStrategy,
            new MarketStructureStrategy,
            new SupportResistanceReactionStrategy,
            new BreakoutStrategy,
            new MtfTrendStrategy,
            new VolatilityExpansionStrategy,
        ] as $plugin) {
            $this->plugins[$plugin->key()] = $plugin;
        }
    }

    public function get(string $key): TradingStrategyPlugin
    {
        $key = strtolower($key);
        if (! isset($this->plugins[$key])) {
            throw new InvalidArgumentException("Unknown strategy plugin: {$key}");
        }

        return $this->plugins[$key];
    }

    public function has(string $key): bool
    {
        return isset($this->plugins[strtolower($key)]);
    }

    /** @return list<TradingStrategyPlugin> */
    public function all(): array
    {
        return array_values($this->plugins);
    }

    /** @return list<array<string, mixed>> */
    public function catalog(): array
    {
        return array_map(fn (TradingStrategyPlugin $p) => [
            'key' => $p->key(),
            'name' => $p->name(),
            'category' => $p->category(),
            'description' => $p->description(),
            'evidence_family' => $p->evidenceFamily(),
            'default_parameters' => $p->defaultParameters(),
            'upload_allowed' => false,
            'execution' => false,
        ], $this->all());
    }
}
