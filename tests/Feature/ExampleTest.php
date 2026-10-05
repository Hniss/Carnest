<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // Décision Q1 du 2026-10-05 : le visiteur arrive sur la connexion des adultes.
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk();
    }
}
