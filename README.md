# omnimap/google

[omniscient/omnimap](https://github.com/glitchr-studio/omnimap)'s Google Maps, under any PHP
host:

- **the live map** - `GoogleProvider::describe()` for `assets/google.js`, which loads the Maps
  JavaScript API (Google's dynamic library loader, `importLibrary()`), draws the map, its
  markers, its styled map types and the style shown below each zoom
  (`MapView::options('google')`: `styles`, `zoomStyles`, `map`);
- **a view as an image** - `image()` / `staticUrl()`: the Static Maps API (640 pixels a side at
  most, `scale` 2 for twice the density), the URL signed with the URL signing secret;
- **geocoding** - geocoder-php's `GoogleMaps` provider behind omnimap's `GeocoderInterface`,
  answers kept;
- **ux-google's names** - `legacy_signature: true` names a view's picture as glitchr/ux-google
  named its captures, so they stay valid.

```php
use Omnimap\Google\GoogleFactory;
use Omnimap\Registry;
use Symfony\Component\HttpClient\{HttpClient, Psr18Client};

$http = HttpClient::create();
$registry = new Registry([new GoogleFactory($http, new Psr18Client($http), $cache)], [
    'maps' => ['factory' => 'google', 'options' => [
        'api_key' => $browserKey,        // shown in the page: restrict it to your sites
        'server_key' => $serverKey,      // Static Maps and Geocoding
        'secret' => $urlSigningSecret,
    ]],
]);
```

Options: `api_key`, `server_key`, `secret`, `version` (weekly), `language`, `region`,
`legacy_signature`, `geocoder_ttl` (30 days).

## Consent and terms

The live map loads Google's script and tiles from the visitor's browser
(`maps.googleapis.com`, `maps.gstatic.com`, `fonts.googleapis.com`): a third party, loaded
through the host's consent gate (`omnimap.js`'s `consent`, feature `MAP`). Google's terms
(<https://cloud.google.com/maps-platform/terms>) restrict caching and storing Maps content and
require its attribution to stay visible: a picture of Google's map kept in omnimap's cache is
the host's responsibility.

## Verified, and not

- Verified (the core's harness, PHP 8.4): ux-google's names for three views, against the rule
  lesringostars' `App\Page\DestinationMap` copies; the description; the Static Maps URL, its
  size clamped, its markers and style, and its signature - equal to the one geocoder-php's own
  implementation of Google's URL signing gives for the same secret and URL; an image fetched
  through a recorded client; geocoding through geocoder-php with an answer written from the
  Geocoding API's documentation (one call, the second from the cache); every class loading with
  the package's requirements alone (`BareTest`).
- Verified on lesringostars (2026-10-23): the names of the pictures ux-google captured kept
  (tcIj2DZnhR2xGsZ8K8t2ID4PKVg, its 24 tiles served at the same addresses); no request to Google
  from a visitor's page (headless Chrome, 1440 and 390 pixels).
- Not verified live: Google's answers themselves (Static Maps, Geocoding, the Maps JavaScript API
  drawn by assets/google.js) - no key in the harness.

## Licence

MIT. An unofficial client, affiliated with Google in no way.
