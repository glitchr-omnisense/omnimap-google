<?php

namespace Omnimap\Google\Tests;

use Omnimap\Google\GoogleFactory;
use Omnimap\ProviderFactoryInterface;
use Omnimap\Testing\ArrayCache;
use Omnimap\Testing\PlainTiles;
use Omnimap\Testing\ProviderContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** omnimap's conformance suite on Google: Static Maps answering a PNG, the Geocoding API its documented answer. */
final class GoogleContractTest extends TestCase
{
    use ProviderContractTests;

    protected function contractFactory(?HttpClientInterface $http): ProviderFactoryInterface
    {
        return new GoogleFactory($http, null === $http ? null : new Psr18Client($http), new ArrayCache());
    }

    /** @return array<string, mixed> */
    protected function contractOptions(): array
    {
        return ['api_key' => 'browser-key', 'server_key' => 'server-key'];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{0: int, 1: string, 2: array<string, string|list<string>>}
     */
    protected function contractAnswer(string $method, string $url, array $options): array
    {
        if (str_contains($url, '/maps/api/geocode/')) {
            return [200, (string) file_get_contents(__DIR__.'/Fixtures/geocode-osaka.json'), ['content-type' => 'application/json']];
        }

        return [200, PlainTiles::png(1, 0, 0, 300), ['content-type' => 'image/png']];
    }
}
