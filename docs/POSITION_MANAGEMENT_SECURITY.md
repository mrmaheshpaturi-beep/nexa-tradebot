# Position Management Security

Defense in depth: PositionManagementGate → DemoAccountVerifier → request.account.trade_mode=DEMO → bridge independent DEMO check → authorized_order_send.

No generic arbitrary order_send API. Management methods: modify_position_protection, close_position, partial_close, cancel_pending_order.

ExecutionLock: BLOCK_NEW_ENTRIES ≠ BLOCK_ALL_BROKER_ACTIONS. RiskLock blocks new entries, not protective closes by default.
