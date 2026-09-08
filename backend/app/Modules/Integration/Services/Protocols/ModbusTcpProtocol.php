<?php

namespace App\Modules\Integration\Services\Protocols;

use App\Modules\Integration\Contracts\IoTProtocolInterface;
use Illuminate\Support\Facades\Log;

class ModbusTcpProtocol implements IoTProtocolInterface
{
    protected string $host;
    protected int $port;
    /** @var resource|null */
    protected $socket = null;
    protected ?string $lastError = null;
    protected int $timeout = 5;
    protected int $unitId = 1;
    protected int $transactionId = 0;

    // Modbus function codes
    const FC_READ_COILS = 0x01;
    const FC_READ_DISCRETE_INPUTS = 0x02;
    const FC_READ_HOLDING_REGISTERS = 0x03;
    const FC_READ_INPUT_REGISTERS = 0x04;
    const FC_WRITE_SINGLE_COIL = 0x05;
    const FC_WRITE_SINGLE_REGISTER = 0x06;
    const FC_WRITE_MULTIPLE_COILS = 0x0F;
    const FC_WRITE_MULTIPLE_REGISTERS = 0x10;

    // Modbus TCP constants
    const MODBUS_TCP_PORT = 502;
    const PROTOCOL_ID = 0x0000;
    const MBAP_HEADER_SIZE = 7;

    public function __construct(string $host, int $port = self::MODBUS_TCP_PORT, int $unitId = 1)
    {
        $this->host = $host;
        $this->port = $port;
        $this->unitId = $unitId;
    }

