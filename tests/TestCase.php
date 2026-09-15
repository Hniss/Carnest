<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Aucun appel HTTP réel (fournisseurs d'IA compris) ne doit partir depuis les tests.
        Http::preventStrayRequests();
    }
}
