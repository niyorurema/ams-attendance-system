<?php

use App\Services\ZKTecoService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ZKTecoServiceEncodingTest extends CIUnitTestCase
{
    public function testNormaliseLesNomsMojibakesSansModifierLesChainesNormales(): void
    {
        $service = new class extends ZKTecoService {
            public function normaliserPourTest(string $value): string
            {
                return $this->normalizeUtf8String($value);
            }
        };

        $cases = [
            'DieudonnÃ©' => 'Dieudonné',
            'Joselyne' => 'Joselyne',
            'MUNEZERO' => 'MUNEZERO',
            'FranÃ§ois' => 'François',
            'CÃ©lestin' => 'Célestin',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, $service->normaliserPourTest($input), $input);
        }
    }
}