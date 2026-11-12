<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * La raíz redirige al dashboard de precios.
     */
    public function test_the_application_redirects_to_the_prices_dashboard(): void
    {
        $this->get('/')->assertRedirect('/precios');
    }
}
