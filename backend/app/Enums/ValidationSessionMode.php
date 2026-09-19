<?php

namespace App\Enums;

/**
 * Forward-validation modes. Distinct from backtest.
 * LIVE validation does not exist.
 */
enum ValidationSessionMode: string
{
    case DemoForward = 'DEMO_FORWARD';
    case DryRunShadow = 'DRY_RUN_SHADOW';
    case DemoAutoShadow = 'DEMO_AUTO_SHADOW';
    case AiShadow = 'AI_SHADOW';
}
