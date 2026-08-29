<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test may reach the real network. Validation-1 calls the configured advisory AI
        // document check, so every test that runs it (ValidationFlowTest, StaffReviewTest,
        // AppointmentSchedulingTest, ReportChatTest) was silently issuing live API calls —
        // burning the account's free-tier quota, and making the suite slow and dependent on a
        // third party being up. Tests that genuinely exercise an HTTP integration fake it
        // explicitly (see DocumentVerificationTest); anything else throws here instead of
        // escaping to the internet.
        Http::preventStrayRequests();
    }

    /** Real PDF signature for tests that exercise server-side content sniffing. */
    protected function fakePdf(string $name = 'document.pdf', int $kilobytes = 1): UploadedFile
    {
        $minimum = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n";
        $content = str_pad($minimum, max(strlen($minimum), $kilobytes * 1024), "\0");

        return UploadedFile::fake()->createWithContent($name, $content);
    }
}
