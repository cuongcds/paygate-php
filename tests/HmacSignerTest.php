<?php

declare(strict_types=1);

namespace PayGate\Tests;

use PayGate\HmacSigner;
use PHPUnit\Framework\TestCase;

final class HmacSignerTest extends TestCase
{
    public function testSignMatchesReferenceVector(): void
    {
        $signer = new HmacSigner('my_secret');

        $signature = $signer->sign('POST', '/api/v1/checkout-sessions', '{"a":1}', 1700000000);

        $expected = hash_hmac(
            'sha256',
            '1700000000.POST./api/v1/checkout-sessions.{"a":1}',
            'my_secret'
        );
        $this->assertSame($expected, $signature);
    }

    public function testMethodIsUppercasedRegardlessOfInputCase(): void
    {
        $signer = new HmacSigner('secret');

        $this->assertSame(
            $signer->sign('post', '/x', '', 1),
            $signer->sign('POST', '/x', '', 1)
        );
    }

    public function testDifferentBodyProducesDifferentSignature(): void
    {
        $signer = new HmacSigner('secret');

        $this->assertNotSame(
            $signer->sign('GET', '/x', 'a', 1),
            $signer->sign('GET', '/x', 'b', 1)
        );
    }

    public function testOutputIsLowercaseHex(): void
    {
        $signer = new HmacSigner('secret');

        $signature = $signer->sign('GET', '/x', '', 1);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature);
    }
}
