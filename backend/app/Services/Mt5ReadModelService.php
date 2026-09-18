<?php

namespace App\Services;

use App\Models\AccountSnapshot;
use App\Models\BrokerAccount;
use App\Models\InstrumentAlias;
use App\Models\Mt5AccountMapping;
use App\Models\Mt5BridgeConnection;
use App\Models\Mt5ExternalDeal;
use App\Models\Mt5ExternalOrder;
use App\Models\Mt5ExternalPosition;
use App\Models\Mt5ReconciliationRun;
use App\Models\Mt5SyncCursor;
use App\Models\TradingInstrument;
use Illuminate\Support\Facades\DB;

class Mt5ReadModelService
{
    public function __construct(private readonly TradingBridgeClient $bridge) {}

    public function sync(Mt5BridgeConnection $connection): array
    {
        $accountPayload = $this->bridge->get('account');
        $symbolPayload = $this->bridge->get('symbols');
        $positionPayload = $this->bridge->get('positions');
        $orderPayload = $this->bridge->get('orders');
        $from = now()->subDays(7)->utc()->toIso8601String();
        $to = now()->utc()->toIso8601String();
        $historyOrders = $this->bridge->get('history/orders', compact('from', 'to'));
        $historyDeals = $this->bridge->get('history/deals', compact('from', 'to'));

        return DB::transaction(function () use (
            $connection, $accountPayload, $symbolPayload, $positionPayload, $orderPayload,
            $historyOrders, $historyDeals
        ): array {
            $account = $accountPayload['data'];
            $externalAccountId = (string) ($account['account_id'] ?? '');
            $brokerAccount = BrokerAccount::firstOrCreate([
                'user_id' => $connection->user_id,
                'account_reference' => 'MT5-DEMO-'.$externalAccountId,
            ], [
                'name' => 'MT5 DEMO '.$externalAccountId,
                'broker' => 'MetaTrader 5',
                'platform' => 'MT5',
                'environment' => 'DEMO',
                'currency' => (string) ($account['currency'] ?? 'USD'),
                'leverage' => (int) ($account['leverage'] ?? 1),
                'status' => 'CONNECTED',
                'is_enabled' => false,
                'created_by' => $connection->user_id,
                'metadata' => ['read_only' => true],
            ]);
            $mapping = Mt5AccountMapping::updateOrCreate([
                'mt5_bridge_connection_id' => $connection->id,
                'external_account_id' => $externalAccountId,
            ], [
                'broker_account_id' => $brokerAccount->id,
                'currency' => $account['currency'] ?? null,
                'leverage' => $account['leverage'] ?? null,
                'last_synced_at' => now(),
                'metadata' => ['source' => 'MT5', 'environment' => 'DEMO'],
            ]);

            $capturedAt = data_get($accountPayload, 'meta.source_timestamp', now()->toIso8601String());
            AccountSnapshot::updateOrCreate([
                'broker_account_id' => $brokerAccount->id,
                'source' => 'MT5',
                'external_snapshot_id' => hash('sha256', $externalAccountId.'|'.$capturedAt),
            ], [
                'environment' => 'DEMO',
                'balance' => $account['balance'] ?? 0,
                'equity' => $account['equity'] ?? 0,
                'margin' => $account['margin'] ?? 0,
                'free_margin' => $account['free_margin'] ?? 0,
                'margin_level' => $account['margin_level'] ?? null,
                'floating_pnl' => ($account['equity'] ?? 0) - ($account['balance'] ?? 0),
                'drawdown' => 0,
                'open_positions' => count($positionPayload['data']),
                'captured_at' => $capturedAt,
            ]);

            foreach ($symbolPayload['data'] as $spec) {
                $symbol = strtoupper((string) ($spec['symbol'] ?? $spec['name'] ?? ''));
                if ($symbol === '') {
                    continue;
                }
                $instrument = TradingInstrument::where('symbol', $symbol)->first();
                InstrumentAlias::updateOrCreate([
                    'mt5_bridge_connection_id' => $connection->id,
                    'external_symbol' => $symbol,
                ], [
                    'trading_instrument_id' => $instrument?->id,
                    'normalized_symbol' => $symbol,
                    'external_spec' => $spec,
                    'spec_observed_at' => data_get($symbolPayload, 'meta.source_timestamp', now()),
                    'spec_hash' => hash('sha256', json_encode($spec)),
                ]);
            }

            $positionCount = $this->syncPositions($mapping, $positionPayload['data']);
            $orderCount = $this->syncOrders($mapping, [
                ...$orderPayload['data'],
                ...$historyOrders['data'],
            ]);
            $dealCount = $this->syncDeals($mapping, $historyDeals['data']);
            foreach (['POSITIONS', 'ORDERS', 'DEALS'] as $resource) {
                Mt5SyncCursor::updateOrCreate([
                    'mt5_account_mapping_id' => $mapping->id,
                    'resource' => $resource,
                ], [
                    'cursor_value' => now()->utc()->toIso8601String(),
                    'last_source_at' => now(),
                    'last_synced_at' => now(),
                ]);
            }
            $connection->update([
                'status' => data_get($accountPayload, 'meta.freshness') === 'STALE' ? 'STALE' : 'CONNECTED',
                'last_connected_at' => now(),
                'last_stale_at' => data_get($accountPayload, 'meta.freshness') === 'STALE' ? now() : $connection->last_stale_at,
                'last_error_code' => null,
            ]);

            return [
                'account_mapping_id' => $mapping->id,
                'snapshots' => 1,
                'symbols' => count($symbolPayload['data']),
                'positions' => $positionCount,
                'orders' => $orderCount,
                'deals' => $dealCount,
                'source' => 'MT5',
                'environment' => 'DEMO',
            ];
        });
    }

