<?php

namespace Omnimap\Google;

use Geocoder\Provider\GoogleMaps\GoogleMaps;
use Omnimap\Config;
use Omnimap\Geocoding\GeocoderPhp;
use Omnimap\ProviderFactory;
use Omnimap\ProviderInterface;
use Psr\Http\Client\ClientInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google, from its options:
 *
 *   api_key            the browser key (Maps JavaScript API), shown in the page
 *   server_key         the key of the server's calls (Static Maps, Geocoding); the browser key when empty
 *   secret             the URL signing secret (Static Maps' "signature"; the map cache's names)
 *   version            the Maps JavaScript API's channel: weekly (the default), quarterly, beta, or a number
 *   language, region   what the map and the geocoder answer in
 *   legacy_signature   true: a view is named as glitchr/ux-google named its captures (the pictures kept before omnimap stay valid)
 *   geocoder_ttl       how long a geocoder's answer is kept, in seconds (30 days)
 *
 * The HTTP client (Static Maps) and the PSR-18 client (geocoder-php's
 * GoogleMaps provider) are the application's; without them, or without a
 * key (server_key, else api_key), those capabilities are off.
 */
final class GoogleFactory extends ProviderFactory
{
    public function __construct(
        private readonly ?HttpClientInterface $http = null,
        private readonly ?ClientInterface $psr18 = null,
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnimap.factory_name' => 'google',
            'omnimap.factory_title' => 'Google Maps',
            'omnimap.required_options' => [],
            'api_key' => '',
            'server_key' => '',
            'secret' => '',
            'version' => 'weekly',
            'language' => '',
            'region' => '',
            'legacy_signature' => false,
            'geocoder_ttl' => 2592000,
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        $serverKey = $c->string('server_key') ?? $c->string('api_key');
        $geocoder = null;
        if (null !== $this->psr18 && null !== $serverKey) {
            $geocoder = new GeocoderPhp(new GoogleMaps($this->psr18, $c->string('region'), $serverKey), $this->cache, $c->int('geocoder_ttl', 2592000));
        }

        return new GoogleProvider(
            apiKey: $c->string('api_key'),
            serverKey: $serverKey,
            secret: $c->string('secret'),
            version: $c->string('version') ?? 'weekly',
            language: $c->string('language'),
            region: $c->string('region'),
            legacySignature: $c->bool('legacy_signature'),
            http: $this->http,
            geocoder: $geocoder,
        );
    }
}
