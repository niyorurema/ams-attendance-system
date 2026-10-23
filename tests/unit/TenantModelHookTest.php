<?php

use App\Models\TenantModel;
use App\Services\ParametragePointageService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TenantModelHookTest extends CIUnitTestCase
{
    public function testHookInitialiseLesParametresSansRemplacerLesCallbacks(): void
    {
        $initialises = [];
        $model = new class($initialises) extends TenantModel {
            /** @var array<int> */
            public array $initialises;

            public function __construct(array &$initialises)
            {
                $this->initialises = &$initialises;
                parent::__construct();
            }

            public function appelerHook(array $data): array
            {
                return $this->initialiserParametresPointage($data);
            }

            protected function creerServiceParametragePointage(): ParametragePointageService
            {
                return new class(function (int $tenantId): void {
                    $this->initialises[] = $tenantId;
                }) extends ParametragePointageService {
                    private \Closure $callback;

                    public function __construct(\Closure $callback)
                    {
                        $this->callback = $callback;
                    }

                    public function initialiserParametresParDefaut(int $tenantId): void
                    {
                        ($this->callback)($tenantId);
                    }
                };
            }
        };

        $data = ['id' => 34, 'data' => ['name' => 'Tenant'], 'result' => true];
        $this->assertSame($data, $model->appelerHook($data));
        $this->assertSame([34], $initialises);

        $callbacks = new ReflectionProperty(TenantModel::class, 'afterInsert');
        $callbacks->setAccessible(true);
        $this->assertSame(['initialiserParametresPointage'], $callbacks->getValue($model));
    }

    public function testHookIgnoreUneInsertionSansIdentifiantTenant(): void
    {
        $model = new class() extends TenantModel {
            public function appelerHook(array $data): array
            {
                return $this->initialiserParametresPointage($data);
            }
        };

        $this->assertSame([], $model->appelerHook([]));
    }
}
