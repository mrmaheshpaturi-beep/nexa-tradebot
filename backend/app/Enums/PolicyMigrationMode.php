<?php

namespace App\Enums;

/** Explicit KEEP_ORIGINAL vs MIGRATE — never silent (Phase 11 §8). */
enum PolicyMigrationMode: string
{
    case KeepOriginal = 'KEEP_ORIGINAL';
    case Migrate = 'MIGRATE';
}
