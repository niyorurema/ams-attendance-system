<?php

namespace App\Services;

use Throwable;

/** Claims and executes queued retries while preserving each run's final outcome. */
final class SystemJobRetryProcessor
{
    public function __construct(private readonly SystemJobService $jobs)
    {
    }

    /**
     * @param callable(array<string,mixed>):array<string,mixed> $execute
     * @return array{processed:int,errors:int}
     */
    public function process(callable $execute, int $limit = 20): array
    {
        $processed = 0;
        $errors = 0;
        $limit = max(1, min(100, $limit));

        while ($processed < $limit && ($request = $this->jobs->claimNextRetry()) !== null) {
            $processed++;
            $runId = null;
            $reportContext = $request['parameters']['report_context'] ?? null;
            $runContext = is_array($reportContext) ? ['retry_context' => $reportContext] : [];
            try {
                $runId = $this->jobs->start(
                    (string) $request['task_key'],
                    $request['scope_id'] === null ? null : (int) $request['scope_id'],
                    isset($request['requested_by']) ? (int) $request['requested_by'] : null,
                    $runContext
                );
                $summary = $execute($request);
                $status = ($summary['erreurs'] ?? 0) > 0 ? 'PARTIAL' : 'SUCCESS';
                $this->jobs->finish(
                    $runId,
                    $status,
                    $runContext === [] ? $summary : $runContext + ['result' => $summary]
                );
                $this->jobs->finishRetry((int) $request['id'], $status);
                if ($status === 'PARTIAL') {
                    $errors++;
                }
            } catch (Throwable $exception) {
                $errors++;
                if ($runId !== null) {
                    try {
                        $this->jobs->finish($runId, 'ERROR', $runContext, $exception->getMessage());
                    } catch (Throwable $trackingError) {
                        log_message('critical', 'Impossible de journaliser la relance système #{requestId} : {error}', [
                            'requestId' => $request['id'],
                            'error' => $trackingError->getMessage(),
                        ]);
                    }
                }
                try {
                    $this->jobs->finishRetry((int) $request['id'], 'ERROR', $exception->getMessage());
                } catch (Throwable $trackingError) {
                    log_message('critical', 'Impossible de mettre à jour la relance système #{requestId} : {error}', [
                        'requestId' => $request['id'],
                        'error' => $trackingError->getMessage(),
                    ]);
                }
                log_message('error', 'Relance système #{requestId} échouée : {error}', [
                    'requestId' => $request['id'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return ['processed' => $processed, 'errors' => $errors];
    }
}
