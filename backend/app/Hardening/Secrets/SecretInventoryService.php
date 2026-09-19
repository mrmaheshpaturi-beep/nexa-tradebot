<?php

namespace App\Hardening\Secrets;

use App\Hardening\Support\HardeningSafety;
use App\Models\HardeningSecretInventory;
use App\Observability\SecretRedactor;
use Illuminate\Support\Str;

/**
 * Secret inventory / provider / rotation / redaction.
 * No frontend or Git secrets. Values never returned — refs and metadata only.
 */
class SecretInventoryService
{
    /** @var list<array{key:string,category:string}> */
    public const INVENTORY_SEED = [
        ['key' => 'APP_KEY', 'category' => 'APP'],
        ['key' => 'DB_PASSWORD', 'category' => 'DATABASE'],
        ['key' => 'TRADING_BRIDGE_SERVICE_TOKEN', 'category' => 'BRIDGE'],
        ['key' => 'MT5_PASSWORD', 'category' => 'BROKER'],
        ['key' => 'MAIL_PASSWORD', 'category' => 'NOTIFICATION'],
        ['key' => 'REDIS_PASSWORD', 'category' => 'CACHE_QUEUE'],
        ['key' => 'DEV_SUPER_ADMIN_PASSWORD', 'category' => 'DEV_ONLY'],
    ];

    public function __construct(private readonly SecretRedactor $redactor) {}

    public function ensureInventory(): void
    {
        foreach (self::INVENTORY_SEED as $item) {
            HardeningSecretInventory::query()->firstOrCreate(
                ['secret_key' => $item['key']],
                [
                    'public_id' => (string) Str::uuid(),
                    'category' => $item['category'],
                    'provider' => 'ENV',
                    'storage' => 'SERVER_SIDE_ONLY',
                    'frontend_forbidden' => true,
                    'git_forbidden' => true,
                    'status' => 'ACTIVE',
                    'rotation_due_at' => now()->addDays(90),
                    'metadata' => [
                        'vite_forbidden' => true,
                        'bundle_forbidden' => true,
                        'phase' => HardeningSafety::PHASE,
                    ],
                ]
            );
        }
    }

    /** @return array<string, mixed> */
    public function inventory(): array
    {
        $this->ensureInventory();
        $items = HardeningSecretInventory::query()->orderBy('secret_key')->get()->map(fn (HardeningSecretInventory $row) => [
            'public_id' => $row->public_id,
            'secret_key' => $row->secret_key,
            'category' => $row->category,
            'provider' => $row->provider,
            'storage' => $row->storage,
            'frontend_forbidden' => $row->frontend_forbidden,
            'git_forbidden' => $row->git_forbidden,
            'status' => $row->status,
            'last_rotated_at' => $row->last_rotated_at?->toIso8601String(),
            'rotation_due_at' => $row->rotation_due_at?->toIso8601String(),
            'value' => '[NEVER_RETURNED]',
        ])->all();

        return [
            'phase' => HardeningSafety::PHASE,
            'provider' => 'ENV_WITH_ROTATION_METADATA',
            'frontend_secrets' => 'FORBIDDEN',
            'git_secrets' => 'FORBIDDEN',
            'items' => $items,
            'redaction' => 'SecretRedactor',
        ];
    }

    public function rotate(string $secretKey): HardeningSecretInventory
    {
        $this->ensureInventory();
        $row = HardeningSecretInventory::query()->where('secret_key', $secretKey)->firstOrFail();
        $row->forceFill([
            'last_rotated_at' => now(),
            'rotation_due_at' => now()->addDays(90),
            'status' => 'ACTIVE',
            'metadata' => array_merge($row->metadata ?? [], [
                'last_rotation_note' => 'Metadata rotation recorded — operator must replace provider value out-of-band',
                'value_returned' => false,
            ]),
        ])->save();

        return $row;
    }

    /** @param array<string,mixed> $payload */
    public function redact(array $payload): array
    {
        return $this->redactor->redact($payload);
    }

    /** @return array<string, mixed> */
    public function auditPosture(): array
    {
        return [
            'vite_prefix_secrets' => 'FORBIDDEN',
            'browser_storage_secrets' => 'FORBIDDEN',
            'git_tracked_env' => 'FORBIDDEN',
            'audit_payload_redaction' => true,
            'error_message_redaction' => true,
        ];
    }
}
