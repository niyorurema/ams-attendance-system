<?php

namespace Tests\Feature;

use App\Controllers\IclockController;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Services;

/**
 * Vérifie les cas ADMS qui doivent être rejetés sans toucher au réseau appareil.
 */
final class IclockControllerTest extends CIUnitTestCase
{
    public function testCdataRefuseUneRequeteSansNumeroDeSerie(): void
    {
        $request = Services::incomingrequest(new App(), false);
        $request->setGlobal('get', []);
        $controller = new IclockController();
        $controller->initController(
            $request,
            Services::response(new App(), false),
            Services::logger()
        );

        $response = $controller->cdata();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Missing SN', $response->getBody());
    }
}
