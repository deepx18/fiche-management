<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Telephony\TelephonyException;
use Tests\TestCase;

class TelephonyExceptionTest extends TestCase
{
    public function test_provider_error_kinds_are_distinguishable(): void
    {
        $this->assertSame(TelephonyException::KIND_AUTHENTICATION, TelephonyException::authentication('Kavkom')->kind());
        $this->assertSame(TelephonyException::KIND_PERMISSION, TelephonyException::permission('Issabel')->kind());
        $this->assertSame(TelephonyException::KIND_RATE_LIMITED, TelephonyException::rateLimited('Issabel')->kind());
        $this->assertSame(TelephonyException::KIND_TIMEOUT, TelephonyException::timeout('Issabel')->kind());
        $this->assertSame(TelephonyException::KIND_MALFORMED_RESPONSE, TelephonyException::malformedResponse('Issabel')->kind());
        $this->assertSame(TelephonyException::KIND_UNSUPPORTED, TelephonyException::unsupported('Non documenté')->kind());
    }

    public function test_messages_do_not_contain_credentials(): void
    {
        $messages = [
            TelephonyException::authentication('Kavkom')->getMessage(),
            TelephonyException::permission('Issabel')->getMessage(),
            TelephonyException::providerError('Issabel', 500)->getMessage(),
        ];

        foreach ($messages as $message) {
            $this->assertStringNotContainsString('Bearer', $message);
            $this->assertStringNotContainsString('password', $message);
            $this->assertStringNotContainsString('token=', $message);
        }
    }
}
