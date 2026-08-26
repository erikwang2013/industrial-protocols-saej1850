<?php

/*
 * Copyright (c) 2026 erik <erik@erik.xyz> — https://erik.xyz
 */

namespace Erikwang2013\IndustrialProtocols\SaeJ1850\Tests\Unit;

use Erikwang2013\IndustrialProtocols\Bridge\BridgeConnector;
use Erikwang2013\IndustrialProtocols\Bridge\ExternalProcessBridge;
use Erikwang2013\IndustrialProtocols\Bridge\TcpGatewayBridge;
use Erikwang2013\IndustrialProtocols\Connection\ConnectionState;
use Erikwang2013\IndustrialProtocols\SaeJ1850\SaeJ1850Protocol;
use PHPUnit\Framework\TestCase;

class SaeJ1850Test extends TestCase
{
    private const GATEWAY_PORT = 15210;
    private const TIMEOUT_PORT = 15211;

    public function testMetadata(): void
    {
        $p = new SaeJ1850Protocol();
        $this->assertSame('sae-j1850', $p->getName());
        $this->assertSame('1.1.1', $p->getVersion());
        $this->assertSame(0, $p->getDefaultPort());
        $this->assertSame(['pwm', 'vpw'], $p->getSupportedVariants());
    }

    public function testRequiresBridge(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires a BridgeInterface');
        (new SaeJ1850Protocol())->createConnector([]);
    }

    public function testRejectsInvalidBridge(): void
    {
        $this->expectException(\TypeError::class);
        (new SaeJ1850Protocol())->createConnector(['bridge' => 'not-a-bridge']);
    }

    public function testWithBridgeNotConnected(): void
    {
        $connector = (new SaeJ1850Protocol())->createConnector(['bridge' => new TcpGatewayBridge('127.0.0.1', 9999)]);
        $this->assertInstanceOf(BridgeConnector::class, $connector);
        $this->assertFalse($connector->isConnected());
        $this->assertSame(ConnectionState::CLOSED, $connector->getHealth()->state);
    }

    public function testReadWriteCommandOverTcpGateway(): void
    {
        // Fake gateway server: speaks the TcpGatewayBridge framing
        // (pack('v', cmdLen) . cmd . pack('V', payloadLen) . payload) and
        // answers every request with OK:<command>:<payload>.
        $proc = proc_open([PHP_BINARY, '-r', <<<'STUB'
            $server = stream_socket_server('tcp://127.0.0.1:15210');
            echo "READY\n";
            flush();
            $readn = function ($c, $n) {
                $buf = '';
                while (strlen($buf) < $n && !feof($c)) {
                    $chunk = fread($c, $n - strlen($buf));
                    if ($chunk === false || $chunk === '') { break; }
                    $buf .= $chunk;
                }
                return $buf;
            };
            $client = @stream_socket_accept($server, 5);
            if ($client) {
                while (!feof($client)) {
                    $head = $readn($client, 2);
                    if (strlen($head) < 2) { break; }
                    $cmd = $readn($client, unpack('v', $head)[1]);
                    $payload = $readn($client, unpack('V', $readn($client, 4))[1]);
                    fwrite($client, 'OK:' . $cmd . ':' . $payload);
                }
                fclose($client);
            }
            fclose($server);
STUB, ], [1 => ['pipe', 'w']], $pipes);

        fgets($pipes[1]);

        $connector = (new SaeJ1850Protocol())->createConnector([
            'bridge' => new TcpGatewayBridge('127.0.0.1', self::GATEWAY_PORT, 2.0),
        ]);
        $connector->connect();
        $this->assertTrue($connector->isConnected());
        $this->assertSame(ConnectionState::HEALTHY, $connector->getHealth()->state);

        $single = $connector->read('a1');
        $this->assertSame('OK:read:{"address":"a1"}', $single['a1']);

        $multi = $connector->read(['a1', 'a2']);
        $this->assertCount(2, $multi);
        $this->assertSame('OK:read:{"address":"a2"}', $multi['a2']);

        $write = $connector->write('a1', [42]);
        $this->assertSame('OK:write:{"address":"a1","value":42}', $write['a1']);

        $multiWrite = $connector->write(['a1', 'a2'], ['a1' => 1, 'a2' => 2]);
        $this->assertCount(2, $multiWrite);
        $this->assertSame('OK:write:{"address":"a2","value":2}', $multiWrite['a2']);

        $this->assertSame('OK:ping:x', $connector->command('ping', 'x'));
        $this->assertSame('tcp-gateway', $connector->getBridge()->getType());

        $connector->disconnect();
        $this->assertFalse($connector->isConnected());
        $this->assertSame(ConnectionState::CLOSED, $connector->getHealth()->state);

        proc_close($proc);
    }

