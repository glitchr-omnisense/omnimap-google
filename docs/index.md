# omnimap/google

Google Maps for [omnisense/omnimap](https://github.com/glitchr-omnisense/omnimap): the live map drawn
in the browser by the Maps JavaScript API (`assets/google.js`, a driver for omnimap's
`omnimap.js`), a view as an image made by the Static Maps API, the URL signed with the URL signing
secret, and geocoding through geocoder-php's `GoogleMaps` provider. A `legacy_signature` option
names a view's picture as glitchr/ux-google named its captures, so pictures kept before omnimap
stay valid.

| Operation | Where | Address |
|---|---|---|
| live map (browser) | Maps JavaScript API, Google's dynamic library loader | `https://maps.googleapis.com/maps/api/js?libraries=maps&key=...&v=...&callback=google.maps.__ib__` |
| image (server) | Static Maps API | `GET https://maps.googleapis.com/maps/api/staticmap?...` (`GoogleProvider::STATIC_URL`) |
| geocode, reverse (server) | Geocoding API, through `geocoder-php/google-maps-provider` | `GET https://maps.googleapis.com/maps/api/geocode/json?address=...` / `?latlng=...` |

There is no sandbox: every call is billed to the key's Google Cloud project.

## Installation

```sh
composer require omnimap/google
```

Requires PHP 8.2, `omnisense/omnimap` (1.x), `geocoder-php/google-maps-provider` (^4.7),
`psr/http-client`, `psr/simple-cache` and `symfony/http-client-contracts`. Static Maps needs a
Symfony HTTP client (`symfony/http-client`), geocoding a PSR-18 client (Symfony's `Psr18Client`
with `nyholm/psr7`, or any other). In the browser, `assets/google.js` is registered with
`omnimap.js` ([The browser](https://github.com/glitchr-omnisense/omnimap/blob/1.x/docs/javascript.md)).
With omnibridge/symfony or omnibridge/laravel, the factory is registered as soon as the package
is installed.

## Credentials

Three values from the Google Cloud project that has the Maps APIs enabled (Maps JavaScript API,
Maps Static API, Geocoding API):

- a **browser key** (`api_key`): written in the page, read by anyone - restrict it to your sites
  (HTTP referrers) and to the Maps JavaScript API;
- a **server key** (`server_key`): sent by the server only, for Static Maps and Geocoding; the
  browser key is used when it is empty;
- the **URL signing secret** (`secret`, URL-safe base64): signs Static Maps URLs. The same value
  can be omnimap's map cache secret (the bridges' `secret`): it is what lesringostars uses.

Google publishes no shared test key.

## Options

`new GoogleFactory(?HttpClientInterface $http = null, ?Psr\Http\Client\ClientInterface $psr18 = null, ?Psr\SimpleCache\CacheInterface $cache = null)`;
no option is required.

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `api_key` | string | `''` | no | the browser key; the description's `library.key` |
| `server_key` | string | `''` | no | the server's key (Static Maps, Geocoding); `api_key` when empty |
| `secret` | string | `''` | no | the URL signing secret; empty: Static Maps URLs unsigned |
| `version` | string | `weekly` | no | the Maps JavaScript API's channel or version (`weekly`, `quarterly`, `beta`, a number); `library.v` |
| `language` | string | `''` | no | the map's language (`library.language`), Static Maps' `language`, the geocoder's default locale |
| `region` | string | `''` | no | the map's region (`library.region`), Static Maps' `region`, geocoder-php's region |
| `legacy_signature` | bool | `false` | no | `true`: fingerprints as glitchr/ux-google did |
| `geocoder_ttl` | int | `2592000` | no | seconds a geocoding answer is kept in `$cache` |

A view's own options, under `MapView::options('google')`:

| Key | |
|---|---|
| `styles` | list of `{name, rules: [{featureType, elementType, stylers: [{color: '#99d7fe'}]}], baseMapTypeId}`: styled map types |
| `zoomStyles` | `{default: <style name>, below: {<zoom>: <style name>}}`: the style shown, and the one shown below each zoom |
| `map` | any other `google.maps.MapOptions`, as JSON |

## Capabilities

| Capability | Supported |
|---|---|
| renderer (`MapRendererInterface`) | yes, always |
| static map (`StaticMapInterface`) | with the HTTP client; the call needs `server_key` or `api_key` too |
| geocoder (`GeocoderInterface`) | with the PSR-18 client and `server_key` or `api_key` |
| tiles (`TileSourceInterface`) | no |

Browser hosts: `maps.googleapis.com`, `maps.gstatic.com`, `fonts.googleapis.com`; cookies: declared
`false`. Terms: `https://cloud.google.com/maps-platform/terms`,
`https://developers.google.com/maps/documentation/javascript/policies`.

> [!WARNING]
> Known defect: with the HTTP client and no key, `capabilities()->staticMap` is `true`, where
> it should be `false`: `image()` then throws `InvalidConfigException` (`Google Static Maps needs a
> key (server_key or api_key).`) instead of `NotSupportedException`, and omnibridge's
> `cache.make: auto` tries to make pictures it cannot.

## Behaviour

**Description.** `describe()` returns `provider: google`, `consent: MAP`, the view,
`library: {key, v, language, region}` (empty values left out), `options: {styles, zoomStyles, map}`
(`[]`, `null` and `{}` when the view sets none) and `attribution: ''` (the live map draws Google's
own; `attribution()` is empty too, so `MapHtml::snapshot()` prints no attribution band beside a
kept picture).

**The live map (`assets/google.js`).** Installs Google's dynamic library loader unless
`google.maps.importLibrary` exists, its URL parameters from `library` (camelCase written
snake_case), the page's script nonce copied; imports `maps` and `marker`; draws
`new Map(element, {center, zoom, disableDefaultUI: !controls, ...map})`; one
`google.maps.Marker` per marker with its title (its colour is not used); registers each style as
a `StyledMapType` (`baseMapTypeId` `roadmap` by default); with `zoomStyles.default`, sets the map
type, and when `below` has stops, sets it again on `idle` and `zoom_changed` to the style of the
first stop above the current zoom. It re-dispatches the map's `tilesloaded`, `idle` and `drag` on
`window`, as glitchr/ux-google's maps did.

**Fingerprint.** `google|` and the JSON of centre, zoom, markers, controls and the view's `google`
options: a change of style makes a new picture. With `legacy_signature`, ux-google's text instead:
`instance='Google\Model\Maps\Map' zoom='<zoom>' center='new google.maps.LatLng(<lat>, <lng>, false)' html2canvas='1'`
and `disableDefaultUI='true'` when the controls are off; markers and styles are not part of it.

**Image.** `staticUrl($view, $size, $scale)` builds the URL: `center`, `zoom`, `size` clamped to
640 pixels on its longer side (`STATIC_MAX`, the ratio kept), `scale` 1 or 2, one `markers=` per
marker (`color:0x<rrggbb>|` when it has a colour, its title not sent), one `style=` per rule of
the default style (`feature:`, `element:`, then each styler, `#` colours written `0x`), `language`,
`region`, `key`, and with a secret `signature=` (HMAC-SHA1 of path and query, padded URL-safe
base64: Google's digital signature). `image()` fetches it: a status other than 200 is a
`ProviderException`, the bytes must be an image (their type is read from the bytes), and
`Image::$size` is the clamped size times the scale. Images are not cached by the provider:
omnimap's map cache keeps the picture.

**Geocoding.** geocoder-php's `GoogleMaps($psr18, region, serverKey)` behind
`Omnimap\Geocoding\GeocoderPhp`, the locale the call's or else `language`, answers kept
`geocoder_ttl` seconds, no pace. A place's `label` is Google's formatted address, its `id` the
`place_id`, `providedBy` `google_maps`.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnimap\Google\GoogleFactory;
use Omnimap\MapHtml;
use Omnimap\Model\LatLng;
use Omnimap\Model\MapView;
use Omnimap\Model\Marker;
use Omnimap\Model\Size;
use Omnimap\Registry;
use Omnimap\Testing\ArrayCache;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

$http = HttpClient::create();
$registry = new Registry([new GoogleFactory($http, new Psr18Client($http), new ArrayCache())], [
    'maps' => ['factory' => 'google', 'options' => [
        'api_key' => 'browser-key',                     // shown in the page: restrict it to your sites
        'server_key' => 'server-key',                   // Static Maps and Geocoding
        'secret' => 'vNIXE0xscrmjlyV-12Nj_BvUPaw=',     // the URL signing secret
        'language' => 'fr',
        'region' => 'FR',
    ]],
]);
$google = $registry->get('maps');

$styles = [['name' => 'pale', 'rules' => [['featureType' => 'water', 'elementType' => 'geometry', 'stylers' => [['color' => '#99d7fe']]]]]];
$view = (new MapView(new LatLng(48.5818, 7.7509), 14, [new Marker(new LatLng(48.5818, 7.7509), 'Cathédrale', '#d33b3b')], controls: false, id: 'map'))
    ->withOptions('google', ['styles' => $styles, 'zoomStyles' => ['default' => 'pale'], 'map' => ['gestureHandling' => 'cooperative']]);

echo json_encode($google->describe($view)['library']), "\n";
echo json_encode($google->describe($view)['options']), "\n";
echo implode(',', $google->capabilities()->contracts()), "\n";
echo $google->staticUrl($view, new Size(800, 400)), "\n";
echo $google->fingerprint($view), "\n";
echo (new GoogleFactory())->create(['legacy_signature' => true])->fingerprint($view), "\n";
```

```
{"key":"browser-key","v":"weekly","language":"fr","region":"FR"}
{"styles":[{"name":"pale","rules":[{"featureType":"water","elementType":"geometry","stylers":[{"color":"#99d7fe"}]}]}],"zoomStyles":{"default":"pale"},"map":{"gestureHandling":"cooperative"}}
renderer,staticMap,geocoder
https://maps.googleapis.com/maps/api/staticmap?center=48.5818,7.7509&zoom=14&size=640x320&scale=1&markers=color%3A0xd33b3b%7C48.5818%2C7.7509&style=feature%3Awater%7Celement%3Ageometry%7Ccolor%3A0x99d7fe&language=fr&region=FR&key=server-key&signature=7qVglXRTCoW7bbIyJb-lboANOX4=
google|[{"lat":48.5818,"lng":7.7509},14,[{"position":{"lat":48.5818,"lng":7.7509},"title":"Cathédrale","color":"#d33b3b"}],false,{"styles":[{"name":"pale","rules":[{"featureType":"water","elementType":"geometry","stylers":[{"color":"#99d7fe"}]}]}],"zoomStyles":{"default":"pale"},"map":{"gestureHandling":"cooperative"}}]
instance='Google\Model\Maps\Map' zoom='14' center='new google.maps.LatLng(48.5818, 7.7509, false)' html2canvas='1' disableDefaultUI='true'
```

(run on 2026-10-25 in omnimap's harness with the fake keys above: nothing was sent to Google -
describing, building a URL and fingerprinting call nothing; `$google->image($view, new
Size(800, 400))` and `$google->geocode('Strasbourg')` would, with real keys)

In the page, beside omnimap's script:

```js
import { register, mountAll } from '.../omnimap/assets/omnimap.js';
import google from '.../omnimap/google/assets/google.js';

register('google', google);
mountAll(document, { consent: (feature, load) => Consent.use(feature, {}, load) });
```

In Symfony (omnibridge/symfony):

```yaml
omnimap:
    provider: google
    secret: '%env(GOOGLE_MAPS_SECRET)%'
    providers:
        google:
            api_key: '%env(GOOGLE_MAPS_CLIENT_KEY)%'
            server_key: '%env(GOOGLE_MAPS_SERVER_KEY)%'
            secret: '%env(GOOGLE_MAPS_SECRET)%'
            legacy_signature: true          # pictures captured by glitchr/ux-google keep their names
    cache:
        drive: local.uploads
```

## Errors

- Static Maps: a status other than 200 is a `ProviderException` (`Google Static Maps answered 403
  for a map image.`), bytes that are not an image too (`... answered something that is not an
  image for a map image.`); a transport failure an `UnreachableException` (`Google Static Maps
  could not be reached: ...`); no key an `InvalidConfigException`; no HTTP client a
  `NotSupportedException`.
- Geocoding: geocoder-php's errors (Google's `REQUEST_DENIED`, `OVER_QUERY_LIMIT`, an invalid
  answer) are `ProviderException`s naming `google_maps` and the question, a network failure an
  `UnreachableException`; no PSR-18 client or no key a `NotSupportedException`.
- `ZERO_RESULTS` is not an error: an empty list.

## Verified, and not

Tested offline in omnimap's harness (PHP 8.4): `Tests/GoogleContractTest.php`, omnimap's
conformance suite on recorded answers (every failure a `ProviderException` or an
`UnreachableException`, a 200 that is not an image refused); `Tests/GoogleProviderTest.php`:
ux-google's names for three views against the rule lesringostars' `App\Page\DestinationMap`
copies, the description, the Static Maps URL (size clamped, markers, style) and its signature -
equal to the one geocoder-php's own implementation of Google's URL signing gives - an image
through a recorded client, geocoding with an answer written from the Geocoding API's
documentation (one call, the second from the cache); `Tests/BareTest.php`: every class loading
with the package's requirements alone. On lesringostars (2026-10-23): the pictures ux-google
captured kept their names and addresses; no request to Google from a visitor's page.

Not verified: Google's live answers (Static Maps, Geocoding) and `assets/google.js` drawn in a
browser - no key in the harness.

## Responsibility

An independent, unofficial client, affiliated with Google in no way. Google's terms
(https://cloud.google.com/maps-platform/terms) restrict caching and storing Maps content and
require its attribution to stay visible: a picture of Google's map kept in omnimap's cache is
the host's responsibility. See the README's "Consent and terms" section.
