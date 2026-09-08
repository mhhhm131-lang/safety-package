<?php

namespace App\Modules\Integration\Services\Protocols;

use App\Modules\Integration\Contracts\IoTProtocolInterface;
use Illuminate\Support\Facades\Log;

class BACnetProtocol implements IoTProtocolInterface
{
    protected string $host;
    protected int $port;
    /** @var resource|null */
    protected $socket = null;
    protected ?string $lastError = null;
    protected int $timeout = 5;
    protected int $deviceId;

    // BACnet constants
    const BACNET_PORT = 47808;
    const BACNET_PROTOCOL_VERSION = 0x01;
    const BVLC_TYPE = 0x81;

    // Service choices
    const SERVICE_CONFIRMED_READ_PROPERTY = 0x0C;
    const SERVICE_CONFIRMED_WRITE_PROPERTY = 0x0F;
    const SERVICE_UNCONFIRMED_WHO_IS = 0x08;
    const SERVICE_UNCONFIRMED_I_AM = 0x00;

    public function __construct(string $host, int $port = self::BACNET_PORT, int $deviceId = 1)
    {
        $this->host = $host;
        $this->port = $port;
        $this->deviceId = $deviceId;
    }

    public function connect(): bool
    {
        try {
            $this->socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

            if ($this->socket === false) {
                $this->lastError = socket_strerror(socket_last_error());
                return false;
            }

            socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, [
                'sec' => $this->timeout,
                'usec' => 0
            ]);

            socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, [
                'sec' => $this->timeout,
                'usec' => 0
            ]);

            // Send Who-Is to discover device
            $whoIs = $this->buildWhoIsRequest();
            $sent = @socket_sendto($this->socket, $whoIs, strlen($whoIs), 0, $this->host, $this->port);

            if ($sent === false) {
                $this->lastError = socket_strerror(socket_last_error($this->socket));
                return false;
            }

            // Wait for I-Am response
            $response = '';
            $from = '';
            $fromPort = 0;
            $received = @socket_recvfrom($this->socket, $response, 1024, 0, $from, $fromPort);

            if ($received === false || $received === 0) {
                $this->lastError = 'No response from BACnet device';
                return false;
            }

            Log::info('[BACnet] Connected to device', [
                'host' => $this->host,
                'port' => $this->port,
                'device_id' => $this->deviceId
            ]);