    public function connect(): bool
    {
        try {
            $this->socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

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

            $result = @socket_connect($this->socket, $this->host, $this->port);

            if ($result === false) {
                $this->lastError = socket_strerror(socket_last_error($this->socket));
                socket_close($this->socket);
                $this->socket = null;
                return false;
            }

            Log::info('[Modbus TCP] Connected', [
                'host' => $this->host,
                'port' => $this->port,
                'unit_id' => $this->unitId
            ]);

            return true;

        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::error('[Modbus TCP] Connection failed', ['error' => $e->getMessage()]);
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
                case 'read_coils':
                    return $this->readCoils(
                        $params['address'] ?? 0,
                        $params['count'] ?? 1
                    );

                case 'read_discrete_inputs':
                    return $this->readDiscreteInputs(
                        $params['address'] ?? 0,
                        $params['count'] ?? 1
                    );

                case 'read_holding_registers':
                    return $this->readHoldingRegisters(
                        $params['address'] ?? 0,
                        $params['count'] ?? 1
                    );

                case 'read_input_registers':
                    return $this->readInputRegisters(
                        $params['address'] ?? 0,
                        $params['count'] ?? 1
                    );

                case 'write_coil':
                    return $this->writeSingleCoil(
                        $params['address'] ?? 0,
                        $params['value'] ?? false
                    );

                case 'write_register':
                    return $this->writeSingleRegister(
                        $params['address'] ?? 0,
                        $params['value'] ?? 0
                    );

                case 'write_registers':
                    return $this->writeMultipleRegisters(
                        $params['address'] ?? 0,
                        $params['values'] ?? []
                    );

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
        $result = $this->readHoldingRegisters($address, $count);

        return $result['success'] ? $result['values'] : [];
    }

    public function writeRegister(int $address, array $values): bool
    {
        if (count($values) === 1) {
            $result = $this->writeSingleRegister($address, $values[0]);
        } else {
            $result = $this->writeMultipleRegisters($address, $values);
        }

        return $result['success'];
    }

    public function subscribe(string $topic, callable $callback): void
    {
        // Modbus doesn't support subscriptions natively
        // Would need to implement polling
        Log::info('[Modbus TCP] Subscription requested (polling required)', ['topic' => $topic]);
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    protected function readCoils(int $address, int $count): array
    {
        return $this->readBits(self::FC_READ_COILS, $address, $count);
    }

    protected function readDiscreteInputs(int $address, int $count): array
    {
        return $this->readBits(self::FC_READ_DISCRETE_INPUTS, $address, $count);
    }

    protected function readHoldingRegisters(int $address, int $count): array
    {
        return $this->readRegisters(self::FC_READ_HOLDING_REGISTERS, $address, $count);
    }

    protected function readInputRegisters(int $address, int $count): array
    {
        return $this->readRegisters(self::FC_READ_INPUT_REGISTERS, $address, $count);
    }

    protected function readBits(int $functionCode, int $address, int $count): array
    {
        $pdu = pack('Cnn', $functionCode, $address, $count);
        $response = $this->sendRequest($pdu);

        if ($response === null) {
            return ['success' => false, 'error' => $this->lastError];
        }

        if (strlen($response) < 2) {
            return ['success' => false, 'error' => 'Invalid response'];
        }

        $byteCount = ord($response[1]);
        $values = [];

        for ($i = 0; $i < $count; $i++) {
            $byteIndex = 2 + intdiv($i, 8);
            $bitIndex = $i % 8;

            if ($byteIndex < strlen($response)) {
                $values[] = (ord($response[$byteIndex]) >> $bitIndex) & 0x01;
            }
        }

        return ['success' => true, 'values' => $values];
    }

    protected function readRegisters(int $functionCode, int $address, int $count): array
    {
        $pdu = pack('Cnn', $functionCode, $address, $count);
        $response = $this->sendRequest($pdu);

        if ($response === null) {
            return ['success' => false, 'error' => $this->lastError];
        }

        if (strlen($response) < 2) {
            return ['success' => false, 'error' => 'Invalid response'];
        }

        $byteCount = ord($response[1]);
        $values = [];

        for ($i = 0; $i < $count; $i++) {
            $offset = 2 + ($i * 2);
            if ($offset + 1 < strlen($response)) {
                $values[] = (ord($response[$offset]) << 8) | ord($response[$offset + 1]);
            }
        }

        return ['success' => true, 'values' => $values];
    }

    protected function writeSingleCoil(int $address, bool $value): array
    {
        $pdu = pack('Cnn', self::FC_WRITE_SINGLE_COIL, $address, $value ? 0xFF00 : 0x0000);
        $response = $this->sendRequest($pdu);

        if ($response === null) {
            return ['success' => false, 'error' => $this->lastError];
        }

        return ['success' => true];
    }

    protected function writeSingleRegister(int $address, int $value): array
    {
        $pdu = pack('Cnn', self::FC_WRITE_SINGLE_REGISTER, $address, $value & 0xFFFF);
        $response = $this->sendRequest($pdu);

        if ($response === null) {
            return ['success' => false, 'error' => $this->lastError];
        }

        return ['success' => true];
    }

    protected function writeMultipleRegisters(int $address, array $values): array
    {
        $count = count($values);
        $byteCount = $count * 2;

        $pdu = pack('CnnC', self::FC_WRITE_MULTIPLE_REGISTERS, $address, $count, $byteCount);

        foreach ($values as $value) {
            $pdu .= pack('n', $value & 0xFFFF);
        }

        $response = $this->sendRequest($pdu);

        if ($response === null) {
            return ['success' => false, 'error' => $this->lastError];
        }

        return ['success' => true];
    }

    protected function sendRequest(string $pdu): ?string
    {
        $this->transactionId = ($this->transactionId + 1) & 0xFFFF;

        // MBAP Header
        $mbap = pack('nnnC',
            $this->transactionId,           // Transaction ID
            self::PROTOCOL_ID,               // Protocol ID (0 = Modbus)
            strlen($pdu) + 1,               // Length (PDU + Unit ID)
            $this->unitId                    // Unit ID
        );

        $request = $mbap . $pdu;

        $sent = @socket_send($this->socket, $request, strlen($request), 0);

        if ($sent === false) {
            $this->lastError = socket_strerror(socket_last_error($this->socket));
            return null;
        }

        // Read MBAP header first
        $header = '';
        $received = @socket_recv($this->socket, $header, self::MBAP_HEADER_SIZE, MSG_WAITALL);

        if ($received === false || $received < self::MBAP_HEADER_SIZE) {
            $this->lastError = 'Failed to receive MBAP header';
            return null;
        }

        // Parse header to get PDU length
        $headerData = unpack('ntransaction/nprotocol/nlength/Cunit', $header);
        $pduLength = $headerData['length'] - 1; // Subtract Unit ID

        if ($pduLength <= 0) {
            $this->lastError = 'Invalid PDU length';
            return null;
        }

        // Read PDU
        $responsePdu = '';
        $received = @socket_recv($this->socket, $responsePdu, $pduLength, MSG_WAITALL);

        if ($received === false || $received < $pduLength) {
            $this->lastError = 'Failed to receive PDU';
            return null;
        }

        // Check for exception response
        if (ord($responsePdu[0]) & 0x80) {
            $exceptionCode = ord($responsePdu[1]);
            $this->lastError = $this->getExceptionMessage($exceptionCode);
            return null;
        }

        return $responsePdu;
    }

    protected function getExceptionMessage(int $code): string
    {
        $messages = [
            0x01 => 'Illegal Function',
            0x02 => 'Illegal Data Address',
            0x03 => 'Illegal Data Value',
            0x04 => 'Slave Device Failure',
            0x05 => 'Acknowledge',
            0x06 => 'Slave Device Busy',
            0x08 => 'Memory Parity Error',
            0x0A => 'Gateway Path Unavailable',
            0x0B => 'Gateway Target Device Failed to Respond',
        ];

        return $messages[$code] ?? "Unknown Exception ($code)";
    }
}
