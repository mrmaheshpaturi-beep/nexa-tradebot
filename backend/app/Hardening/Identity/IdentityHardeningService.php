<?php

namespace App\Hardening\Identity;

use App\Hardening\Support\HardeningSafety;
use App\Models\HardeningMfaChallenge;
use App\Models\HardeningNodeIdentity;
use App\Models\HardeningReplayNonce;
use App\Models\HardeningServiceIdentity;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Auth/RBAC/MFA foundation + service + node identity + replay protection.
 * MFA is challenge-based foundation (not full TOTP product) — operators must complete enrollment separately.
 */
class IdentityHardeningService
{
    public function registerService(string $serviceName, array $claims = []): HardeningServiceIdentity
    {
        return HardeningServiceIdentity::query()->updateOrCreate(
            ['service_name' => $serviceName],
            [
                'public_id' => (string) Str::uuid(),
                'identity_kind' => 'SERVICE',
                'fingerprint' => hash('sha256', $serviceName.'|'.HardeningSafety::HARDENING_VERSION),
                'status' => 'ACTIVE',
                'claims' => array_merge([
                    'phase' => HardeningSafety::PHASE,
                    'may_execute' => false,
                    'may_mutate_risk' => false,
                    'order_send' => false,
                ], $claims),
                'issued_at' => now(),
                'expires_at' => now()->addDays(30),
            ]
        );
    }

    public function registerNode(string $label, string $platform = 'LINUX', bool $timeSyncOk = true): HardeningNodeIdentity
    {
        $fp = hash('sha256', strtoupper($label).'|'.$platform);

        return HardeningNodeIdentity::query()->updateOrCreate(
            ['node_fingerprint' => $fp],
            [
                'public_id' => (string) Str::uuid(),
                'node_label' => $label,
                'platform' => strtoupper($platform),
                'status' => 'ACTIVE',
                'time_sync_ok' => $timeSyncOk,
                'tls_required' => true,
                'hardening_checklist' => $this->nodeChecklist($platform),
                'last_seen_at' => now(),
            ]
        );
    }

    /** @return array<string, mixed> */
    public function nodeChecklist(string $platform): array
    {
        $p = strtoupper($platform);

        return [
            'platform' => $p,
            'tls_required' => true,
            'time_sync' => 'NTP_OR_CHRONY_REQUIRED',
            'network_segmentation' => 'RECOMMENDED',
            'windows_hardening' => $p === 'WINDOWS' ? [
                'disable_unnecessary_services' => 'REQUIRED',
                'mt5_terminal_isolation' => 'REQUIRED',
                'firewall_allowlist' => 'REQUIRED',
                'status' => 'CHECKLIST_ONLY_PENDING_MANUAL',
            ] : 'N_A',
            'python_worker_isolation' => 'REQUIRED',
            'live_trading' => 'HARD_BLOCKED',
        ];
    }

    public function issueMfaChallenge(User $user, string $purpose = 'SENSITIVE_OPS'): array
    {
        $code = (string) random_int(100000, 999999);
        $nonce = (string) Str::uuid();
        $row = HardeningMfaChallenge::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'purpose' => strtoupper($purpose),
            'challenge_hash' => Hash::make($code),
            'status' => 'PENDING',
            'expires_at' => now()->addMinutes(5),
            'nonce' => $nonce,
        ]);

        return [
            'public_id' => $row->public_id,
            'nonce' => $nonce,
            'purpose' => $row->purpose,
            'expires_at' => $row->expires_at->toIso8601String(),
            'delivery' => 'FOUNDATION_ONLY',
            'note' => 'MFA foundation — code not returned in production responses; test harness may assert verify path only',
            // Dev/test only hint omitted from API responses by controller when not testing
            '_test_code' => app()->environment('testing') ? $code : null,
        ];
    }

    public function verifyMfaChallenge(string $publicId, string $code, string $nonce): bool
    {
        $row = HardeningMfaChallenge::query()->where('public_id', $publicId)->first();
        if (! $row || $row->status !== 'PENDING' || $row->nonce !== $nonce) {
            return false;
        }
        if ($row->expires_at->isPast()) {
            $row->forceFill(['status' => 'EXPIRED'])->save();

            return false;
        }
        if (! Hash::check($code, $row->challenge_hash)) {
            return false;
        }
        $row->forceFill(['status' => 'CONSUMED', 'consumed_at' => now()])->save();

        return true;
    }

    public function issueReplayNonce(string $purpose, ?User $user = null, int $ttlSeconds = 120): string
    {
        $nonce = (string) Str::uuid();
        HardeningReplayNonce::query()->create([
            'nonce' => $nonce,
            'purpose' => strtoupper($purpose),
            'user_id' => $user?->id,
            'expires_at' => now()->addSeconds($ttlSeconds),
        ]);

        return $nonce;
    }

    public function consumeReplayNonce(string $nonce, string $purpose): bool
    {
        $row = HardeningReplayNonce::query()
            ->where('nonce', $nonce)
            ->where('purpose', strtoupper($purpose))
            ->whereNull('consumed_at')
            ->first();
        if (! $row || $row->expires_at->isPast()) {
            return false;
        }
        $row->forceFill(['consumed_at' => now()])->save();

        return true;
    }

    /** @return array<string, mixed> */
    public function rbacFoundation(): array
    {
        return [
            'roles' => ['SUPER_ADMIN', 'ADMIN', 'TRADER', 'ANALYST', 'VIEWER'],
            'hardening_permissions' => [
                'hardening.view', 'hardening.manage', 'hardening.operate', 'hardening.deploy', 'hardening.secrets',
            ],
            'mfa' => 'FOUNDATION',
            'service_identity' => true,
            'node_identity' => true,
            'replay_protection' => true,
            'ai_bypass_rbac' => false,
        ];
    }
}
