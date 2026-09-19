<?php

namespace App\Enums;

/** Distinguishes entry blocking from full broker-action blocking (Phase 11 §54). */
enum BrokerActionLockScope: string
{
    case BlockNewEntries = 'BLOCK_NEW_ENTRIES';
    case BlockAllBrokerActions = 'BLOCK_ALL_BROKER_ACTIONS';
}
