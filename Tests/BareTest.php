<?php

namespace Omnimap\Google\Tests;

use Omnimap\Google\GoogleProvider;
use PHPUnit\Framework\TestCase;

/**
 * omnimap/google with no framework: every class of the package loaded in a
 * PHP process of its own, with nothing from outside but what it requires -
 * omnisense/omnimap, geocoder-php's google-maps-provider and what it needs (PSR-18, PSR-7
 * and its discovery), the PSR interfaces and Symfony's HTTP client contracts
 * (interfaces only): no framework, no HTTP client, no application, even in
 * the harness where they are installed beside it.
 */
final class BareTest extends TestCase
{
    private const FOREIGN = '~^(?:Symfony\\\\(?!Contracts\\\\HttpClient\\\\|Polyfill\\\\)|(?:Illuminate|Laravel|Doctrine|Twig|League|GuzzleHttp|Nyholm|Omnibridge|Base|App)\\\\)~';
    private const ALLOWED_FILES = '~/vendor/(?:omnisense/omnimap|omnimap/google|psr/[a-z-]+|php-http/[a-z-]+|symfony/http-client-contracts|willdurand/geocoder|geocoder-php/[a-z-]+|composer)/|/vendor/autoload\.php$|^/omnimap/(?:core|plugins/google)/~';

    public function testEveryClassLoadsWithTheRequiredPackagesAlone(): void
    {
        $autoload = self::autoload();
        $process = proc_open([\PHP_BINARY, __DIR__.'/Fixtures/load-all.php', $autoload, \dirname(__DIR__)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $err.$out);
        $report = json_decode($out, true);
        self::assertIsArray($report, $err.$out);

        self::assertContains(GoogleProvider::class, $report['loaded']);
        self::assertSame([], array_values(preg_grep(self::FOREIGN, $report['symbols']) ?: []), 'no class of a framework, an HTTP client or an application');
        $eager = array_map('dirname', array_filter($report['eager']));
        $outside = array_filter($report['files'], static fn (?string $file) => null !== $file && !\in_array(\dirname($file), $eager, true) && !preg_match(self::ALLOWED_FILES, $file) && !str_starts_with($file, (string) realpath(\dirname(__DIR__)).'/'));
        self::assertSame([], array_values($outside), 'no file but the package and what it requires');
    }

    private static function autoload(): string
    {
        foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $vendor => $loader) {
            if ($loader->findFile(GoogleProvider::class) && $loader->findFile(self::class)) {
                return $vendor.'/autoload.php';
            }
        }
        self::markTestSkipped('No autoloader knows omnimap/google\'s tests.');
    }
}
