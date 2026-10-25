<?php

namespace Omnimap\Google;

use Omnimap\Capabilities;
use Omnimap\Exception\InvalidConfigException;
use Omnimap\Exception\NotSupportedException;
use Omnimap\Exception\ProviderException;
use Omnimap\Exception\UnreachableException;
use Omnimap\Geocoding\GeocoderPhp;
use Omnimap\GeocoderInterface;
use Omnimap\MapRendererInterface;
use Omnimap\Model\Image;
use Omnimap\Model\LatLng;
use Omnimap\Model\MapView;
use Omnimap\Model\Size;
use Omnimap\Signer;
use Omnimap\StaticMapInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google Maps: the live map (the Maps JavaScript API, drawn by
 * assets/google.js), a view as an image (the Static Maps API, the URL
 * signed with the secret), and geocoding through geocoder-php's GoogleMaps
 * provider.
 *
 * Google's own options of a view, under MapView::options('google'):
 *   styles      list of {name, rules: [{featureType, elementType, stylers: [{color: '#99d7fe'}]}], baseMapTypeId}
 *   zoomStyles  {default: <style name>, below: {4: <name>, 7: <name>}}: the style shown below each zoom
 *   map         any other google.maps.MapOptions, as JSON
 */
final class GoogleProvider implements MapRendererInterface, StaticMapInterface, GeocoderInterface
{
    public const STATIC_URL = 'https://maps.googleapis.com/maps/api/staticmap';

