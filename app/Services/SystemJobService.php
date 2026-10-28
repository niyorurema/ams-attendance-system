<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use RuntimeException;
use Throwable;

/** Journal des dernières exécutions et file de relance contrôlée des tâches système. */
final class SystemJobService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    /** @param array<string, mixed> $summary */
    public function start(
        string $taskKey,
        ?int $scopeId = null,
        ?int $requestedBy = null,
        array $summary = []
    ): int
    {
        $now = date('Y-m-d H:i:s');
        $inserted = $this->db->table('system_job_runs')->insert([
            'task_key' => $taskKey,
            'scope_id' => $scopeId,
            'status' => 'RUNNING',
            'started_at' => $now,
            'heartbeat_at' => $now,
            'summary' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'requested_by' => $requestedBy,
        ]);
        $this->ensureDatabaseOperation($inserted, 'Impossible d’enregistrer le démarrage de la tâche.');

        return (int) $this->db->insertID();
    }

    /** @param array<string, mixed> $summary */
    public function heartbeat(int $runId, array $summary, ?string $error = null): void
    {
        $updated = $this->db->table('system_job_runs')->where('id', $runId)->update([
            'heartbeat_at' => date('Y-m-d H:i:s'),
            'summary' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'error_message' => $error,
        ]);
        $this->ensureDatabaseOperation($updated, 'Impossible d’enregistrer le signal de vie de la tâche.');
    }

    /** @param array<string, mixed> $summary */
    public function finish(int $runId, string $status, array $summary = [], ?string $error = null): void
    {
        if (! in_array($status, ['SUCCESS', 'PARTIAL', 'ERROR'], true)) {
            throw new DomainException('Statut de tâche invalide.');
        }
        $now = date('Y-m-d H:i:s');
        $updated = $this->db->table('system_job_runs')->where('id', $runId)->update([
            'status' => $status,
            'heartbeat_at' => $now,
            'finished_at' => $now,
            'summary' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'error_message' => $error,
        ]);
        $this->ensureDatabaseOperation($updated, 'Impossible d’enregistrer le résultat de la tâche.');
    }

    /** @return list<array<string, mixed>> */
    public function recentRuns(int $limit = 50): array
    {
        $staleBefore = date('Y-m-d H:i:s', time() - 900);
        $runsUpdated = $this->db->table('system_job_runs')
            ->where('status', 'RUNNING')
            ->where('heartbeat_at <', $staleBefore)
            ->update([
                'status' => 'STALE',
                'finished_at' => date('Y-m-d H:i:s'),
                'error_message' => 'Aucun signal de vie reçu depuis 15 minutes.',
            ]);
        $this->ensureDatabaseOperation($runsUpdated, 'Impossible de mettre à jour les tâches obsolètes.');
        $retriesUpdated = $this->db->table('system_job_retry_requests')
            ->where('status', 'RUNNING')
            ->where('started_at <', $staleBefore)
            ->update([
                'status' => 'ERROR',
                'finished_at' => date('Y-m-d H:i:s'),
                'error_message' => 'Le traitement de la relance ne répond plus depuis 15 minutes.',
            ]);
        $this->ensureDatabaseOperation($retriesUpdated, 'Impossible de mettre à jour les relances obsolètes.');

        $runsQuery = $this->db->table('system_job_runs')
            ->orderBy('started_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(100, $limit)))
            ->get();
        if ($runsQuery === false) {
            throw new RuntimeException('Impossible de lire les exécutions système : ' . ($this->db->error()['message'] ?? 'erreur SQL inconnue'));
        }
        $rows = $runsQuery->getResultArray();
        $runIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $retriesByRun = [];
        if ($runIds !== []) {
            $retryQuery = $this->db->table('system_job_retry_requests')
                ->whereIn('run_id', $runIds)
                ->orderBy('id', 'DESC')
                ->get();
            if ($retryQuery === false) {
                throw new RuntimeException('Impossible de lire les demandes de relance : ' . ($this->db->error()['message'] ?? 'erreur SQL inconnue'));
            }
            foreach ($retryQuery->getResultArray() as $retry) {
                $runId = (int) $retry['run_id'];
                $retriesByRun[$runId] ??= $retry;
            }
        }

        foreach ($rows as &$row) {
            $decoded = json_decode((string) ($row['summary'] ?? '{}'), true);
            $row['summary'] = is_array($decoded) ? $decoded : [];
            $retry = $retriesByRun[(int) $row['id']] ?? null;
            $row['retry_id'] = $retry === null ? null : (int) $retry['id'];
            $row['retry_status'] = $retry['status'] ?? null;
            $row['retry_requested_at'] = $retry['requested_at'] ?? null;
            $row['retry_started_at'] = $retry['started_at'] ?? null;
            $row['retry_finished_at'] = $retry['finished_at'] ?? null;
            $row['retry_requested_by'] = $retry === null ? null : (int) $retry['requested_by'];
            $row['retry_error_message'] = $retry['error_message'] ?? null;
        }
        unset($row);

        return $rows;
    }

    /** @return array{runs:list<array<string,mixed>>,alerts:list<array<string,mixed>>} */
    public function dashboardOverview(int $limit = 100): array
    {
        $runs = $this->recentRuns($limit);
        $alerts = [];
        $activeReports = $this->db->table('rapport_journalier_configs c')
            ->join('tenants t', 't.id = c.tenant_id')
            ->where('c.actif', 1)
            ->where('t.is_active', 1)
            ->where('t.deleted_at', null)
            ->countAllResults();
        if ($activeReports > 0) {
            $latest = $this->db->table('system_job_runs')
                ->select('started_at')
                ->where('task_key', 'rapports:envoyer-quotidiens')
                ->orderBy('started_at', 'DESC')
                ->orderBy('id', 'DESC')
                ->limit(1)
                ->get()
                ->getRowArray();
            if ($latest === null || strtotime((string) $latest['started_at']) < time() - 93600) {
                $alerts[] = [
                    'key' => 'daily_reports_missing',
                    'severity' => 'HIGH',
                    'count' => 1,
                    'task_key' => 'rapports:envoyer-quotidiens',
                ];
            }
        }
        $latestByTask = [];
        foreach ($runs as $run) {
            if (! isset($latestByTask[$run['task_key']])) {
                $latestByTask[$run['task_key']] = $run;
            }
        }
        foreach ($latestByTask as $taskKey => $run) {
            if ($run['status'] === 'STALE') {
                $alerts[] = [
                    'key' => 'job_stale',
                    'severity' => 'HIGH',
                    'task_key' => $taskKey,
                    'count' => 1,
                ];
            } elseif (in_array($run['status'], ['ERROR', 'PARTIAL'], true)) {
                $alerts[] = [
                    'key' => 'job_failed',
                    'severity' => $run['status'] === 'ERROR' ? 'HIGH' : 'MEDIUM',
                    'task_key' => $taskKey,
                    'count' => 1,
                ];
            }
        }

        $oldPendingRetries = $this->db->table('system_job_retry_requests')
            ->where('status', 'PENDING')
            ->where('requested_at <', date('Y-m-d H:i:s', time() - 300))
            ->countAllResults();
        if ($oldPendingRetries > 0) {
            $alerts[] = [
                'key' => 'retry_queue_stalled',
                'severity' => 'HIGH',
                'count' => $oldPendingRetries,
                'task_key' => null,
            ];
        }

        return ['runs' => $runs, 'alerts' => $alerts];
    }

    /** @return array<string, mixed> */
    public function enqueueRetry(int $runId, int $requestedBy): array
    {
        $runQuery = $this->db->table('system_job_runs')->where('id', $runId)->get();
        if ($runQuery === false) {
            throw new RuntimeException('Impossible de lire la tâche à relancer : ' . ($this->db->error()['message'] ?? 'erreur SQL inconnue'));
        }
        $run = $runQuery->getRowArray();
        if ($run === null || ! in_array($run['status'], ['ERROR', 'PARTIAL', 'STALE'], true)) {
            throw new DomainException('Seule une tâche en échec ou partielle peut être relancée.');
        }
        if (! in_array($run['task_key'], ['rapports:envoyer-quotidiens', 'gpio:sync-obr', 'capteurs:sync-attendance'], true)) {
            throw new DomainException('Cette tâche ne peut pas être relancée depuis l’interface.');
        }
        if ($run['task_key'] === 'capteurs:sync-attendance' && (int) ($run['scope_id'] ?? 0) < 1) {
            throw new DomainException('La relance du scan nécessite un identifiant de capteur.');
        }
        $parameters = [];
        if ($run['task_key'] === 'rapports:envoyer-quotidiens') {
            $summary = json_decode((string) ($run['summary'] ?? '{}'), true);
            $context = is_array($summary) ? ($summary['retry_context'] ?? null) : null;
            if (! is_array($context) || $context === []) {
                throw new DomainException('Le contexte original des rapports est absent; cette exécution ne peut pas être relancée.');
            }
            $parameters['report_context'] = $context;
        }
        $existingRetryQuery = $this->db->table('system_job_retry_requests')
            ->where('run_id', $runId)
            ->get();
        if ($existingRetryQuery === false) {
            throw new RuntimeException('Impossible de vérifier les demandes de relance : ' . ($this->db->error()['message'] ?? 'erreur SQL inconnue'));
        }
        $existingRetry = $existingRetryQuery->getRowArray();
        if ($existingRetry !== null) {
            throw new DomainException('Une demande de relance existe déjà pour cette exécution.');
        }

        $inserted = $this->db->table('system_job_retry_requests')->insert([
            'run_id' => $runId,
            'status' => 'PENDING',
            'requested_by' => $requestedBy,
            'requested_at' => date('Y-m-d H:i:s'),
            'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
        $this->ensureDatabaseOperation($inserted, 'Impossible de mettre la relance en file.');

        return [
            'id' => (int) $this->db->insertID(),
            'status' => 'PENDING',
            'task_key' => $run['task_key'],
            'scope_id' => $run['scope_id'] === null ? null : (int) $run['scope_id'],
            'parameters' => $parameters,
        ];
    }

    /** @return array<string, mixed>|null */
    public function claimNextRetry(): ?array
    {
        $this->db->transBegin();
        try {
            $staleRetriesUpdated = $this->db->table('system_job_retry_requests')
            ->where('status', 'RUNNING')
            ->where('started_at <', date('Y-m-d H:i:s', time() - 900))
            ->update([
                'status' => 'ERROR',
                'finished_at' => date('Y-m-d H:i:s'),
                'error_message' => 'Le traitement de la relance a dépassé le délai de surveillance.',
            ]);
            $this->ensureDatabaseOperation($staleRetriesUpdated, 'Impossible de mettre à jour les relances obsolètes.');
            if ($this->db->DBDriver === 'SQLite3') {
                $retryQuery = $this->db->table('system_job_retry_requests q')
                    ->select('q.id, q.run_id, q.requested_by, q.parameters, r.task_key, r.scope_id')
                    ->join('system_job_runs r', 'r.id = q.run_id')
                    ->where('q.status', 'PENDING')
                    ->orderBy('q.requested_at', 'ASC')
                    ->orderBy('q.id', 'ASC')
                    ->limit(1)
                    ->get();
                if ($retryQuery === false) {
                    throw new RuntimeException('Impossible de lire la file de relance : ' . ($this->db->error()['message'] ?? 'erreur SQL inconnue'));
                }
                $row = $retryQuery->getRowArray();
            } else {
                $retryQuery = $this->db->query(
                    "SELECT q.id, q.run_id, q.requested_by, q.parameters, r.task_key, r.scope_id FROM system_job_retry_requests q "
                    . "JOIN system_job_runs r ON r.id = q.run_id "
                    . "WHERE q.status = 'PENDING' ORDER BY q.requested_at, q.id LIMIT 1 FOR UPDATE"
                );
                if ($retryQuery === false) {
                    throw new RuntimeException('Impossible de lire la file de relance : ' . ($this->db->error()['message'] ?? 'erreur SQL inconnue'));
                }
                $row = $retryQuery->getRowArray();
            }
            if ($row === null) {
                if ($this->db->transStatus() === false) {
                    throw new RuntimeException('La transaction de lecture de la file de relance a échoué.');
                }
                $this->db->transCommit();

                return null;
            }

            $updated = $this->db->table('system_job_retry_requests')
                ->where('id', $row['id'])
                ->where('status', 'PENDING')
                ->update([
                    'status' => 'RUNNING',
                    'started_at' => date('Y-m-d H:i:s'),
                ]);
            $this->ensureDatabaseOperation($updated, 'Impossible de réserver la relance.');
            if ($this->db->affectedRows() !== 1 || $this->db->transStatus() === false) {
                throw new RuntimeException('La relance a été réservée par un autre worker.');
            }
            $this->db->transCommit();
            $parameters = json_decode((string) ($row['parameters'] ?? '{}'), true);
            $row['parameters'] = is_array($parameters) ? $parameters : [];

            return $row;
        } catch (Throwable $exception) {
            try {
                $this->db->transRollback();
            } catch (Throwable $rollbackError) {
                log_message('critical', 'Retour arrière de la réservation de relance impossible : {error}', [
                    'error' => $rollbackError->getMessage(),
                ]);
            }
            throw $exception;
        }
    }

    public function finishRetry(int $requestId, string $status, ?string $error = null): void
    {
        if (! in_array($status, ['SUCCESS', 'PARTIAL', 'ERROR'], true)) {
            throw new DomainException('Statut de relance invalide.');
        }
        $updated = $this->db->table('system_job_retry_requests')->where('id', $requestId)->where('status', 'RUNNING')->update([
            'status' => $status,
            'finished_at' => date('Y-m-d H:i:s'),
            'error_message' => $error,
        ]);
        $this->ensureDatabaseOperation($updated, 'Impossible d’enregistrer le résultat de la relance.');
    }

    public function pruneHistory(int $retentionDays = 90): int
    {
        $retentionDays = max(30, min(3650, $retentionDays));
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . $retentionDays . ' days'));
        $protected = $this->db->table('system_job_retry_requests')
            ->select('run_id')
            ->whereIn('status', ['PENDING', 'RUNNING'])
            ->get();
        if ($protected === false) {
            throw new RuntimeException('Impossible de préparer la rétention des relances.');
        }
        $protectedRunIds = array_map('intval', array_column($protected->getResultArray(), 'run_id'));
        $query = $this->db->table('system_job_runs')
            ->where('finished_at <', $cutoff)
            ->where('status !=', 'RUNNING');
        if ($protectedRunIds !== []) {
            $query->whereNotIn('id', $protectedRunIds);
        }
        $deleted = $query->delete();
        $this->ensureDatabaseOperation($deleted, 'Impossible de purger l’historique des tâches.');

        return $this->db->affectedRows();
    }

    private function ensureDatabaseOperation(bool $succeeded, string $message): void
    {
        if (! $succeeded) {
            throw new RuntimeException($message . ' ' . ($this->db->error()['message'] ?? 'Erreur SQL inconnue.'));
        }
    }
}
