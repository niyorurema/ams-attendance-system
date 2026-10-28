<?php

namespace App\Controllers\Api;

use App\Services\SystemJobService;
use DomainException;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/** Consultation et mise en file des relances réservées au super-administrateur. */
class SystemJobsController extends BaseApiController
{
    public function index(): ResponseInterface
    {
        if (! $this->isSuperAdmin()) {
            return $this->errorResponse('Cette page est réservée au super-administrateur.', 403);
        }

        try {
            return $this->successResponse($this->jobService()->dashboardOverview());
        } catch (Throwable $exception) {
            log_message('error', 'Lecture des tâches système impossible : {error}', ['error' => $exception->getMessage()]);
            return $this->errorResponse('Impossible de charger les tâches système.', 500);
        }
    }

    public function retry(int $runId): ResponseInterface
    {
        if (! $this->isSuperAdmin()) {
            return $this->errorResponse('Cette action est réservée au super-administrateur.', 403);
        }

        try {
            $userId = (int) ($this->claims()?->sub ?? 0);
            if ($userId < 1) {
                return $this->authorizationError();
            }
            $queued = $this->jobService()->enqueueRetry($runId, $userId);

            return $this->successResponse($queued, 'La relance a été mise en file et sera traitée par le planificateur.', 202);
        } catch (DomainException $exception) {
            return $this->errorResponse($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            log_message('error', 'Mise en file de la relance impossible : {error}', ['error' => $exception->getMessage()]);
            return $this->errorResponse('Impossible de mettre cette relance en file.', 500);
        }
    }

    protected function jobService(): SystemJobService
    {
        return new SystemJobService();
    }
}
