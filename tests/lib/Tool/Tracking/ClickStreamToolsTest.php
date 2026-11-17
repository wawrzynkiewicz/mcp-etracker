<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Tests\McpEtracker\Tool\Tracking;

use Ibexa\Contracts\Mcp\Attribute\McpTool;
use Ibexa\Contracts\Mcp\ToolResult;
use Ibexa\McpEtracker\Stream\JsonFileStreamDataSource;
use Ibexa\McpEtracker\Stream\StreamService;
use Ibexa\McpEtracker\Tool\Tracking\ClickStreamTools;
use Ibexa\Tests\McpEtracker\Stream\JsonFileStreamDataSourceTest;
use PHPUnit\Framework\TestCase;

final class ClickStreamToolsTest extends TestCase
{
    private ClickStreamTools $tools;

    protected function setUp(): void
    {
        $this->tools = new ClickStreamTools(new StreamService(new JsonFileStreamDataSource([
            'test' => ['name' => 'Test account', 'data_file' => JsonFileStreamDataSourceTest::FIXTURE],
        ])));
    }

    public function testToolsAreRegisteredOnEtrackerServer(): void
    {
        $names = [];
        foreach ((new \ReflectionClass(ClickStreamTools::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(McpTool::class) as $attribute) {
                $tool = $attribute->newInstance();
                self::assertSame([ClickStreamTools::SERVER], $tool->servers);
                self::assertNotNull($tool->outputSchema);
                self::assertNotNull($tool->annotations);
                self::assertTrue($tool->annotations->readOnlyHint);
                $names[] = $tool->name;
            }
        }

        self::assertSame([
            'list_tracking_accounts',
            'get_click_stream',
            'get_channel_summary',
            'get_hourly_click_distribution',
            'get_website_page_statistics',
            'get_instagram_post_statistics',
        ], $names);
    }

    public function testListTrackingAccounts(): void
    {
        $data = $this->assertSuccess($this->tools->listTrackingAccounts());

        self::assertSame(['test'], array_column($data['accounts'], 'identifier'));
    }

    public function testGetClickStreamWithChannelAndTimeframe(): void
    {
        $data = $this->assertSuccess($this->tools->getClickStream(channel: 'website', timeframe: 'last_day'));

        self::assertSame('test', $data['account']);
        self::assertSame(['website'], $data['channels']);
        self::assertSame(['from' => '2026-01-03', 'to' => '2026-01-03', 'days' => 1], $data['timeframe']);
        self::assertCount(1, $data['days']);
        self::assertSame(400, $data['days'][0]['website']['pageviews']);
    }

    public function testGetChannelSummaryWithCustomRange(): void
    {
        $data = $this->assertSuccess($this->tools->getChannelSummary(channel: 'instagram', from: '2026-01-01', to: '2026-01-02'));

        self::assertSame(['instagram'], array_keys($data['channels']));
        self::assertSame(110, $data['channels']['instagram']['likes']);
    }

    public function testGetHourlyClickDistribution(): void
    {
        $data = $this->assertSuccess($this->tools->getHourlyClickDistribution(channel: 'all', timeframe: 'all'));

        self::assertSame(['website', 'instagram'], array_keys($data['channels']));
    }

    public function testGetWebsitePageStatistics(): void
    {
        $data = $this->assertSuccess($this->tools->getWebsitePageStatistics(timeframe: 'all', sortBy: 'clicks', limit: 1));

        self::assertSame(2, $data['totalCount']);
        self::assertSame('https://www.example.com/', $data['pages'][0]['url']);
    }

    public function testGetInstagramPostStatistics(): void
    {
        $data = $this->assertSuccess($this->tools->getInstagramPostStatistics(timeframe: 'all', mediaType: 'reel'));

        self::assertSame(1, $data['totalCount']);
        self::assertSame('SIM20260102001', $data['posts'][0]['shortcode']);
    }

    public function testErrorsAreReturnedAsFailureResult(): void
    {
        self::assertSame(
            ['success' => false, 'error' => 'Unknown tracking account "nope". Available accounts: test.'],
            $this->tools->getClickStream(account: 'nope')->jsonSerialize(),
        );
        self::assertFalse($this->tools->getChannelSummary(channel: 'tiktok')->success);
        self::assertFalse($this->tools->getWebsitePageStatistics(timeframe: 'last_year')->success);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertSuccess(ToolResult $result): array
    {
        self::assertTrue($result->success, (string) $result->error);
        self::assertIsArray($result->data);

        /** @var array<string, mixed> */
        return json_decode((string) json_encode($result->data), true);
    }
}