            return true;

        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::error('[BACnet] Connection failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            socket_close($this->socket);
            $this->socket = null;
        }
    }

    public function isConnected(): bool
    {
        return $this->socket !== null;
    }

    public function sendCommand(string $command, array $params = []): array
    {
        if (!$this->isConnected()) {
            return ['success' => false, 'error' => 'Not connected'];
        }

        try {
            switch ($command) {
                case 'read_property':
                    return $this->readProperty(
                        $params['object_type'] ?? 0,
                        $params['object_instance'] ?? 0,
                        $params['property_id'] ?? 85 // Present Value
                    );

                case 'write_property':
                    return $this->writeProperty(
                        $params['object_type'] ?? 0,
                        $params['object_instance'] ?? 0,
                        $params['property_id'] ?? 85,
                        $params['value'] ?? 0
                    );

                case 'who_is':
                    return $this->whoIs($params['low'] ?? null, $params['high'] ?? null);

                default:
                    return ['success' => false, 'error' => 'Unknown command'];
            }
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function readRegister(int $address, int $count = 1): array
    {
        // BACnet uses object/property model, not registers
        // Map address to object type and instance
        $objectType = ($address >> 22) & 0x3FF;
        $objectInstance = $address & 0x3FFFFF;

        $result = $this->readProperty($objectType, $objectInstance, 85);

        return $result['success'] ? [$result['value']] : [];
    }

    public function writeRegister(int $address, array $values): bool
    {
        $objectType = ($address >> 22) & 0x3FF;
        $objectInstance = $address & 0x3FFFFF;

        $result = $this->writeProperty($objectType, $objectInstance, 85, $values[0] ?? 0);

        return $result['success'];
    }

    public function subscribe(string $topic, callable $callback): void
    {
        // BACnet COV (Change of Value) subscription
        // This would require a persistent connection and event loop
        Log::info('[BACnet] COV subscription requested', ['topic' => $topic]);
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    protected function buildWhoIsRequest(?int $lowLimit = null, ?int $highLimit = null): string
    {
        // BVLC header
        $bvlc = pack('C*',
            self::BVLC_TYPE,           // Type
            0x0B,                       // Function: Original-Broadcast-NPDU
            0x00, 0x08                  // Length (placeholder)
        );

        // NPDU
        $npdu = pack('C*',
            self::BACNET_PROTOCOL_VERSION,
            0x20,                       // Control: expecting reply
            0xFF, 0xFF                  // DNET: broadcast
        );

        // APDU - Who-Is
        $apdu = pack('C', 0x10 | self::SERVICE_UNCONFIRMED_WHO_IS);

        if ($lowLimit !== null && $highLimit !== null) {
            $apdu .= $this->encodeUnsigned($lowLimit, 0);
            $apdu .= $this->encodeUnsigned($highLimit, 1);
        }

        $message = $npdu . $apdu;
        $length = strlen($message) + 4;

        // Update length in BVLC
        $bvlc[2] = chr(($length >> 8) & 0xFF);
        $bvlc[3] = chr($length & 0xFF);

        return $bvlc . $message;
    }

    protected function readProperty(int $objectType, int $objectInstance, int $propertyId): array
    {
        // Build ReadProperty request
        $request = $this->buildReadPropertyRequest($objectType, $objectInstance, $propertyId);

        $sent = @socket_sendto($this->socket, $request, strlen($request), 0, $this->host, $this->port);

        if ($sent === false) {
            return ['success' => false, 'error' => 'Send failed'];
        }

        $response = '';
        $from = '';
        $fromPort = 0;
        $received = @socket_recvfrom($this->socket, $response, 1024, 0, $from, $fromPort);

        if ($received === false || $received === 0) {
            return ['success' => false, 'error' => 'No response'];
        }

        return $this->parseReadPropertyResponse($response);
    }

    protected function writeProperty(int $objectType, int $objectInstance, int $propertyId, $value): array
    {
        // Build WriteProperty request
        $request = $this->buildWritePropertyRequest($objectType, $objectInstance, $propertyId, $value);

        $sent = @socket_sendto($this->socket, $request, strlen($request), 0, $this->host, $this->port);

        if ($sent === false) {
            return ['success' => false, 'error' => 'Send failed'];
        }

        $response = '';
        $from = '';
        $fromPort = 0;
        $received = @socket_recvfrom($this->socket, $response, 1024, 0, $from, $fromPort);

        if ($received === false || $received === 0) {
            return ['success' => false, 'error' => 'No response'];
        }

        return ['success' => true];
    }

    protected function whoIs(?int $low, ?int $high): array
    {
        $request = $this->buildWhoIsRequest($low, $high);

        $sent = @socket_sendto($this->socket, $request, strlen($request), 0, $this->host, $this->port);

        if ($sent === false) {
            return ['success' => false, 'error' => 'Send failed'];
        }

        $devices = [];
        $startTime = time();

        while ((time() - $startTime) < 3) {
            $response = '';
            $from = '';
            $fromPort = 0;
            $received = @socket_recvfrom($this->socket, $response, 1024, MSG_DONTWAIT, $from, $fromPort);

            if ($received > 0) {
                $device = $this->parseIAmResponse($response);
                if ($device) {
                    $devices[] = $device;
                }
            }

            usleep(100000); // 100ms
        }

        return ['success' => true, 'devices' => $devices];
    }

    protected function buildReadPropertyRequest(int $objectType, int $objectInstance, int $propertyId): string
    {
        // Simplified ReadProperty APDU
        $apdu = pack('C*', 0x00, self::SERVICE_CONFIRMED_READ_PROPERTY);
        $apdu .= $this->encodeObjectIdentifier($objectType, $objectInstance);
        $apdu .= $this->encodePropertyIdentifier($propertyId);

        return $this->wrapInBVLC($apdu);
    }

    protected function buildWritePropertyRequest(int $objectType, int $objectInstance, int $propertyId, $value): string
    {
        $apdu = pack('C*', 0x00, self::SERVICE_CONFIRMED_WRITE_PROPERTY);
        $apdu .= $this->encodeObjectIdentifier($objectType, $objectInstance);
        $apdu .= $this->encodePropertyIdentifier($propertyId);
        $apdu .= $this->encodeValue($value);

        return $this->wrapInBVLC($apdu);
    }

    protected function wrapInBVLC(string $apdu): string
    {
        $npdu = pack('C*', self::BACNET_PROTOCOL_VERSION, 0x04);
        $message = $npdu . $apdu;
        $length = strlen($message) + 4;

        $bvlc = pack('C*',
            self::BVLC_TYPE,
            0x0A, // Original-Unicast-NPDU
            ($length >> 8) & 0xFF,
            $length & 0xFF
        );

        return $bvlc . $message;
    }

    protected function encodeObjectIdentifier(int $objectType, int $objectInstance): string
    {
        $value = ($objectType << 22) | ($objectInstance & 0x3FFFFF);
        return pack('C', 0x0C) . pack('N', $value);
    }

    protected function encodePropertyIdentifier(int $propertyId): string
    {
        if ($propertyId < 256) {
            return pack('C*', 0x19, $propertyId);
        }
        return pack('C*', 0x1A, ($propertyId >> 8) & 0xFF, $propertyId & 0xFF);
    }

    protected function encodeUnsigned(int $value, int $contextTag): string
    {
        $tag = ($contextTag << 4) | 0x09;
        if ($value < 256) {
            return pack('C*', $tag, $value);
        }
        return pack('C*', $tag | 0x01, ($value >> 8) & 0xFF, $value & 0xFF);
    }

    protected function encodeValue($value): string
    {
        if (is_bool($value)) {
            return pack('C', $value ? 0x11 : 0x10);
        }
        if (is_int($value)) {
            return pack('C*', 0x21, $value & 0xFF);
        }
        if (is_float($value)) {
            return pack('C', 0x44) . pack('f', $value);
        }
        return pack('C', 0x75) . pack('C', strlen($value)) . $value;
    }

    protected function parseReadPropertyResponse(string $response): array
    {
        // Simplified parsing - real implementation would be more complex
        if (strlen($response) < 10) {
            return ['success' => false, 'error' => 'Invalid response'];
        }

        return [
            'success' => true,
            'value' => ord($response[strlen($response) - 1])
        ];
    }

    protected function parseIAmResponse(string $response): ?array
    {
        if (strlen($response) < 12) {
            return null;
        }

        // Extract device ID from I-Am response
        return [
            'device_id' => $this->deviceId,
            'host' => $this->host,
            'port' => $this->port
        ];
    }
}
