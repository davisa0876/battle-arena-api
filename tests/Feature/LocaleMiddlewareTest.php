<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocaleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_french_accept_language_localizes_not_found_message(): void
    {
        $this->withHeaders(['Accept-Language' => 'fr'])
            ->getJson('/api/creatures/missing-slug')
            ->assertNotFound()
            ->assertJsonPath('message', 'Créature introuvable.')
            ->assertHeader('Content-Language', 'fr');
    }

    public function test_spanish_accept_language_localizes_not_found_message(): void
    {
        $this->withHeaders(['Accept-Language' => 'es-ES,es;q=0.9'])
            ->getJson('/api/creatures/missing-slug')
            ->assertNotFound()
            ->assertJsonPath('message', 'Criatura no encontrada.')
            ->assertHeader('Content-Language', 'es');
    }

    public function test_italian_accept_language_localizes_not_found_message(): void
    {
        $this->withHeaders(['Accept-Language' => 'it-IT'])
            ->getJson('/api/creatures/missing-slug')
            ->assertNotFound()
            ->assertJsonPath('message', 'Creatura non trovata.')
            ->assertHeader('Content-Language', 'it');
    }
}
