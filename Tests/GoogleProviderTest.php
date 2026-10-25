<?php

namespace Omnimap\Google\Tests;

use Geocoder\Provider\GoogleMaps\GoogleMaps;
use Geocoder\Query\GeocodeQuery;
use Omnimap\Google\GoogleFactory;
use Omnimap\Google\GoogleProvider;
use Omnimap\Model\LatLng;
use Omnimap\Model\MapView;
use Omnimap\Model\Marker;
use Omnimap\Model\Size;
use Omnimap\Registry;
use Omnimap\Signer;
use Omnimap\Testing\ArrayCache;
use Omnimap\Testing\PlainTiles;
use Omnimap\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GoogleProviderTest extends TestCase
{
    private const SECRET = 'vNIXE0xscrmjlyV-12Nj_BvUPaw=';

    /**
     * glitchr/ux-google's names for the pictures it captured, as
     * lesringostars' App\Page\DestinationMap computes them (its rule copied
     * here: the class and the Map's options " name='value'", HMAC-SHA1 with
     * the secret): the same with legacy_signature.
     */
    public function testUxGooglesNames(): void
    {
        $provider = (new GoogleFactory())->create(['secret' => self::SECRET, 'legacy_signature' => true]);
        \assert($provider instanceof GoogleProvider);
        $signer = new Signer(self::SECRET);

        foreach ([[0.0, 0.0, 2], [30.58715, 136.06688, 5], [40.1164204, -88.2433829, 8]] as [$lat, $lng, $zoom]) {
            $view = new MapView(new LatLng($lat, $lng), $zoom, [new Marker(new LatLng(1, 2))], controls: false, id: 'myMap');
            $uxGoogle = "instance='Google\\Model\\Maps\\Map' zoom='$zoom' center='new google.maps.LatLng($lat, $lng, false)' html2canvas='1' disableDefaultUI='true'";

            self::assertSame($uxGoogle, $provider->fingerprint($view));
            self::assertSame(self::uxGoogleSignature($uxGoogle, self::SECRET), $signer->sign($provider->fingerprint($view)));
        }
        $plain = (new GoogleFactory())->create();
        \assert($plain instanceof GoogleProvider);
        $view = new MapView(new LatLng(1, 2), 3);
        self::assertNotSame($plain->fingerprint($view), $plain->fingerprint($view->withMarker(new Marker(new LatLng(1, 2)))));
    }

    public function testTheDescriptionTheMapsJavascriptApiDraws(): void
    {
        $provider = (new GoogleFactory())->create(['api_key' => 'browser-key', 'language' => 'fr']);
        \assert($provider instanceof GoogleProvider);
        $styles = [['name' => 'water', 'rules' => [['featureType' => 'water', 'stylers' => [['color' => '#99d7fe']]]], 'baseMapTypeId' => 'roadmap']];
        $view = (new MapView(new LatLng(0, 0), 2, controls: false, id: 'myMap'))->withOptions('google', ['styles' => $styles, 'zoomStyles' => ['default' => 'water', 'below' => [4 => 'water']]]);
        $description = $provider->describe($view);

        self::assertSame(['provider', 'consent', 'view', 'library', 'options', 'attribution'], array_keys($description));
        self::assertSame(['key' => 'browser-key', 'v' => 'weekly', 'language' => 'fr'], $description['library']);
        self::assertSame($styles, $description['options']['styles']);
        self::assertSame(['maps.googleapis.com', 'maps.gstatic.com', 'fonts.googleapis.com'], $provider->capabilities()->browserHosts);
    }

    /**
     * The Static Maps URL, signed - the signature checked against
     * geocoder-php's own implementation of Google's URL signing (GoogleMaps::business()).
     */
    public function testAStaticMapUrlSignedAsGoogleAsks(): void
    {
        $provider = (new GoogleFactory())->create(['api_key' => 'browser', 'server_key' => 'server', 'secret' => self::SECRET]);
        \assert($provider instanceof GoogleProvider);
        $view = (new MapView(new LatLng(35.0116, 135.7681), 12, [new Marker(new LatLng(35.0036, 135.778), 'Gion', '#ff0000')]))
            ->withOptions('google', ['styles' => [['name' => 'water', 'rules' => [['featureType' => 'water', 'elementType' => 'geometry', 'stylers' => [['color' => '#99d7fe'], ['visibility' => 'on']]]]]]]);
        $url = $provider->staticUrl($view, new Size(1512, 938), 2);

        self::assertStringStartsWith('https://maps.googleapis.com/maps/api/staticmap?center=35.0116,135.7681&zoom=12&size=640x397&scale=2&markers=color%3A0xff0000%7C35.0036%2C135.778&style=feature%3Awater%7Celement%3Ageometry%7Ccolor%3A0x99d7fe%7Cvisibility%3Aon&key=server&signature=', $url);
        [$unsigned, $signature] = explode('&signature=', $url);
        $parts = parse_url($unsigned);
        self::assertSame((new Signer(self::SECRET))->signPadded($parts['path'].'?'.$parts['query']), $signature);

        // geocoder-php signs its business URLs the same way: the same secret, the same path and query, the same signature.
        $seen = null;
        $mock = new MockHttpClient(static function (string $method, string $url) use (&$seen) {
            $seen = $url;

            return new MockResponse('{"results":[],"status":"ZERO_RESULTS"}');
        });
        GoogleMaps::business(new Psr18Client($mock), 'gme-client', self::SECRET)->geocodeQuery(GeocodeQuery::create('Kyoto'));
        [$theirs, $theirSignature] = explode('&signature=', (string) $seen);
        $parts = parse_url($theirs);
        self::assertSame($theirSignature, (new Signer(self::SECRET))->signPadded($parts['path'].'?'.$parts['query']));
    }

    public function testAStaticImage(): void
    {
        $png = PlainTiles::png(3, 1, 2, 300);
        $http = new RecordedHttpClient(static fn () => [200, $png, ['content-type' => ['image/png']]]);
        $registry = new Registry([new GoogleFactory($http)], ['maps' => ['factory' => 'google', 'options' => ['api_key' => 'k']]]);
        $image = $registry->staticMap('maps')->image(new MapView(new LatLng(1, 2), 3), new Size(300, 200));

        self::assertSame([$png, 'image/png', '300x200'], [$image->bytes, $image->mimeType, (string) $image->size]);
        self::assertSame('https://maps.googleapis.com/maps/api/staticmap?center=1,2&zoom=3&size=300x200&scale=1&key=k', $http->requests[0]['url']);
    }

    /** An answer in the format of Google's Geocoding API documentation (written from it, not recorded: no key here). */
    public function testGeocodingThroughGeocoderPhp(): void
    {
        $calls = 0;
        $mock = new MockHttpClient(static function (string $method, string $url) use (&$calls) {
            ++$calls;
            self::assertStringStartsWith('https://maps.googleapis.com/maps/api/geocode/json?address=Osaka%2C%20Japan&language=en&key=server', $url);

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/geocode-osaka.json'));
        });
        $provider = (new GoogleFactory(null, new Psr18Client($mock), new ArrayCache()))->create(['api_key' => 'browser', 'server_key' => 'server', 'language' => 'en']);
        \assert($provider instanceof GoogleProvider);

        $places = $provider->geocode('Osaka, Japan');
        $provider->geocode('Osaka, Japan');

        self::assertSame(1, $calls);
        self::assertSame(['Osaka, Japan', 'google_maps', 'ChIJ4eIGNFXmAGAR5y9q5G7BW8U', 'JP'], [$places[0]->label, $places[0]->providedBy, $places[0]->id, $places[0]->address?->countryCode]);
        self::assertSame(['lat' => 34.6937378, 'lng' => 135.5021651], $places[0]->position->toArray());
    }

    private static function uxGoogleSignature(string $payload, string $secret): string
    {
        $key = base64_decode(strtr($secret, '-_', '+/'));

        return str_replace('=', '', strtr(base64_encode(hash_hmac('sha1', $payload, $key, true)), '+/', '-_'));
    }
}
