<?php

namespace Tests\Feature;

use App\Services\SystemJobService;
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Migrations;

/** Real MySQL/InnoDB race test for the queued retry claim operation. */
final class SystemJobMysqlConcurrencyTest extends CIUnitTestCase
{
    private ?\CodeIgniter\Database\BaseConnection $mysqlDb = null;
    private int $runId = 0;
    private int $retryId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (env('AMS_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('Set AMS_MYSQL_TESTS=1 to enable isolated MySQL integration tests.');
        }
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The concurrent MySQL test requires pcntl.');
        }

        $this->mysqlDb = Database::connect('mysqltests');
        $migrations = new MigrationRunner(config(Migrations::class), 'mysqltests');
        $migrations->setNamespace('App');
        if (! $migrations->latest()) {
            $this->fail('Unable to prepare the isolated MySQL test schema.');
        }
        $engine = $this->mysqlDb->query("SHOW TABLE STATUS LIKE 'system_job_retry_requests'")->getRowArray();
        $this->assertSame('InnoDB', $engine['Engine'] ?? null);
    }

    protected function tearDown(): void
    {
        if ($this->mysqlDb !== null) {
            if ($this->retryId > 0) {
                $this->mysqlDb->table('system_job_retry_requests')->where('id', $this->retryId)->delete();
            }
            if ($this->runId > 0) {
                $this->mysqlDb->table('system_job_runs')->where('id', $this->runId)->delete();
            }
        }
        parent::tearDown();
    }

    public function testDeuxWorkersNePeuventPasReclamerLaMemeRelance(): void
    {
        $jobs = new SystemJobService($this->mysqlDb);
        $this->runId = $jobs->start('gpio:sync-obr');
        $jobs->finish($this->runId, 'ERROR', [], 'test');
        $retry = $jobs->enqueueRetry($this->runId, 1);
        $this->retryId = $retry['id'];

        $token = bin2hex(random_bytes(12));
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ams-mysql-claim-' . $token;
        if (! mkdir($directory, 0700)) {
            $this->fail('Unable to create the temporary concurrency-test directory.');
        }
        $barrier = $directory . DIRECTORY_SEPARATOR . 'go';
        $children = [];

        try {
            $this->mysqlDb->close();
            for ($worker = 0; $worker < 2; $worker++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->fail('Unable to start both MySQL claim workers.');
                }
                if ($pid === 0) {
                    while (! is_file($barrier)) {
                        usleep(1000);
                    }
                    try {
                        $workerDb = Database::connect('mysqltests');
                        $claimed = (new SystemJobService($workerDb))->claimNextRetry();
                        file_put_contents(
                            $directory . DIRECTORY_SEPARATOR . 'worker-' . $worker,
                            $claimed === null ? 'none' : (string) $claimed['id'],
                            LOCK_EX
                        );
                        exit(0);
                    } catch (\Throwable) {
                        exit(1);
                    }
                }
                $children[] = $pid;
            }

            touch($barrier);
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }

            $results = [
                file_get_contents($directory . DIRECTORY_SEPARATOR . 'worker-0'),
                file_get_contents($directory . DIRECTORY_SEPARATOR . 'worker-1'),
            ];
            $this->assertSame(1, count(array_filter($results, fn (string $result): bool => $result === (string) $this->retryId)));
            $this->assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'none')));
        } finally {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
            $this->mysqlDb = Database::connect('mysqltests');
        }
    }
}