    /** The largest image Static Maps makes, per side, at scale 1. */
    public const STATIC_MAX = 640;

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $serverKey = null,
        private readonly ?string $secret = null,
        private readonly string $version = 'weekly',
        private readonly ?string $language = null,
        private readonly ?string $region = null,
        private readonly bool $legacySignature = false,
        private readonly ?HttpClientInterface $http = null,
        private readonly ?GeocoderPhp $geocoder = null,
    ) {
    }

    public function getName(): string
    {
        return 'google';
    }

    public function getTitle(): string
    {
        return 'Google Maps';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            renderer: true,
            staticMap: null !== $this->http,
            geocoder: null !== $this->geocoder,
            browserHosts: ['maps.googleapis.com', 'maps.gstatic.com', 'fonts.googleapis.com'],
            cookies: false,
            terms: ['https://cloud.google.com/maps-platform/terms', 'https://developers.google.com/maps/documentation/javascript/policies'],
        );
    }

    public function describe(MapView $view): array
    {
        $options = $view->options('google');

        return [
            'provider' => 'google',
            'consent' => 'MAP',
            'view' => $view->toArray(),
            'library' => array_filter(['key' => $this->apiKey, 'v' => $this->version, 'language' => $this->language, 'region' => $this->region]),
            'options' => [
                'styles' => $options['styles'] ?? [],
                'zoomStyles' => $options['zoomStyles'] ?? null,
                'map' => $options['map'] ?? new \stdClass(),
            ],
            'attribution' => '',
        ];
    }

    /**
     * What a picture of the view depends on. legacy_signature: the text
     * glitchr/ux-google signed (GmObject::getSignatureWithOptions() of a Map
     * built with zoom, center, html2canvas and disableDefaultUI), so the
     * captures it named keep their names; otherwise the view itself, markers
     * and styles included.
     */
    public function fingerprint(MapView $view): string
    {
        if ($this->legacySignature) {
            return self::legacyArgs([
                'instance' => 'Google\\Model\\Maps\\Map',
                'zoom' => $view->zoom,
                'center' => 'new google.maps.LatLng('.$view->center->lat.', '.$view->center->lng.', false)',
                'html2canvas' => true,
                'disableDefaultUI' => $view->controls ? '' : 'true',
            ]);
        }

        return 'google|'.json_encode([$view->center, $view->zoom, $view->markers, $view->controls, $view->options('google')], \JSON_THROW_ON_ERROR);
    }

    public function attribution(): string
    {
        return '';
    }

    public function image(MapView $view, Size $size, int $scale = 1): Image
    {
        if (null === $this->http) {
            throw new NotSupportedException('No HTTP client was given to the Google provider: no Static Maps.');
        }
        $url = $this->staticUrl($view, $size, $scale);
        try {
            $response = $this->http->request('GET', $url);
            $status = $response->getStatusCode();
            if (200 !== $status) {
                throw ProviderException::answered('Google Static Maps', 'a map image', $status);
            }
            $bytes = $response->getContent();
        } catch (HttpException $e) {
            throw new UnreachableException('Google Static Maps could not be reached: '.$e->getMessage(), 0, $e);
        }
        // The media type the bytes are, not the one announced: a proxy's page in a 200 is no map.
        $type = (getimagesizefromstring($bytes) ?: [])['mime'] ?? null;
        if (null === $type) {
            throw ProviderException::answered('Google Static Maps', 'a map image', 'something that is not an image');
        }
        $clamped = self::clamp($size);

        return new Image($bytes, $type, $clamped->scaled(max(1, min(2, $scale))));
    }

    /**
     * The Static Maps URL of a view: the size clamped to 640 pixels a side,
     * the markers, the default style's rules, the server key, and the
     * signature of the path and query with the secret (Google's "digital
     * signature").
     */
    public function staticUrl(MapView $view, Size $size, int $scale = 1): string
    {
        $key = $this->serverKey ?? throw new InvalidConfigException('Google Static Maps needs a key (server_key or api_key).');
        $size = self::clamp($size);
        $query = [
            'center='.$view->center,
            'zoom='.$view->zoom,
            'size='.$size,
            'scale='.max(1, min(2, $scale)),
        ];
        foreach ($view->markers as $marker) {
            $query[] = 'markers='.rawurlencode((null === $marker->color ? '' : 'color:0x'.ltrim($marker->color, '#').'|').$marker->position);
        }
        foreach (self::defaultStyle($view) as $rule) {
            $query[] = 'style='.rawurlencode($rule);
        }
        if (null !== $this->language) {
            $query[] = 'language='.rawurlencode($this->language);
        }
        if (null !== $this->region) {
            $query[] = 'region='.rawurlencode($this->region);
        }
        $query[] = 'key='.rawurlencode($key);
        $url = self::STATIC_URL.'?'.implode('&', $query);
        if (null === $this->secret) {
            return $url;
        }
        $parts = parse_url($url);

        return $url.'&signature='.(new Signer($this->secret))->signPadded(($parts['path'] ?? '').'?'.($parts['query'] ?? ''));
    }

    public function geocode(string $address, ?string $locale = null, int $limit = 5): array
    {
        return $this->geocoder()->geocode($address, $locale ?? $this->language, $limit);
    }

    public function reverse(LatLng $position, ?string $locale = null, int $limit = 5): array
    {
        return $this->geocoder()->reverse($position, $locale ?? $this->language, $limit);
    }

    private function geocoder(): GeocoderPhp
    {
        return $this->geocoder ?? throw new NotSupportedException('No PSR-18 client or no key was given to the Google provider: no geocoding.');
    }

    private static function clamp(Size $size): Size
    {
        $ratio = min(1.0, self::STATIC_MAX / max($size->width, $size->height));

        return new Size(max(1, (int) floor($size->width * $ratio)), max(1, (int) floor($size->height * $ratio)));
    }

    /** @return list<string> the rules of the view's default style, as Static Maps writes them ("feature:water|color:0x99d7fe") */
    private static function defaultStyle(MapView $view): array
    {
        $options = $view->options('google');
        $styles = \is_array($options['styles'] ?? null) ? $options['styles'] : [];
        $default = \is_array($options['zoomStyles'] ?? null) ? ($options['zoomStyles']['default'] ?? null) : null;
        $rules = [];
        foreach ($styles as $style) {
            if (!\is_array($style) || (null !== $default && ($style['name'] ?? null) !== $default)) {
                continue;
            }
            foreach (\is_array($style['rules'] ?? null) ? $style['rules'] : [] as $rule) {
                if (!\is_array($rule)) {
                    continue;
                }
                $parts = [];
                if (isset($rule['featureType']) && \is_string($rule['featureType'])) {
                    $parts[] = 'feature:'.$rule['featureType'];
                }
                if (isset($rule['elementType']) && \is_string($rule['elementType'])) {
                    $parts[] = 'element:'.$rule['elementType'];
                }
                foreach (\is_array($rule['stylers'] ?? null) ? $rule['stylers'] : [] as $styler) {
                    foreach (\is_array($styler) ? $styler : [] as $name => $value) {
                        $value = \is_scalar($value) ? (string) $value : '';
                        $parts[] = $name.':'.(str_starts_with($value, '#') ? '0x'.substr($value, 1) : $value);
                    }
                }
                $rules[] = implode('|', $parts);
            }
            break;
        }

        return $rules;
    }

    /**
     * GmObject::parseArgs() of glitchr/ux-google: " name='value'", empty and '0' values left out.
     *
     * @param array<array-key, mixed> $args
     */
    private static function legacyArgs(array $args): string
    {
        $parsed = '';
        foreach ($args as $name => $value) {
            $value = \is_array($value) ? self::legacyArgs($value) : (\is_scalar($value) ? (string) $value : '');
            $parsed .= '' !== $value && '0' !== $value ? ' '.$name."='".$value."'" : '';
        }

        return trim($parsed);
    }
}
