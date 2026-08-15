<?php

declare(strict_types=1);

namespace ModelsLab\ModelQ\Tests\Unit;

use ModelsLab\ModelQ\ModelQ;
use PHPUnit\Framework\TestCase;
use Redis;

/**
 * Regression tests for the 2026-08-15 silent worker stall.
 *
 * A dropped TCP connection to Redis left workers parked forever on a blocking
 * read. phpredis defaults its read timeout to 0 — "wait forever" — so the
 * server-side BLPOP timeout is no protection at all: the reply that would end
 * the wait can never arrive over a socket that no longer exists.
 */
class ConnectionLivenessTest extends TestCase
{
    /**
     * The client must outwait the server, or every idle poll aborts early.
     *
     * BLPOP asks Redis to hold the pop for BLPOP_TIMEOUT seconds. If the client
     * gives up reading before then, a correct empty reply is turned into an
     * exception on every single idle loop.
     */
    public function testReadTimeoutExceedsBlpopTimeout(): void
    {
        $this->assertGreaterThan(
            ModelQ::BLPOP_TIMEOUT,
            ModelQ::READ_TIMEOUT,
            'READ_TIMEOUT must exceed BLPOP_TIMEOUT or idle polls abort mid-read'
        );
    }

    /**
     * Every liveness bound must actually be bounded.
     *
     * Zero is phpredis' "wait forever", which is the defect itself.
     */
    public function testLivenessBoundsAreFinite(): void
    {
        foreach ([
            'BLPOP_TIMEOUT' => ModelQ::BLPOP_TIMEOUT,
            'CONNECT_TIMEOUT' => ModelQ::CONNECT_TIMEOUT,
            'READ_TIMEOUT' => ModelQ::READ_TIMEOUT,
            'TCP_KEEPALIVE' => ModelQ::TCP_KEEPALIVE,
        ] as $name => $value) {
            $this->assertGreaterThan(0, $value, "$name must be a finite bound, not 0 (wait forever)");
        }
    }

    /**
     * The worker's blocking pop must carry the configured bound.
     */
    public function testWorkerBlockingPopUsesTheConfiguredTimeout(): void
    {
        $redis = $this->createMock(Redis::class);
        $seenTimeout = null;

        // The loop also runs its prune/requeue housekeeping; give those reads an
        // empty result so the assertion below is about BLPOP and nothing else.
        $redis->method('hGetAll')->willReturn([]);
        $redis->method('zRangeByScore')->willReturn([]);
        $redis->method('sMembers')->willReturn([]);

        $modelq = new ModelQ(redisClient: $redis, serverId: 'liveness-test');

        $redis->method('blPop')->willReturnCallback(
            function (array $keys, $timeout) use (&$seenTimeout, $modelq) {
                $seenTimeout = $timeout;
                $modelq->stop();   // one iteration is enough

                return false;
            }
        );

        $modelq->startWorkers(1);

        $this->assertSame(
            ModelQ::BLPOP_TIMEOUT,
            $seenTimeout,
            'worker issued a blocking pop that does not use BLPOP_TIMEOUT'
        );
    }
}
