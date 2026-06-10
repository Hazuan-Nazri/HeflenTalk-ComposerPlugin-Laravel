<?php

namespace HelfenTalk\Connect\Tests;

use HelfenTalk\Connect\Support\Signature;
use PHPUnit\Framework\TestCase;

class SignatureTest extends TestCase
{
    public function test_signs_and_verifies(): void
    {
        $secret = 'htc_demo_secret';
        $timestamp = '1700000000';
        $body = '{"message":"hi","user_context":{"user_id":1}}';

        $signature = Signature::expected($secret, $timestamp, $body);

        $this->assertTrue(Signature::verify($secret, $timestamp, $body, $signature));
        $this->assertFalse(Signature::verify($secret, $timestamp, $body, 'tampered'));
        $this->assertFalse(Signature::verify('other-secret', $timestamp, $body, $signature));
        $this->assertFalse(Signature::verify($secret, $timestamp, $body . 'x', $signature));
    }

    public function test_matches_heflentalk_be_formula(): void
    {
        // Must be byte-identical to App\Connect\ConnectSignature in HeflenTalk-be.
        $this->assertSame(
            hash_hmac('sha256', '1700000000.body', 'secret'),
            Signature::expected('secret', '1700000000', 'body'),
        );
    }
}
