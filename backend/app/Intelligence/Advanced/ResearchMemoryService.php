<?php

namespace App\Intelligence\Advanced;

use App\Intelligence\Support\IntelligenceSafety;
use App\Models\IntelligenceMemoryRecord;
use App\Models\User;
use Illuminate\Http\Request;
use App\Services\AuditService;

/**
 * Immutable pre/post-trade intelligence and research memory.
 * Append-only — no updates/deletes via service API.
 */
class ResearchMemoryService
{
    public function __construct(
        private readonly AuditService $audit = new AuditService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        User $user,
        string $kind,
        array $payload,
        ?string $symbol = null,
        ?int $assessmentId = null,
        ?Request $request = null,
    ): IntelligenceMemoryRecord {
        $kind = strtoupper($kind);
        if (! in_array($kind, ['PRE_TRADE', 'POST_TRADE', 'RESEARCH', 'SHADOW_NOTE'], true)) {
            $kind = 'RESEARCH';
        }

        $contentHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $row = IntelligenceMemoryRecord::query()->create([
            'user_id' => $user->id,
            'intelligence_assessment_id' => $assessmentId,
            'kind' => $kind,
            'symbol' => $symbol,
            'mode' => strtoupper((string) ($payload['mode'] ?? 'ADVISORY')),
            'content_hash' => $contentHash,
            'payload' => $payload,
            'immutable' => true,
            'orchestrator_version' => IntelligenceSafety::ORCHESTRATOR_VERSION,
            'recorded_at' => now(),
        ]);

        $this->audit->record('intelligence.memory_recorded', $row, [], [
            'kind' => $kind,
            'content_hash' => $contentHash,
            'immutable' => true,
            'order_send' => false,
        ], $request);

        return $row;
    }

    /**
     * Attempted mutation always refused — memory is append-only.
     *
     * @return array<string, mixed>
     */
    public function refuseMutation(string $action): array
    {
        return [
            'refused' => true,
            'action' => $action,
            'reason' => 'INTELLIGENCE_MEMORY_IMMUTABLE',
            'order_send' => false,
            'allowed' => false,
        ];
    }
}