    public function reconcile(Mt5AccountMapping $mapping, int $userId): Mt5ReconciliationRun
    {
        $rawPositions = $this->bridge->get('positions')['data'] ?? [];
        $positionRecords = is_array($rawPositions) && array_is_list($rawPositions)
            ? $rawPositions
            : (is_array($rawPositions) ? [$rawPositions] : []);
        $observed = collect($positionRecords)
            ->filter(fn ($item): bool => is_array($item))
            ->keyBy(fn (array $item): string => (string) ($item['ticket'] ?? ''));
        $stored = $mapping->positions()->where('status', 'OPEN')->get()->keyBy('external_id');
        $run = Mt5ReconciliationRun::create([
            'user_id' => $userId,
            'mt5_account_mapping_id' => $mapping->id,
            'status' => 'RUNNING',
            'source' => 'MT5',
            'environment' => 'DEMO',
            'started_at' => now(),
        ]);
        $matched = 0;
        $mismatched = 0;
        foreach ($stored->keys()->merge($observed->keys())->map(fn ($id) => (string) $id)->unique() as $externalId) {
            $expected = $stored->get($externalId);
            $actual = $observed->get($externalId);
            $consistent = $expected && $actual
                && (string) $expected->symbol === (string) ($actual['symbol'] ?? '')
                && number_format((float) $expected->volume, 4, '.', '') === number_format((float) ($actual['volume'] ?? 0), 4, '.', '');
            $consistent ? $matched++ : $mismatched++;
            $run->items()->create([
                'resource_type' => 'POSITION',
                'external_id' => (string) $externalId,
                'status' => $consistent ? 'MATCHED' : 'MISMATCH',
                'reason_code' => $consistent ? null : ($expected ? 'VALUE_MISMATCH_OR_MISSING_SOURCE' : 'MISSING_PROJECTION'),
                'expected' => $expected?->only(['symbol', 'side', 'volume', 'price_open', 'status']),
                'observed' => $actual,
            ]);
        }
        $run->update([
            'status' => $mismatched > 0 ? 'MISMATCHES_FOUND' : 'MATCHED',
            'matched_count' => $matched,
            'mismatch_count' => $mismatched,
            'completed_at' => now(),
        ]);

        return $run->fresh('items');
    }

    private function syncPositions(Mt5AccountMapping $mapping, array $records): int
    {
        foreach ($records as $record) {
            Mt5ExternalPosition::updateOrCreate([
                'mt5_account_mapping_id' => $mapping->id,
                'external_id' => (string) ($record['ticket'] ?? ''),
            ], [
                'symbol' => $record['symbol'] ?? 'UNKNOWN',
                'side' => $record['side'] ?? $record['type'] ?? null,
                'volume' => $record['volume'] ?? 0,
                'price_open' => $record['price_open'] ?? null,
                'price_current' => $record['price_current'] ?? null,
                'profit' => $record['profit'] ?? 0,
                'status' => 'OPEN',
                'source_updated_at' => $record['time'] ?? null,
                'last_seen_at' => now(),
                'payload' => $record,
            ]);
        }

        return count($records);
    }

    private function syncOrders(Mt5AccountMapping $mapping, array $records): int
    {
        foreach ($records as $record) {
            Mt5ExternalOrder::updateOrCreate([
                'mt5_account_mapping_id' => $mapping->id,
                'external_id' => (string) ($record['ticket'] ?? ''),
            ], [
                'symbol' => $record['symbol'] ?? 'UNKNOWN',
                'type' => $record['type'] ?? null,
                'state' => $record['state'] ?? 'PENDING',
                'volume' => $record['volume_current'] ?? $record['volume_initial'] ?? 0,
                'price' => $record['price_open'] ?? null,
                'source_updated_at' => $record['time_done'] ?? $record['time_setup'] ?? null,
                'last_seen_at' => now(),
                'payload' => $record,
            ]);
        }

        return count($records);
    }

    private function syncDeals(Mt5AccountMapping $mapping, array $records): int
    {
        foreach ($records as $record) {
            Mt5ExternalDeal::updateOrCreate([
                'mt5_account_mapping_id' => $mapping->id,
                'external_id' => (string) ($record['ticket'] ?? ''),
            ], [
                'external_order_id' => isset($record['order']) ? (string) $record['order'] : null,
                'symbol' => $record['symbol'] ?? 'UNKNOWN',
                'entry' => $record['entry'] ?? null,
                'volume' => $record['volume'] ?? 0,
                'price' => $record['price'] ?? null,
                'profit' => $record['profit'] ?? 0,
                'executed_at' => $record['time'] ?? null,
                'payload' => $record,
            ]);
        }

        return count($records);
    }
}
