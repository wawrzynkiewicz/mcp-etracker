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
use Ibexa\McpEtracker\Stream\JsonFileStreamDataSource;
use Ibexa\McpEtracker\Stream\StreamService;
use PHPUnit\Framework\TestCase;

final class StreamServiceTest extends TestCase
{
    private StreamService $service;

    private Timeframe $all;

    protected function setUp(): void
    {
        $this->service = new StreamService(new JsonFileStreamDataSource([
            'test' => ['name' => 'Test account', 'data_file' => JsonFileStreamDataSourceTest::FIXTURE],
        ]));
        $this->all = Timeframe::fromStrings('2026-01-01', '2026-01-03');
    }

    public function testGetAccount(): void
    {
        $account = $this->service->getAccount();

        self::assertSame('test', $account['identifier']);
        self::assertSame('Test account', $account['name']);
        self::assertTrue($account['isDefault']);
        self::assertTrue($account['simulated']);
        self::assertSame(['from' => '2026-01-01', 'to' => '2026-01-03', 'days' => 3], $account['trackedPeriod']);
        self::assertSame(
            [
                ['channel' => 'website', 'handle' => 'www.example.com', 'url' => 'https://www.example.com', 'trackedItems' => 2],
                ['channel' => 'instagram', 'handle' => '@example', 'url' => 'https://www.instagram.com/example/', 'trackedItems' => 2],
            ],
            $account['channels'],
        );
    }

    public function testResolveTimeframe(): void
    {
        self::assertSame(['from' => '2026-01-03', 'to' => '2026-01-03', 'days' => 1], $this->service->resolveTimeframe(null, 'last_day')->toArray());
        // Presets are clipped to the tracked period.
        self::assertSame(['from' => '2026-01-01', 'to' => '2026-01-03', 'days' => 3], $this->service->resolveTimeframe('test', 'last_7_days')->toArray());
        // Explicit bounds override the preset; a missing bound defaults to the tracked period.
        self::assertSame(['from' => '2026-01-02', 'to' => '2026-01-03', 'days' => 2], $this->service->resolveTimeframe(null, 'last_day', '2026-01-02')->toArray());
        self::assertSame(['from' => '2026-01-01', 'to' => '2026-01-01', 'days' => 1], $this->service->resolveTimeframe(null, null, null, '2026-01-01')->toArray());
    }

    public function testResolveTimeframeOutsideTrackedPeriodThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lies outside the tracked period');

        $this->service->resolveTimeframe(null, null, '2025-01-01', '2025-01-31');
    }

    public function testGetClickStreamFiltersChannelAndTimeframe(): void
    {
        $days = $this->service->getClickStream(null, Timeframe::fromStrings('2026-01-02', '2026-01-03'), [Channel::Instagram]);

        self::assertSame(['2026-01-02', '2026-01-03'], array_column($days, 'date'));
        self::assertArrayNotHasKey('website', $days[0]);
        self::assertSame(
            ['followers' => 1001, 'postsPublished' => 1, 'likes' => 70, 'comments' => 6, 'clicks' => 6, 'impressions' => 700, 'reach' => 420, 'websiteClicks' => 2],
            $days[0]['instagram'],
        );
    }

    public function testGetChannelSummary(): void
    {
        $summary = $this->service->getChannelSummary(null, $this->all, Channel::cases());

        $website = $summary['website'];
        self::assertSame(2200, $website['pageviews']);
        self::assertSame(1100, $website['sessions']);
        self::assertSame(2700, $website['clicks']);
        self::assertSame(0.4, $website['bounceRate']);
        self::assertSame(['date' => '2026-01-01', 'clicks' => 1200], $website['peakDay']);
        self::assertSame(['organicSearch' => 550, 'direct' => 550], $website['trafficSources']);
        self::assertSame(15, $website['instagramReferrals']);

        $instagram = $summary['instagram'];
        self::assertSame(135, $instagram['likes']);
        self::assertSame(12, $instagram['comments']);
        self::assertSame(1, $instagram['postsPublished']);
        self::assertSame(['start' => 1000, 'end' => 1002, 'change' => 2], $instagram['followers']);
        self::assertSame(['date' => '2026-01-02', 'likes' => 70], $instagram['peakDay']);
    }

    public function testGetHourlyDistribution(): void
    {
        $hourly = $this->service->getHourlyDistribution(null, $this->all, Channel::cases());

        self::assertCount(24, $hourly['website']);
        self::assertSame(['hour' => 10, 'clicks' => 2700, 'share' => 1.0], $hourly['website'][10]);
        self::assertSame(['hour' => 20, 'likes' => 135, 'share' => 1.0], $hourly['instagram'][20]);
        self::assertSame(0, $hourly['instagram'][0]['likes']);
    }

    public function testGetWebsitePageStatistics(): void
    {
        $result = $this->service->getWebsitePageStatistics(null, $this->all, null, 'ctr');

        self::assertSame(2, $result['totalCount']);
        $conditions = $result['pages'][0];
        self::assertSame('https://www.example.com/conditions/', $conditions['url']);
        self::assertSame(800, $conditions['pageviews']);
        self::assertSame(1050, $conditions['clicks']);
        self::assertSame(1.3125, $conditions['clickThroughRate']);
        self::assertSame(['navigation' => 1020, 'downloadPdf' => 30], $conditions['clicksByTarget']);
        self::assertSame(60.0, $conditions['avgTimeOnPageSeconds']);

        $filtered = $this->service->getWebsitePageStatistics(null, $this->all, 'home', 'pageviews', 1);
        self::assertSame(1, $filtered['totalCount']);
        self::assertSame(1400, $filtered['pages'][0]['pageviews']);
    }

    public function testGetInstagramPostStatistics(): void
    {
        $result = $this->service->getInstagramPostStatistics(null, $this->all);

        self::assertSame(2, $result['totalCount']);
        [$reel, $photo] = $result['posts'];

        self::assertSame('SIM20260102001', $reel['shortcode']);
        self::assertNull($reel['url']);
        self::assertSame('simulated', $reel['source']);
        self::assertSame('Event-Recap', $reel['topic']);
        self::assertSame('2026-01-02', $reel['publishedAt']);
        self::assertSame(70, $reel['likes']);
        self::assertSame(7, $reel['comments']);
        self::assertSame(840, $reel['plays']);
        self::assertSame(['asOf' => '2026-01-03', 'likes' => 70, 'comments' => 4], $reel['lifetime']);
        self::assertSame(['profileVisits' => 4, 'linkInBio' => 2, 'taggedAccount' => 0, 'total' => 6], $reel['clicks']);

        self::assertSame('AAA', $photo['shortcode']);
        self::assertSame(65, $photo['likes']);
        self::assertSame(5, $photo['comments']);
        self::assertSame(3, $photo['activeDays']);
        self::assertArrayNotHasKey('plays', $photo);

        $photos = $this->service->getInstagramPostStatistics(null, $this->all, 'photo', 'comments');
        self::assertSame(['AAA'], array_column($photos['posts'], 'shortcode'));
    }

    public function testUnsupportedSortFieldThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->getInstagramPostStatistics(null, $this->all, null, 'followers');
    }
}
