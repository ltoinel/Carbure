<?php

use PHPUnit\Framework\TestCase;

class ApnsTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    protected function setUp(): void
    {
        FakeApnsServer::start();
    }

    public function testSendWithToken()
    {
        $result = Apns::send(self::TOKEN, 'Title', 'Body', 3, ['transactionId' => 12]);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['http_code']);

        $request = FakeApnsServer::requests()[0];
        $this->assertSame(self::TOKEN, $request['token']);
        $this->assertSame('io.carbure.test', $request['topic']);
        $this->assertSame('Title', $request['payload']['aps']['alert']['title']);
        $this->assertSame(3, $request['payload']['aps']['badge']);
        $this->assertSame(12, $request['payload']['transactionId']);

        // ES256 provider token: header.payload.signature with the key id
        $this->assertStringStartsWith('Bearer ', $request['authorization']);
        $header = json_decode(base64_decode(explode('.', substr($request['authorization'], 7))[0]), true);
        $this->assertSame(['alg' => 'ES256', 'kid' => 'TESTKEY123'], $header);
    }

    public function testRejectedTokenReturnsTheReason()
    {
        $result = Apns::send(str_repeat('0', 64), 'Title', 'Body');

        $this->assertFalse($result['success']);
        $this->assertSame(400, $result['http_code']);
        $this->assertSame('BadDeviceToken', $result['error']);
    }

    public function testInvalidTokenFormat()
    {
        $this->expectException(Error::class);
        Apns::send('not-a-token', 'Title', 'Body');
    }

    public function testSendToMultipleKeepsGoingOnErrors()
    {
        $results = Apns::sendToMultiple([self::TOKEN, 'invalid'], 'Title', 'Body', 5);

        $this->assertTrue($results[self::TOKEN]['success']);
        $this->assertFalse($results['invalid']['success']);
        $this->assertSame(5, FakeApnsServer::requests()[0]['payload']['aps']['badge']);
    }

    public function testIncompleteTokenConfiguration()
    {
        Config::set('apns_key_id', '');
        $this->expectException(Error::class);
        $this->expectExceptionMessage('configuration incomplete');
        Apns::send(self::TOKEN, 'Title', 'Body');
    }

    public function testMissingKeyFile()
    {
        Config::set('apns_key_path', '/does/not/exist.p8');
        $this->expectException(Error::class);
        $this->expectExceptionMessage('key file not found');
        Apns::send(self::TOKEN, 'Title', 'Body');
    }

    public function testInvalidKey()
    {
        $file = tempnam(sys_get_temp_dir(), 'p8');
        file_put_contents($file, 'not a key');
        Config::set('apns_key_path', $file);

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Invalid APNs private key');
        Apns::send(self::TOKEN, 'Title', 'Body');
    }

    public function testMissingBundleId()
    {
        Config::set('apns_bundle_id', '');
        $this->expectException(Error::class);
        Apns::send(self::TOKEN, 'Title', 'Body');
    }

    public function testSendWithCertificate()
    {
        $certificate = tempnam(sys_get_temp_dir(), 'pem');
        Config::set('apns_auth_method', 'certificate');
        Config::set('apns_certificate_path', $certificate);
        Config::set('apns_certificate_password', 'secret');

        // Plain HTTP fake server: the client certificate is simply not used
        $result = Apns::send(self::TOKEN, 'Title', 'Body');

        $this->assertTrue($result['success']);
        $this->assertNull(FakeApnsServer::requests()[0]['authorization']);
    }

    public function testCertificateNotFound()
    {
        Config::set('apns_auth_method', 'certificate');
        Config::set('apns_certificate_path', '/does/not/exist.pem');

        $this->expectException(Error::class);
        Apns::send(self::TOKEN, 'Title', 'Body');
    }

    public function testCertificateNotConfigured()
    {
        Config::set('apns_auth_method', 'certificate');
        Config::set('apns_certificate_path', '');

        $this->expectException(Error::class);
        Apns::send(self::TOKEN, 'Title', 'Body');
    }

    public function testUnreachableServer()
    {
        Config::set('apns_endpoint', 'http://127.0.0.1:1');

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Failed to send APNs notification');
        try {
            Apns::send(self::TOKEN, 'Title', 'Body');
        } finally {
            FakeApnsServer::stop();
        }
    }
}
