<?php

namespace Tests\Unit\Domain\Creatures;

use App\Domain\Creatures\Support\TrustedCryUrl;
use PHPUnit\Framework\TestCase;

class TrustedCryUrlTest extends TestCase
{
    public function test_accepts_trusted_pokeapi_cry_urls(): void
    {
        $latest = 'https://raw.githubusercontent.com/PokeAPI/cries/main/cries/pokemon/latest/1.ogg';
        $legacy = 'https://raw.githubusercontent.com/PokeAPI/cries/main/cries/pokemon/legacy/1.ogg';

        $this->assertSame($latest, TrustedCryUrl::normalize($latest));
        $this->assertSame($legacy, TrustedCryUrl::normalize($legacy));
        $this->assertSame($latest, TrustedCryUrl::prefer($latest, $legacy));
    }

    public function test_falls_back_to_legacy_when_latest_missing(): void
    {
        $legacy = 'https://raw.githubusercontent.com/PokeAPI/cries/main/cries/pokemon/legacy/25.ogg';
        $this->assertSame($legacy, TrustedCryUrl::prefer(null, $legacy));
    }

    public function test_rejects_untrusted_or_invalid_urls(): void
    {
        $this->assertNull(TrustedCryUrl::normalize('http://raw.githubusercontent.com/PokeAPI/cries/main/cries/pokemon/latest/1.ogg'));
        $this->assertNull(TrustedCryUrl::normalize('https://evil.example/audio.ogg'));
        $this->assertNull(TrustedCryUrl::normalize('https://raw.githubusercontent.com/other/repo/main/file.ogg'));
        $this->assertNull(TrustedCryUrl::prefer(null, null));
    }
}
