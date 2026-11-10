<?php

namespace Omnimap\Google\Tests;

use Omnimap\Google\GoogleFactory;
use Omnimap\Google\GoogleProvider;
use Omnimap\Registry;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\PolicyExport;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;

/** The Google Maps Platform terms as policy.json declares them. */
final class PolicyTest extends TestCase
{
    public function testThePolicyFileReadsAsGooglesTermsSayThem(): void
    {
        $policy = UsagePolicy::fromFile(GoogleProvider::POLICY);
        $declared = (new PolicyExport())->folders(\dirname(__DIR__))->entries();

        self::assertSame(['package' => 'omnimap/google', 'version' => null, 'file' => 'policy.json', 'policy' => $policy->toArray()], $declared[0] ?? null, 'composer.json names it (extra.omnisense.policy)');
        self::assertSame('google', $policy->provider);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertNull($policy->rateLimits, 'quotas are the Cloud project\'s, no default published');
        self::assertTrue($policy->requirements->key);
        self::assertSame(Permission::Forbidden, $policy->rights->caching->permission);
        self::assertSame(Permission::Forbidden, $policy->rights->storage);
        self::assertSame('Google Maps', $policy->rights->attribution->text);

        $provider = (new GoogleFactory())->create(['api_key' => 'browser-key']);
        self::assertEquals($policy, $provider->capabilities()->policy());
    }

    public function testNoAnswerMayBeCachedByTheGuard(): void
    {
        $registry = new Registry([new GoogleFactory()], ['maps' => ['factory' => 'google', 'options' => ['api_key' => 'browser-key']]]);

        self::assertSame(0, $registry->guard('maps')->cacheTtl(3600));
        self::assertFalse($registry->guard('maps')->mayCache());
    }
}