    public function testConnectRefused(): void
    {
        // Bind an ephemeral port, close it, then connect -> ECONNREFUSED.
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($server, false);
        fclose($server);
        $port = (int) substr($name, strrpos($name, ':') + 1);

        $connector = (new SaeJ1850Protocol())->createConnector([
            'bridge' => new TcpGatewayBridge('127.0.0.1', $port, 1.0),
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gateway bridge connect failed');
        $connector->connect();
    }

    public function testReadWithoutConnect(): void
    {
        $connector = (new SaeJ1850Protocol())->createConnector([
            'bridge' => new TcpGatewayBridge('127.0.0.1', self::GATEWAY_PORT),
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gateway bridge not connected');
        $connector->read('a1');
    }

    public function testReadTimeout(): void
    {
        // Server accepts the connection but never answers.
        $proc = proc_open([PHP_BINARY, '-r', <<<'STUB'
            $server = stream_socket_server('tcp://127.0.0.1:15211');
            echo "READY\n";
            flush();
            $client = @stream_socket_accept($server, 5);
            if ($client) { sleep(5); fclose($client); }
            fclose($server);
STUB, ], [1 => ['pipe', 'w']], $pipes);

        fgets($pipes[1]);

        try {
            $connector = (new SaeJ1850Protocol())->createConnector([
                'bridge' => new TcpGatewayBridge('127.0.0.1', self::TIMEOUT_PORT, 1.0),
            ]);
            $connector->connect();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Gateway bridge read timeout');
            $connector->read('a1');
        } finally {
            $connector->disconnect();
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    public function testExternalProcessBridgeLifecycle(): void
    {
        // Fake external bridge: echoes READY on startup, then echoes every
        // command line read from stdin.
        $script = tempnam(sys_get_temp_dir(), 'saej1850bridge_') . '.php';
        file_put_contents($script, '#!/usr/bin/env php' . PHP_EOL . '<?php'
            . ' echo "READY\n"; flush();'
            . ' while (($line = fgets(STDIN)) !== false) { echo trim($line) . "\n"; flush(); }' . PHP_EOL);
        chmod($script, 0755);

        try {
            $connector = (new SaeJ1850Protocol())->createConnector([
                'bridge' => new ExternalProcessBridge($script),
            ]);
            $connector->connect();
            $this->assertTrue($connector->isConnected());
            $this->assertSame('external-process', $connector->getBridge()->getType());

            $result = $connector->read('a1');
            $this->assertSame('read {"address":"a1"}', $result['a1']);

            $connector->disconnect();
            $this->assertFalse($connector->isConnected());
        } finally {
            @unlink($script);
        }
    }

    public function testExternalProcessBridgeMissingExecutable(): void
    {
        $connector = (new SaeJ1850Protocol())->createConnector([
            'bridge' => new ExternalProcessBridge('/nonexistent/saej1850-bridge'),
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to start bridge process');
        $connector->connect();
    }

    public function testExternalProcessBridgeExecuteBeforeReady(): void
    {
        $connector = (new SaeJ1850Protocol())->createConnector([
            'bridge' => new ExternalProcessBridge('/nonexistent/saej1850-bridge'),
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Bridge not ready');
        $connector->read('a1');
    }
}
