<?php

namespace UprzejmieDonosze\Tests;

use PHPUnit\Framework\TestCase;

/**
 * _log_syslog_datagram() is pure formatting (no socket I/O), split out of
 * Logger.php specifically to be unit-testable — see AGENTS.md § Logging.
 */
class LoggerTest extends TestCase
{
    public function testDebugSeverityPri(): void
    {
        // LOG_DAEMON (24) | LOG_DEBUG (7) = 31
        $this->assertSame(
            '<31>uprzejmiedonosze-dev: hello',
            \_log_syslog_datagram(LOG_DEBUG, 'uprzejmiedonosze-dev', 'hello')
        );
    }

    public function testInfoSeverityPri(): void
    {
        // LOG_DAEMON (24) | LOG_INFO (6) = 30
        $this->assertSame(
            '<30>staging-webapp-srv: hello',
            \_log_syslog_datagram(LOG_INFO, 'staging-webapp-srv', 'hello')
        );
    }

    public function testErrorSeverityPri(): void
    {
        // LOG_DAEMON (24) | LOG_ERR (3) = 27
        $this->assertSame(
            '<27>prod-worker-cron: hello',
            \_log_syslog_datagram(LOG_ERR, 'prod-worker-cron', 'hello')
        );
    }

    public function testIdentIsPrefixedVerbatim(): void
    {
        $this->assertSame(
            '<30>some-ident: multi word message',
            \_log_syslog_datagram(LOG_INFO, 'some-ident', 'multi word message')
        );
    }
}
