<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditService
{
    public function record(
        string $action,
        ?Model $subject,
        array $before = [],
        array $after = [],
        ?Request $request = null,
        ?string $description = null,
        string $result = 'SUCCESS',
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $request?->user()?->id,
            'action' => $action,
            'module' => str($action)->before('.')->upper()->toString(),
            'entity_type' => $subject ? $subject::class : null,
            'entity_id' => $subject?->getKey(),
            'description' => $description ?? str_replace(['.', '_'], ' ', $action),
            'result' => $result,
            'before' => $before ?: null,
            'after' => $after ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'occurred_at' => now(),
        ]);
    }
}
