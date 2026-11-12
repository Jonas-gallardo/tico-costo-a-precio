<?php

namespace Tests\Feature;

use App\Services\RecalcularPreciosService;
use Mockery;
use Tests\TestCase;

class PreciosControllerTest extends TestCase
{
    /**
     * El botón web debe forzar siempre el modo escaneo (anti-join), incluso
     * cuando el servicio inyectado fue construido en modo alerta.
     */
    public function test_el_boton_recalcular_usa_siempre_modo_escaneo(): void
    {
        config(['profit.escritura_habilitada' => true]);

        $service = Mockery::mock(RecalcularPreciosService::class);
        $service->shouldReceive('conModoEscaneo')->once()->andReturn($service);
        $service->shouldReceive('procesar')->once()->with(false)->andReturn([
            'insertados' => 36,
            'cerrados' => 36,
            'alertas_procesadas' => 9,
        ]);

        $this->app->instance(RecalcularPreciosService::class, $service);

        $this->post('/precios/recalcular')
            ->assertRedirect('/precios')
            ->assertSessionHas('status', 'Se insertaron 36 precios y se cerraron 36 vigencias anteriores.');
    }
}
