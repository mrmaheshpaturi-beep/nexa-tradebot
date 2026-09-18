<?php

namespace Tests\Feature;

use App\Models\Mt5BridgeConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseFourMt5BridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trading_bridge.base_url' => 'http://127.0.0.1:8765',
            'trading_bridge.service_token' => 'phase4-test-token',
        ]);
    }

    public function test_mt5_sync_is_forbidden_for_viewer(): void
    {
        $viewer = $this->userWithRole('VIEWER');
        $connection = Mt5BridgeConnection::create([
            'user_id' => $viewer->id,
            'name' => 'Viewer bridge',
            'mode' => 'REAL',
            'environment' => 'DEMO',
            'status' => 'CONNECTED',
            'is_enabled' => true,
        ]);

        $this->actingAs($viewer)
            ->postJson("/api/v1/mt5/connections/{$connection->id}/sync")
            ->assertForbidden();
    }

    public function test_viewer_can_read_mt5_status_when_granted(): void
    {
        $this->actingAs($this->userWithRole('VIEWER'))
            ->getJson('/api/v1/mt5/status')
            ->assertOk()
            ->assertJsonPath('data.mode', 'READ_ONLY')
            ->assertJsonPath('data.execution_available', false);
    }

    public function test_bridge_proxy_returns_normalized_envelope(): void
    {
        Http::fake([
            '127.0.0.1:8765/v1/health' => Http::response($this->bridgePayload([
                'connected' => true,
                'read_only' => true,
            ])),
        ]);

        $this->actingAs($this->userWithRole('TRADER'))
            ->getJson('/api/v1/mt5/bridge/health')
            ->assertOk()
            ->assertJsonPath('data.read_only', true)
            ->assertJsonPath('meta.environment', 'DEMO');
    }

    public function test_connection_test_sync_and_reconcile_are_permission_scoped(): void
    {
        $this->fakeBridgeReads();

        $admin = $this->userWithRole('SUPER_ADMIN');
        $trader = $this->userWithRole('TRADER');
        $connection = Mt5BridgeConnection::create([
            'user_id' => $admin->id,
            'name' => 'Test bridge',
            'mode' => 'REAL',
            'environment' => 'DEMO',
            'status' => 'UNTESTED',
            'is_enabled' => true,
        ]);

        $this->actingAs($admin)->postJson("/api/v1/mt5/connections/{$connection->id}/test")->assertOk();
        $this->actingAs($this->userWithRole('ANALYST'))
            ->postJson("/api/v1/mt5/connections/{$connection->id}/sync")
            ->assertForbidden();

        $connection->update(['user_id' => $trader->id]);

        $sync = $this->actingAs($trader)->postJson("/api/v1/mt5/connections/{$connection->id}/sync")->assertOk();
        $mappingId = $sync->json('data.account_mapping_id');
        $this->assertNotNull($mappingId);

        $this->actingAs($trader)->postJson("/api/v1/mt5/mappings/{$mappingId}/reconcile")->assertOk()
            ->assertJsonPath('data.status', 'MATCHED');
    }

    public function test_system_status_reports_read_only_bridge_metadata(): void
    {
        $this->getJson('/api/v1/system/status')
            ->assertOk()
            ->assertJsonPath('data.mt5_bridge.mode', 'READ_ONLY')
            ->assertJsonPath('data.execution.broker_transmission', false)
            ->assertJsonPath('data.allow_demo_execution', false);
    }

    private function fakeBridgeReads(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/health')) {
                return Http::response($this->bridgePayload(['connected' => true, 'read_only' => true]));
            }
            if (str_ends_with($path, '/account')) {
                return Http::response($this->bridgePayload([
                    'account_id' => 900001,
                    'currency' => 'USD',
                    'leverage' => 100,
                    'balance' => '10000.00',
                    'equity' => '10024.50',
                    'margin' => '120.00',
                    'free_margin' => '9904.50',
                ]));
            }
            if (str_ends_with($path, '/symbols')) {
                return Http::response($this->bridgePayload([['symbol' => 'EURUSD', 'digits' => 5]]));
            }
            if (str_ends_with($path, '/positions')) {
                return Http::response($this->bridgePayload([[
                    'ticket' => 70001,
                    'symbol' => 'EURUSD',
                    'side' => 'BUY',
                    'volume' => '0.10',
                ]]));
            }
            if (str_ends_with($path, '/orders')) {
                return Http::response($this->bridgePayload([[
                    'ticket' => 71001,
                    'symbol' => 'EURUSD',
                    'type' => 'BUY_LIMIT',
                    'volume_current' => '0.10',
                ]]));
            }
            if (str_ends_with($path, '/history/orders') || str_ends_with($path, '/history/deals')) {
                return Http::response($this->bridgePayload([]));
            }

            return Http::response(['error' => ['code' => 'UNKNOWN', 'message' => 'Unhandled test path']], 404);
        });
    }

    private function bridgePayload(mixed $data): array
    {
        return [
            'data' => $data,
            'meta' => [
                'environment' => 'DEMO',
                'source_timestamp' => now()->toIso8601String(),
                'received_timestamp' => now()->toIso8601String(),
                'freshness' => 'FRESH',
                'adapter_version' => '0.1.0',
                'correlation_id' => 'test-correlation',
            ],
        ];
    }
}
