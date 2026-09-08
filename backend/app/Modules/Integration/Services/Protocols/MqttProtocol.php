<?php

namespace App\Modules\Integration\Services\Protocols;

use App\Modules\Integration\Contracts\IoTProtocolInterface;
use Illuminate\Support\Facades\Log;

class MqttProtocol implements IoTProtocolInterface
{
    protected string $host;
    protected int $port;
    /** @var resource|null */
    protected $socket = null;
    protected ?string $lastError = null;
    protected int $timeout = 5;
    protected string $clientId;
    protected ?string $username = null;
    protected ?string $password = null;
    protected int $keepAlive = 60;
    protected array $subscriptions = [];
    protected int $messageId = 0;

    // MQTT Control Packet Types
    const CONNECT = 0x10;
    const CONNACK = 0x20;
    const PUBLISH = 0x30;
    const PUBACK = 0x40;
    const SUBSCRIBE = 0x80;
    const SUBACK = 0x90;
    const UNSUBSCRIBE = 0xA0;
    const UNSUBACK = 0xB0;
    const PINGREQ = 0xC0;
    const PINGRESP = 0xD0;
    const DISCONNECT = 0xE0;

    // Connect Return Codes
    const CONNACK_ACCEPTED = 0x00;
    const CONNACK_REFUSED_PROTOCOL = 0x01;
    const CONNACK_REFUSED_IDENTIFIER = 0x02;
    const CONNACK_REFUSED_SERVER = 0x03;
    const CONNACK_REFUSED_CREDENTIALS = 0x04;
    const CONNACK_REFUSED_AUTH = 0x05;

