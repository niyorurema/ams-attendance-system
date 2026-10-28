<?php

namespace Tests\Feature;

use App\Services\SystemJobService;
use App\Services\SystemJobRetryProcessor;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Database\MigrationRunner;
use Config\Migrations;
use Tests\Support\AttendanceDatabaseTestTrait;

/** Vérifie l’état durable des tâches et la file de relance sur la base de test. */
final class SystemJobServiceTest extends CIUnitTestCase
{
    use AttendanceDatabaseTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $migrations = new MigrationRunner(config(Migrations::class), 'tests');
        $migrations->setNamespace('App');
        if (! $migrations->latest()) {
            $this->fail('Impossible d’appliquer les migrations à la base de test.');
        }
        $this->setUpAttendanceDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownAttendanceDatabase();
        parent::tearDown();
    }

    public function testEnregistreUnEchecEtMetUneSeuleRelanceEnFile(): void
    {
        $jobs = new SystemJobService($this->attendanceDb);
        $runId = $jobs->start('gpio:sync-obr');
        $jobs->finish($runId, 'ERROR', ['tenant_id' => 1], 'Erreur de test');

        $run = $jobs->recentRuns(1)[0];
        $this->assertSame('ERROR', $run['status']);
        $this->assertSame('Erreur de test', $run['error_message']);
        $this->assertSame(1, $run['summary']['tenant_id']);

        $queued = $jobs->enqueueRetry($runId, 7);
        $this->assertSame('PENDING', $queued['status']);
        $this->expectException(\DomainException::class);
        $jobs->enqueueRetry($runId, 7);
    }

    public function testUneRelanceEnFileEstClaimableUneFoisEtTraceSonResultat(): void
    {
        $jobs = new SystemJobService($this->attendanceDb);
        $runId = $jobs->start('capteurs:sync-attendance', 42);
        $jobs->finish($runId, 'PARTIAL', [], 'Délai dépassé');
        $retry = $jobs->enqueueRetry($runId, 7);

        $claimed = $jobs->claimNextRetry();
        $this->assertSame($retry['id'], (int) $claimed['id']);
        $this->assertSame('capteurs:sync-attendance', $claimed['task_key']);
        $this->assertSame(42, (int) $claimed['scope_id']);
        $this->assertNull($jobs->claimNextRetry());

        $jobs->finishRetry($retry['id'], 'SUCCESS');
        $this->assertSame('SUCCESS', $jobs->recentRuns(1)[0]['retry_status']);
    }

    public function testRefuseLaRelanceDUneTacheReussie(): void
    {
        $jobs = new SystemJobService($this->attendanceDb);
        $runId = $jobs->start('rapports:envoyer-quotidiens');
        $jobs->finish($runId, 'SUCCESS');

        $this->expectException(\DomainException::class);
        $jobs->enqueueRetry($runId, 7);
    }

    public function testMarqueUneExecutionSansSignalDeVieCommeObsolete(): void
    {
        $jobs = new SystemJobService($this->attendanceDb);
        $runId = $jobs->start('capteurs:scan-all');
        $this->attendanceDb->table('system_job_runs')
            ->where('id', $runId)
            ->update(['heartbeat_at' => date('Y-m-d H:i:s', time() - 901)]);

        $run = $jobs->recentRuns(1)[0];

        $this->assertSame('STALE', $run['status']);
        $this->assertSame('Aucun signal de vie reçu depuis 15 minutes.', $run['error_message']);
    }

    public function testRefuseLaRelanceDUnScanSansCapteurOuDUneTacheNonAutorisee(): void
    {
        $jobs = new SystemJobService($this->attendanceDb);
        $scanRunId = $jobs->start('capteurs:sync-attendance');
        $jobs->finish($scanRunId, 'ERROR');
        try {
            $jobs->enqueueRetry($scanRunId, 7);
            $this->fail('Une relance sans identifiant de capteur doit être refusée.');
        } catch (\DomainException $exception) {
            $this->assertSame('La relance du scan nécessite un identifiant de capteur.', $exception->getMessage());
        }

        $unsupportedRunId = $jobs->start('capteurs:scan-all');
        $jobs->finish($unsupportedRunId, 'ERROR');

        $this->expectException(\DomainException::class);
        $jobs->enqueueRetry($unsupportedRunId, 7);
    }

    public function testRelanceDeRapportConserveLeContexteDeDateEtDestinataires(): void
    {
        $context = [[
            'tenant_id' => $this->tenantId,
            'configuration_id' => 12,
            'date_execution' => '2026-10-26',
            'date_rapport' => '2026-10-25',
            'eligible' => true,
            'format' => 'PDF',
            'langue' => 'fr',
            'recipients' => [['user_id' => 18, 'unite_id' => 9]],
        ]];
        $jobs = new SystemJobService($this->attendanceDb);
        $runId = $jobs->start('rapports:envoyer-quotidiens', null, null, ['retry_context' => $context]);
        $jobs->finish($runId, 'PARTIAL', ['retry_context' => $context, 'erreurs' => 1]);

        $retry = $jobs->enqueueRetry($runId, 7);
        $claimed = $jobs->claimNextRetry();

        $this->assertSame($context, $retry['parameters']['report_context']);
        $this->assertSame($context, $claimed['parameters']['report_context']);
    }

    public function testWorkerConserveLeStatutPartielEtLeResultatDeLaRelance(): void
    {
        $context = [['tenant_id' => $this->tenantId, 'configuration_id' => 2]];
        $jobs = new SystemJobService($this->attendanceDb);
        $sourceId = $jobs->start('rapports:envoyer-quotidiens', null, null, ['retry_context' => $context]);
        $jobs->finish($sourceId, 'PARTIAL', ['retry_context' => $context, 'erreurs' => 1]);
        $retry = $jobs->enqueueRetry($sourceId, 7);

        $result = (new SystemJobRetryProcessor($jobs))->process(
            static fn (array $request): array => ['erreurs' => 2, 'task_key' => $request['task_key']]
        );

        $this->assertSame(['processed' => 1, 'errors' => 1], $result);
        $runs = $jobs->recentRuns(10);
        $run = $runs[0];
        $sourceRun = array_values(array_filter($runs, static fn (array $entry): bool => (int) $entry['id'] === $sourceId))[0];
        $this->assertSame('PARTIAL', $run['status']);
        $this->assertSame(2, $run['summary']['result']['erreurs']);
        $this->assertSame($context, $run['summary']['retry_context']);
        $this->assertSame($retry['id'], $sourceRun['retry_id']);
        $this->assertSame('PARTIAL', $sourceRun['retry_status']);

        $retryAgain = $jobs->enqueueRetry((int) $run['id'], 7);
        $this->assertSame($context, $retryAgain['parameters']['report_context']);
    }

    public function testWorkerTraceUneErreurEtExposeSonMessageDansLaFile(): void
    {
        $jobs = new SystemJobService($this->attendanceDb);
        $sourceId = $jobs->start('gpio:sync-obr');
        $jobs->finish($sourceId, 'ERROR');
        $retry = $jobs->enqueueRetry($sourceId, 7);

        $result = (new SystemJobRetryProcessor($jobs))->process(
            static fn (): array => throw new \RuntimeException('Erreur worker de test')
        );

        $this->assertSame(['processed' => 1, 'errors' => 1], $result);
        $request = $this->attendanceDb->table('system_job_retry_requests')->where('id', $retry['id'])->get()->getRowArray();
        $this->assertSame('ERROR', $request['status']);
        $this->assertSame('Erreur worker de test', $request['error_message']);
    }

    public function testPurgeHistoriqueRespecteLaRetentionEtLesRelancesActives(): void
    {
        $jobs = new SystemJobService($this->attendanceDb);
        $oldRunId = $jobs->start('gpio:sync-obr');
        $jobs->finish($oldRunId, 'SUCCESS');
        $this->attendanceDb->table('system_job_runs')
            ->where('id', $oldRunId)
            ->update(['finished_at' => date('Y-m-d H:i:s', strtotime('-100 days'))]);

        $this->assertSame(1, $jobs->pruneHistory(90));
        $this->assertSame(0, $this->attendanceDb->table('system_job_runs')->where('id', $oldRunId)->countAllResults());

        $activeRunId = $jobs->start('gpio:sync-obr');
        $jobs->finish($activeRunId, 'ERROR');
        $this->attendanceDb->table('system_job_runs')
            ->where('id', $activeRunId)
            ->update(['finished_at' => date('Y-m-d H:i:s', strtotime('-100 days'))]);
        $activeRetry = $jobs->enqueueRetry($activeRunId, 7);
        $this->assertSame(0, $jobs->pruneHistory(90));
        $this->assertSame(1, $this->attendanceDb->table('system_job_retry_requests')->where('id', $activeRetry['id'])->countAllResults());
    }

    public function testDashboardSignaleLesRapportsAbsentsEtLaFileEnRetard(): void
    {
        $this->attendanceDb->table('rapport_journalier_configs')->insert([
            'tenant_id' => $this->tenantId,
            'heure_envoi' => '08:00:00',
            'format' => 'PDF',
            'langue' => 'fr',
            'actif' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $jobs = new SystemJobService($this->attendanceDb);
        $runId = $jobs->start('gpio:sync-obr');
        $jobs->finish($runId, 'ERROR');
        $retry = $jobs->enqueueRetry($runId, 7);
        $this->attendanceDb->table('system_job_retry_requests')
            ->where('id', $retry['id'])
            ->update(['requested_at' => date('Y-m-d H:i:s', time() - 301)]);

        $overview = $jobs->dashboardOverview();
        $alertKeys = array_column($overview['alerts'], 'key');

        $this->assertContains('daily_reports_missing', $alertKeys);
        $this->assertContains('job_failed', $alertKeys);
        $this->assertContains('retry_queue_stalled', $alertKeys);
    }
}
