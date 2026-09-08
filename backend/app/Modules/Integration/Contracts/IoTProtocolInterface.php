<?php

namespace App\Modules\Integration\Contracts;

interface IoTProtocolInterface
{
    public function connect(): bool;
    public function disconnect(): void;
    public function isConnected(): bool;
    public function sendCommand(string $command, array $params = []): array;
    public function readRegister(int $address, int $count = 1): array;
    public function writeRegister(int $address, array $values): bool;
    public function subscribe(string $topic, callable $callback): void;
    public function getLastError(): ?string;
}
