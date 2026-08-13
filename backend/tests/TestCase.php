<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach the real network. Validation-1 calls OpenRouter for the advisory AI
        // document check, so every test that runs it (ValidationFlowTest, StaffReviewTest,
        // AppointmentSchedulingTest, ReportChatTest) was silently issuing live API calls —
        // burning the account's free-tier quota, and making the suite slow and dependent on a
        // third party being up. Tests that genuinely exercise an HTTP integration fake it
        // explicitly (see DocumentVerificationTest); anything else throws here instead of
        // escaping to the internet.
        Http::preventStrayRequests();
    }
}
