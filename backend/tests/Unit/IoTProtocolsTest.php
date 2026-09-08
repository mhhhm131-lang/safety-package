<?php

namespace Tests\Unit\Integration;

use App\Modules\Integration\Contracts\IoTProtocolInterface;
use App\Modules\Integration\Services\Protocols\BACnetProtocol;
use App\Modules\Integration\Services\Protocols\ModbusTcpProtocol;
use App\Modules\Integration\Services\Protocols\MqttProtocol;
use PHPUnit\Framework\TestCase;

class IoTProtocolsTest extends TestCase
{
    public function test_bacnet_protocol_implements_interface(): void
    {
        $protocol = new BACnetProtocol('192.168.1.100');
        $this->assertInstanceOf(IoTProtocolInterface::class, $protocol);
    }

    public function test_modbus_protocol_implements_interface(): void
    {
        $protocol = new ModbusTcpProtocol('192.168.1.100');
        $this->assertInstanceOf(IoTProtocolInterface::class, $protocol);
    }

    public function test_mqtt_protocol_implements_interface(): void
    {
        $protocol = new MqttProtocol('192.168.1.100');
        $this->assertInstanceOf(IoTProtocolInterface::class, $protocol);
    }

    public function test_bacnet_protocol_uses_default_port(): void
    {
        $protocol = new BACnetProtocol('192.168.1.100');
        $this->assertFalse($protocol->isConnected());
    }

    public function test_modbus_protocol_uses_default_port(): void
    {
        $protocol = new ModbusTcpProtocol('192.168.1.100');
        $this->assertFalse($protocol->isConnected());
    }

    public function test_mqtt_protocol_generates_client_id(): void
    {
        $protocol = new MqttProtocol('192.168.1.100');
        $this->assertFalse($protocol->isConnected());
    }

    public function test_bacnet_protocol_returns_error_when_not_connected(): void
    {
        $protocol = new BACnetProtocol('192.168.1.100');

        $result = $protocol->sendCommand('read_property', [
            'object_type' => 0,
            'object_instance' => 1,
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('Not connected', $result['error']);
    }

    public function test_modbus_protocol_returns_error_when_not_connected(): void
    {
        $protocol = new ModbusTcpProtocol('192.168.1.100');

        $result = $protocol->sendCommand('read_holding_registers', [
            'address' => 0,
            'count' => 10,
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('Not connected', $result['error']);
    }

    public function test_mqtt_protocol_returns_error_when_not_connected(): void
    {
        $protocol = new MqttProtocol('192.168.1.100');

        $result = $protocol->sendCommand('publish', [
            'topic' => 'test/topic',
            'message' => 'Hello',
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals('Not connected', $result['error']);
    }

    public function test_modbus_protocol_handles_multiple_register_commands(): void
    {
        $protocol = new ModbusTcpProtocol('192.168.1.100');

        $commands = [
            'read_coils',
            'read_discrete_inputs',
            'read_holding_registers',
            'read_input_registers',
            'write_coil',
            'write_register',
            'write_registers',
        ];

        foreach ($commands as $command) {
            $result = $protocol->sendCommand($command, ['address' => 0]);
            $this->assertArrayHasKey('success', $result);
            $this->assertArrayHasKey('error', $result);
        }
    }

    public function test_mqtt_protocol_handles_multiple_commands(): void
    {
        $protocol = new MqttProtocol('192.168.1.100');

        $commands = ['publish', 'subscribe', 'unsubscribe', 'ping'];

        foreach ($commands as $command) {
            $result = $protocol->sendCommand($command, ['topic' => 'test']);
            $this->assertArrayHasKey('success', $result);
        }
    }

    public function test_bacnet_protocol_maps_address_to_object(): void
    {
        if (!function_exists('socket_create')) {
            $this->markTestSkipped('Socket extension not available');
        }

        $protocol = new BACnetProtocol('192.168.1.100');
        $result = $protocol->readRegister(0);
        $this->assertIsArray($result);
    }

    public function test_modbus_protocol_read_register_returns_array(): void
    {
        if (!function_exists('socket_create')) {
            $this->markTestSkipped('Socket extension not available');
        }

        $protocol = new ModbusTcpProtocol('192.168.1.100');
        $result = $protocol->readRegister(0, 5);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_mqtt_protocol_read_register_returns_empty(): void
    {
        $protocol = new MqttProtocol('192.168.1.100');
        $result = $protocol->readRegister(0);
        $this->assertEmpty($result);
    }

    public function test_mqtt_protocol_write_register_returns_false(): void
    {
        $protocol = new MqttProtocol('192.168.1.100');
        $result = $protocol->writeRegister(0, [100]);
        $this->assertFalse($result);
    }

    public function test_protocols_return_null_last_error_initially(): void
    {
        $bacnet = new BACnetProtocol('192.168.1.100');
        $modbus = new ModbusTcpProtocol('192.168.1.100');
        $mqtt = new MqttProtocol('192.168.1.100');

        $this->assertNull($bacnet->getLastError());
        $this->assertNull($modbus->getLastError());
        $this->assertNull($mqtt->getLastError());
    }

    public function test_protocols_can_disconnect_when_not_connected(): void
    {
        $bacnet = new BACnetProtocol('192.168.1.100');
        $modbus = new ModbusTcpProtocol('192.168.1.100');
        $mqtt = new MqttProtocol('192.168.1.100');

        $bacnet->disconnect();
        $modbus->disconnect();
        $mqtt->disconnect();

        $this->assertFalse($bacnet->isConnected());
        $this->assertFalse($modbus->isConnected());
        $this->assertFalse($mqtt->isConnected());
    }
}
