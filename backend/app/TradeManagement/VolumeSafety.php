<?php

namespace App\TradeManagement;

use Illuminate\Validation\ValidationException;

final class VolumeSafety
{
    /**
     * Round close volume to broker step without leaving an illegal residual.
     *
     * @param  array<string,mixed>  $spec
     */
    public static function normalizeCloseVolume(float $requested, float $current, array $spec): float
    {
        $min = (float) ($spec['volume_min'] ?? 0.01);
        $step = (float) ($spec['volume_step'] ?? 0.01);
        if ($step <= 0) {
            $step = 0.01;
        }
        $vol = floor($requested / $step) * $step;
        $vol = round($vol, 4);
        if ($vol < $min && $requested >= $current) {
            $vol = $current;
        }
        if ($vol <= 0) {
            throw ValidationException::withMessages(['volume' => 'Close volume rounds to zero.']);
        }
        if ($vol > $current) {
            $vol = $current;
        }
        $residual = round($current - $vol, 4);
        if ($residual > 0 && $residual < $min) {
            // Closing almost all would leave illegal residual — close full when remaining would be untradeable.
            if ($vol >= $current - $min || $requested >= $current - $min) {
                return $current;
            }
            throw ValidationException::withMessages([
                'volume' => 'Partial close would leave residual below volume_min.',
            ]);
        }

        return $vol;
    }
}