    public function __construct(
        string $host,
        int $port = 1883,
        ?string $clientId = null,
        ?string $username = null,
        ?string $password = null
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->clientId = $clientId ?? 'ohsms-' . uniqid();
        $this->username = $username;
        $this->password = $password;
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

            // Send CONNECT packet
            $connectPacket = $this->buildConnectPacket();
            $sent = @socket_send($this->socket, $connectPacket, strlen($connectPacket), 0);

            if ($sent === false) {
                $this->lastError = 'Failed to send CONNECT packet';
                return false;
            }

            // Wait for CONNACK
            $response = '';
            $received = @socket_recv($this->socket, $response, 4, MSG_WAITALL);

            if ($received < 4) {
                $this->lastError = 'No CONNACK received';
                return false;
            }

            if (ord($response[0]) !== self::CONNACK) {
                $this->lastError = 'Invalid CONNACK response';
                return false;
            }

            $returnCode = ord($response[3]);
            if ($returnCode !== self::CONNACK_ACCEPTED) {
                $this->lastError = $this->getConnackError($returnCode);
                return false;
            }

            Log::info('[MQTT] Connected', [
                'host' => $this->host,
                'port' => $this->port,
                'client_id' => $this->clientId
            ]);

            return true;

        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            Log::error('[MQTT] Connection failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            // Send DISCONNECT packet
            $packet = chr(self::DISCONNECT) . chr(0);
            @socket_send($this->socket, $packet, 2, 0);

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
                case 'publish':
                    return $this->publish(
                        $params['topic'] ?? '',
                        $params['message'] ?? '',
                        $params['qos'] ?? 0,
                        $params['retain'] ?? false
                    );

                case 'subscribe':
                    return $this->subscribeToTopic(
                        $params['topic'] ?? '',
                        $params['qos'] ?? 0
                    );

                case 'unsubscribe':
                    return $this->unsubscribeFromTopic($params['topic'] ?? '');

                case 'ping':
                    return $this->ping();

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
        // MQTT doesn't use registers - return empty
        return [];
    }

    public function writeRegister(int $address, array $values): bool
    {
        // MQTT doesn't use registers - use publish instead
        return false;
    }

    public function subscribe(string $topic, callable $callback): void
    {
        $result = $this->subscribeToTopic($topic, 1);

        if ($result['success']) {
            $this->subscriptions[$topic] = $callback;
        }
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function publish(string $topic, string $message, int $qos = 0, bool $retain = false): array
    {
        $packet = $this->buildPublishPacket($topic, $message, $qos, $retain);
        $sent = @socket_send($this->socket, $packet, strlen($packet), 0);

        if ($sent === false) {
            return ['success' => false, 'error' => 'Failed to send'];
        }

        // Wait for PUBACK if QoS > 0
        if ($qos > 0) {
            $response = '';
            $received = @socket_recv($this->socket, $response, 4, MSG_WAITALL);

            if ($received < 4 || ord($response[0]) !== self::PUBACK) {
                return ['success' => false, 'error' => 'No PUBACK received'];
            }
        }

        return ['success' => true];
    }

    public function subscribeToTopic(string $topic, int $qos = 0): array
    {
        $this->messageId = ($this->messageId + 1) & 0xFFFF;

        $packet = $this->buildSubscribePacket($topic, $qos);
        $sent = @socket_send($this->socket, $packet, strlen($packet), 0);

        if ($sent === false) {
            return ['success' => false, 'error' => 'Failed to send'];
        }

        // Wait for SUBACK
        $response = '';
        $received = @socket_recv($this->socket, $response, 5, MSG_WAITALL);

        if ($received < 5 || ord($response[0]) !== self::SUBACK) {
            return ['success' => false, 'error' => 'No SUBACK received'];
        }

        $grantedQos = ord($response[4]);
        if ($grantedQos === 0x80) {
            return ['success' => false, 'error' => 'Subscription rejected'];
        }

        return ['success' => true, 'granted_qos' => $grantedQos];
    }

    public function unsubscribeFromTopic(string $topic): array
    {
        $this->messageId = ($this->messageId + 1) & 0xFFFF;

        $packet = $this->buildUnsubscribePacket($topic);
        $sent = @socket_send($this->socket, $packet, strlen($packet), 0);

        if ($sent === false) {
            return ['success' => false, 'error' => 'Failed to send'];
        }

        // Wait for UNSUBACK
        $response = '';
        $received = @socket_recv($this->socket, $response, 4, MSG_WAITALL);

        if ($received < 4 || ord($response[0]) !== self::UNSUBACK) {
            return ['success' => false, 'error' => 'No UNSUBACK received'];
        }

        unset($this->subscriptions[$topic]);

        return ['success' => true];
    }

    public function ping(): array
    {
        $packet = chr(self::PINGREQ) . chr(0);
        $sent = @socket_send($this->socket, $packet, 2, 0);

        if ($sent === false) {
            return ['success' => false, 'error' => 'Failed to send ping'];
        }

        $response = '';
        $received = @socket_recv($this->socket, $response, 2, MSG_WAITALL);

        if ($received < 2 || ord($response[0]) !== self::PINGRESP) {
            return ['success' => false, 'error' => 'No PINGRESP'];
        }

        return ['success' => true];
    }

    public function loop(int $timeout = 100): ?array
    {
        if (!$this->isConnected()) {
            return null;
        }

        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, [
            'sec' => 0,
            'usec' => $timeout * 1000
        ]);

        $response = '';
        $received = @socket_recv($this->socket, $response, 2, 0);

        if ($received <= 0) {
            return null;
        }

        $packetType = ord($response[0]) & 0xF0;

        if ($packetType === self::PUBLISH) {
            return $this->parsePublishPacket($response);
        }

        return null;
    }

    protected function buildConnectPacket(): string
    {
        // Variable header
        $variableHeader = '';
        $variableHeader .= pack('n', 4) . 'MQTT';  // Protocol name
        $variableHeader .= chr(4);                   // Protocol level (4 = MQTT 3.1.1)

        // Connect flags
        $flags = 0x02; // Clean session
        if ($this->username) {
            $flags |= 0x80; // Username flag
        }
        if ($this->password) {
            $flags |= 0x40; // Password flag
        }
        $variableHeader .= chr($flags);

        // Keep alive
        $variableHeader .= pack('n', $this->keepAlive);

        // Payload
        $payload = '';
        $payload .= pack('n', strlen($this->clientId)) . $this->clientId;

        if ($this->username) {
            $payload .= pack('n', strlen($this->username)) . $this->username;
        }
        if ($this->password) {
            $payload .= pack('n', strlen($this->password)) . $this->password;
        }

        $remainingLength = strlen($variableHeader) + strlen($payload);

        return chr(self::CONNECT) . $this->encodeLength($remainingLength) . $variableHeader . $payload;
    }

    protected function buildPublishPacket(string $topic, string $message, int $qos, bool $retain): string
    {
        $header = self::PUBLISH;

        if ($retain) {
            $header |= 0x01;
        }
        if ($qos > 0) {
            $header |= ($qos << 1);
        }

        $variableHeader = pack('n', strlen($topic)) . $topic;

        if ($qos > 0) {
            $this->messageId = ($this->messageId + 1) & 0xFFFF;
            $variableHeader .= pack('n', $this->messageId);
        }

        $remainingLength = strlen($variableHeader) + strlen($message);

        return chr($header) . $this->encodeLength($remainingLength) . $variableHeader . $message;
    }

    protected function buildSubscribePacket(string $topic, int $qos): string
    {
        $variableHeader = pack('n', $this->messageId);

        $payload = pack('n', strlen($topic)) . $topic . chr($qos);

        $remainingLength = strlen($variableHeader) + strlen($payload);

        return chr(self::SUBSCRIBE | 0x02) . $this->encodeLength($remainingLength) . $variableHeader . $payload;
    }

    protected function buildUnsubscribePacket(string $topic): string
    {
        $variableHeader = pack('n', $this->messageId);

        $payload = pack('n', strlen($topic)) . $topic;

        $remainingLength = strlen($variableHeader) + strlen($payload);

        return chr(self::UNSUBSCRIBE | 0x02) . $this->encodeLength($remainingLength) . $variableHeader . $payload;
    }

    protected function encodeLength(int $length): string
    {
        $encoded = '';

        do {
            $digit = $length % 128;
            $length = intdiv($length, 128);

            if ($length > 0) {
                $digit |= 0x80;
            }

            $encoded .= chr($digit);
        } while ($length > 0);

        return $encoded;
    }

    protected function parsePublishPacket(string $data): ?array
    {
        $qos = (ord($data[0]) >> 1) & 0x03;

        // Read remaining length
        $pos = 1;
        $multiplier = 1;
        $remainingLength = 0;

        do {
            $digit = ord($data[$pos++]);
            $remainingLength += ($digit & 0x7F) * $multiplier;
            $multiplier *= 128;
        } while ($digit & 0x80);

        // Read full packet
        $fullPacket = $data;
        $needed = $pos + $remainingLength - strlen($data);

        if ($needed > 0) {
            $more = '';
            @socket_recv($this->socket, $more, $needed, MSG_WAITALL);
            $fullPacket .= $more;
        }

        // Parse topic
        $topicLength = (ord($fullPacket[$pos]) << 8) | ord($fullPacket[$pos + 1]);
        $pos += 2;
        $topic = substr($fullPacket, $pos, $topicLength);
        $pos += $topicLength;

        // Message ID for QoS > 0
        if ($qos > 0) {
            $messageId = (ord($fullPacket[$pos]) << 8) | ord($fullPacket[$pos + 1]);
            $pos += 2;

            // Send PUBACK
            $puback = chr(self::PUBACK) . chr(2) . pack('n', $messageId);
            @socket_send($this->socket, $puback, 4, 0);
        }

        // Message
        $message = substr($fullPacket, $pos);

        // Trigger callback if subscribed
        if (isset($this->subscriptions[$topic])) {
            call_user_func($this->subscriptions[$topic], $topic, $message);
        }

        return [
            'topic' => $topic,
            'message' => $message,
            'qos' => $qos
        ];
    }

    protected function getConnackError(int $code): string
    {
        $errors = [
            self::CONNACK_REFUSED_PROTOCOL => 'Unacceptable protocol version',
            self::CONNACK_REFUSED_IDENTIFIER => 'Identifier rejected',
            self::CONNACK_REFUSED_SERVER => 'Server unavailable',
            self::CONNACK_REFUSED_CREDENTIALS => 'Bad username or password',
            self::CONNACK_REFUSED_AUTH => 'Not authorized',
        ];

        return $errors[$code] ?? "Unknown error ($code)";
    }
}
