<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Tests\McpEtracker\Stream;

use Ibexa\Contracts\McpEtracker\Exception\InvalidArgumentException;
use Ibexa\Contracts\McpEtracker\Stream\Channel;
use Ibexa\Contracts\McpEtracker\Stream\Timeframe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TimeframeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function providePresets(): iterable
    {
        yield 'last day' => ['last_day', '2026-11-16', '2026-11-16'];
        yield 'last 7 days' => ['last_7_days', '2026-11-10', '2026-11-16'];
        yield 'last 30 days' => ['last_30_days', '2026-10-18', '2026-11-16'];
        yield 'all' => ['all', '2026-08-17', '2026-11-16'];
        yield 'case-insensitive' => [' LAST_14_DAYS ', '2026-11-03', '2026-11-16'];
    }

    #[DataProvider('providePresets')]
    public function testFromPreset(string $preset, string $expectedFrom, string $expectedTo): void
    {
        $timeframe = Timeframe::fromPreset($preset, new \DateTimeImmutable('2026-11-16'), new \DateTimeImmutable('2026-08-17'));

        self::assertSame($expectedFrom, $timeframe->getFromString());
        self::assertSame($expectedTo, $timeframe->getToString());
    }

    public function testUnknownPresetThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported timeframe "last_year"');

        Timeframe::fromPreset('last_year', new \DateTimeImmutable(), new \DateTimeImmutable());
    }

    public function testFromAfterToThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Timeframe::fromStrings('2026-11-10', '2026-11-01');
    }

    public function testInvalidDateThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid date "yesterday-ish"');

        Timeframe::parseDate('yesterday-ish');
    }

    public function testIntersectContainsAndDays(): void
    {
        $a = Timeframe::fromStrings('2026-01-01', '2026-01-10');
        $b = Timeframe::fromStrings('2026-01-05 13:45', '2026-01-20');

        $intersection = $a->intersect($b);
        self::assertNotNull($intersection);
        self::assertSame(['from' => '2026-01-05', 'to' => '2026-01-10', 'days' => 6], $intersection->toArray());
        self::assertTrue($intersection->contains('2026-01-05'));
        self::assertFalse($intersection->contains('2026-01-11'));
        self::assertNull($a->intersect(Timeframe::fromStrings('2026-02-01', '2026-02-02')));
    }

    public function testChannelListFromParameter(): void
    {
        self::assertSame(Channel::cases(), Channel::listFromParameter(null));
        self::assertSame(Channel::cases(), Channel::listFromParameter('ALL'));
        self::assertSame([Channel::Instagram], Channel::listFromParameter(' Instagram '));

        $this->expectException(InvalidArgumentException::class);
        Channel::listFromParameter('tiktok');
    }
}
