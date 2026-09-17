<?php

namespace App\Contracts;

interface SimulationRepository
{
    /** @return array<string, mixed> */
    public function systemStatus(): array;

    /**
     * @param  array{symbol:string,direction:string,volume:float}  $order
     * @return array<string, mixed>
     */
    public function createOrder(array $order): array;
}
