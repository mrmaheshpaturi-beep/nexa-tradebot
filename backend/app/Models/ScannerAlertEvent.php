<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScannerAlertEvent extends BaseModel
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'delivered' => 'boolean',
            'delivered_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(SignalCandidate::class, 'signal_candidate_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ScannerRun::class, 'scanner_run_id');
    }
}
