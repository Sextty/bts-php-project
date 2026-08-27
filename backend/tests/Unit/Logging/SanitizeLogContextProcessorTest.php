<?php

namespace Tests\Unit\Logging;

use App\Logging\SanitizeLogContextProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

class SanitizeLogContextProcessorTest extends TestCase
{
    public function test_it_redacts_sensitive_context_without_removing_operational_identifiers(): void
    {
        $record = new LogRecord(
            datetime: new \DateTimeImmutable,
            channel: 'json',
            level: Level::Info,
            message: 'test',
            context: [
                'request_id' => 'req-1',
                'actor_id' => 12,
                'password' => 'never-log-me',
                'nested' => ['authorization' => 'Bearer secret', 'application_id' => 5],
                'document_id' => 9,
            ],
        );

        $result = (new SanitizeLogContextProcessor)($record);

        $this->assertSame('req-1', $result->context['request_id']);
        $this->assertSame(12, $result->context['actor_id']);
        $this->assertSame(9, $result->context['document_id']);
        $this->assertSame('[REDACTED]', $result->context['password']);
        $this->assertSame('[REDACTED]', $result->context['nested']['authorization']);
        $this->assertSame(5, $result->context['nested']['application_id']);
    }
}
