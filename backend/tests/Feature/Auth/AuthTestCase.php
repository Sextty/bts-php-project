<?php

namespace Tests\Feature\Auth;

use App\Contracts\SmsProviderInterface;
use App\Services\Sms\CapturingSmsDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class AuthTestCase extends TestCase
{
    use RefreshDatabase;

    protected CapturingSmsDriver $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new CapturingSmsDriver;
        $this->app->instance(SmsProviderInterface::class, $this->sms);
    }
}
