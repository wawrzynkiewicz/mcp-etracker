<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\McpEtracker\Tool\Tracking;

use Ibexa\Contracts\Mcp\Attribute\McpTool;
use Ibexa\Contracts\Mcp\McpCapabilityInterface;
use Ibexa\Contracts\Mcp\ToolResult;
use Ibexa\Contracts\McpEtracker\Stream\Channel;
use Ibexa\Contracts\McpEtracker\Stream\StreamServiceInterface;
use Ibexa\Contracts\McpEtracker\Stream\Timeframe;
use Mcp\Schema\ToolAnnotations;

/**
 * MCP tools simulating the etracker analytics API: click streams of the tracked website and
 * Instagram channel of each configured account.
 */
final readonly class ClickStreamTools implements McpCapabilityInterface
{
    public const string SERVER = 'etracker';

    private const array TIMEFRAME_SCHEMA = [
        'type' => 'object',
        'description' => 'Resolved, inclusive timeframe (clipped to the tracked period).',
        'properties' => [
            'from' => ['type' => 'string', 'description' => 'First day (YYYY-MM-DD).'],
            'to' => ['type' => 'string', 'description' => 'Last day (YYYY-MM-DD).'],
            'days' => ['type' => 'integer', 'description' => 'Number of days in the timeframe.'],
        ],
        'required' => ['from', 'to', 'days'],
    ];

    private const array COUNTER_MAP_SCHEMA = [
        'type' => 'object',
        'additionalProperties' => ['type' => 'integer'],
    ];

    private const array WEBSITE_DAY_SCHEMA = [
        'type' => 'object',
        'description' => 'Website totals of the day.',
        'properties' => [
            'pageviews' => ['type' => 'integer'],
            'sessions' => ['type' => 'integer'],
            'uniqueVisitors' => ['type' => 'integer'],
            'clicks' => ['type' => 'integer', 'description' => 'Tracked clicks on links, CTAs, downloads, calculators etc.'],
            'bounceRate' => ['type' => 'number', 'description' => 'Share of single-page sessions (0-1).'],
            'avgSessionDurationSeconds' => ['type' => 'number'],
            'instagramReferrals' => ['type' => 'integer', 'description' => 'Sessions referred by Instagram.'],
        ],
        'required' => ['pageviews', 'sessions', 'uniqueVisitors', 'clicks', 'bounceRate', 'avgSessionDurationSeconds', 'instagramReferrals'],
    ];

    private const array INSTAGRAM_DAY_SCHEMA = [
        'type' => 'object',
        'description' => 'Instagram totals of the day, summed over all tracked posts.',
        'properties' => [
            'followers' => ['type' => 'integer', 'description' => 'Follower count at the end of the day.'],
            'postsPublished' => ['type' => 'integer'],
            'likes' => ['type' => 'integer', 'description' => 'New likes received on the day.'],
            'comments' => ['type' => 'integer', 'description' => 'New comments received on the day.'],
            'clicks' => ['type' => 'integer', 'description' => 'Profile visits + link-in-bio + tagged-account clicks.'],
            'impressions' => ['type' => 'integer'],
            'reach' => ['type' => 'integer'],
            'websiteClicks' => ['type' => 'integer', 'description' => 'Link-in-bio clicks to the website.'],
        ],
        'required' => ['followers', 'postsPublished', 'likes', 'comments', 'clicks', 'impressions', 'reach', 'websiteClicks'],
    ];

    private const array ACCOUNT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'identifier' => ['type' => 'string', 'description' => 'Account identifier to pass as "account" to the other tools.'],
            'name' => ['type' => 'string'],
            'isDefault' => ['type' => 'boolean', 'description' => 'True for the account used when "account" is omitted.'],
            'simulated' => ['type' => 'boolean', 'description' => 'True when the statistics are simulated.'],
            'timezone' => ['type' => 'string'],
            'trackedPeriod' => self::TIMEFRAME_SCHEMA,
            'channels' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'channel' => ['type' => 'string', 'enum' => ['website', 'instagram']],
                        'handle' => ['type' => 'string', 'description' => 'Domain or Instagram handle.'],
                        'url' => ['type' => 'string'],
                        'trackedItems' => ['type' => 'integer', 'description' => 'Number of tracked pages or posts.'],
                    ],
                    'required' => ['channel', 'handle', 'url', 'trackedItems'],
                ],
            ],
        ],
        'required' => ['identifier', 'name', 'isDefault', 'simulated', 'timezone', 'trackedPeriod', 'channels'],
    ];

    private const array ACCOUNT_LIST_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'accounts' => ['type' => 'array', 'items' => self::ACCOUNT_SCHEMA],
        ],
        'required' => ['accounts'],
    ];

    private const array CLICK_STREAM_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'account' => ['type' => 'string'],
            'channels' => ['type' => 'array', 'items' => ['type' => 'string']],
            'timeframe' => self::TIMEFRAME_SCHEMA,
            'days' => [
                'type' => 'array',
                'description' => 'One entry per day, oldest first. Only the requested channels are present.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'date' => ['type' => 'string'],
                        'weekday' => ['type' => 'string'],
                        'website' => self::WEBSITE_DAY_SCHEMA,
                        'instagram' => self::INSTAGRAM_DAY_SCHEMA,
                    ],
                    'required' => ['date', 'weekday'],
                ],
            ],
        ],
        'required' => ['account', 'channels', 'timeframe', 'days'],
    ];

    private const array SUMMARY_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'account' => ['type' => 'string'],
            'timeframe' => self::TIMEFRAME_SCHEMA,
            'channels' => [
                'type' => 'object',
                'description' => 'Aggregated totals keyed by channel.',
                'properties' => [
                    'website' => [
                        'type' => 'object',
                        'properties' => [
                            'pageviews' => ['type' => 'integer'],
                            'sessions' => ['type' => 'integer'],
                            'uniqueVisitors' => ['type' => 'integer', 'description' => 'Sum of daily unique visitors.'],
                            'clicks' => ['type' => 'integer'],
                            'bounceRate' => ['type' => 'number'],
                            'avgSessionDurationSeconds' => ['type' => 'number'],
                            'clicksPerPageview' => ['type' => 'number'],
                            'dailyAverage' => ['type' => 'object'],
                            'peakDay' => ['type' => ['object', 'null']],
                            'trafficSources' => self::COUNTER_MAP_SCHEMA,
                            'devices' => self::COUNTER_MAP_SCHEMA,
                            'instagramReferrals' => ['type' => 'integer'],
                        ],
                    ],
                    'instagram' => [
                        'type' => 'object',
                        'properties' => [
                            'likes' => ['type' => 'integer'],
                            'comments' => ['type' => 'integer'],
                            'clicks' => ['type' => 'integer'],
                            'impressions' => ['type' => 'integer'],
                            'reach' => ['type' => 'integer'],
                            'websiteClicks' => ['type' => 'integer'],
                            'postsPublished' => ['type' => 'integer'],
                            'engagementRate' => ['type' => 'number', 'description' => '(likes + comments) / reach.'],
                            'followers' => ['type' => 'object', 'description' => 'Follower count at start/end of the timeframe and the change.'],
                            'dailyAverage' => ['type' => 'object'],
                            'peakDay' => ['type' => ['object', 'null']],
                        ],
                    ],
                ],
            ],
        ],
        'required' => ['account', 'timeframe', 'channels'],
    ];

    private const array HOURLY_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'account' => ['type' => 'string'],
            'timeframe' => self::TIMEFRAME_SCHEMA,
            'channels' => [
                'type' => 'object',
                'description' => '24 hourly buckets per channel (website: clicks, instagram: likes) with their share of the total.',
                'additionalProperties' => [
                    'type' => 'array',
                    'items' => ['type' => 'object'],
                ],
            ],
        ],
        'required' => ['account', 'timeframe', 'channels'],
    ];

    private const array PAGE_STATISTICS_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'account' => ['type' => 'string'],
            'timeframe' => self::TIMEFRAME_SCHEMA,
            'totalCount' => ['type' => 'integer', 'description' => 'Number of matching pages (ignoring limit).'],
            'pages' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'section' => ['type' => 'string'],
                        'pageviews' => ['type' => 'integer'],
                        'uniquePageviews' => ['type' => 'integer'],
                        'clicks' => ['type' => 'integer'],
                        'clickThroughRate' => ['type' => 'number', 'description' => 'clicks / pageviews.'],
                        'clicksByTarget' => self::COUNTER_MAP_SCHEMA,
                        'avgTimeOnPageSeconds' => ['type' => 'number'],
                        'exitRate' => ['type' => 'number'],
                    ],
                    'required' => ['url', 'title', 'section', 'pageviews', 'clicks', 'clickThroughRate', 'clicksByTarget'],
                ],
            ],
        ],
        'required' => ['account', 'timeframe', 'totalCount', 'pages'],
    ];

    private const array POST_STATISTICS_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'account' => ['type' => 'string'],
            'timeframe' => self::TIMEFRAME_SCHEMA,
            'totalCount' => ['type' => 'integer', 'description' => 'Number of posts with activity in the timeframe (ignoring limit).'],
            'posts' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'shortcode' => ['type' => 'string'],
                        'url' => ['type' => ['string', 'null'], 'description' => 'Post URL; null for simulated posts.'],
                        'mediaType' => ['type' => 'string', 'enum' => ['photo', 'reel', 'carousel']],
                        'publishedAt' => ['type' => ['string', 'null']],
                        'pinned' => ['type' => 'boolean'],
                        'source' => ['type' => 'string', 'description' => 'observed | observed_date_estimated | simulated'],
                        'topic' => ['type' => ['string', 'null']],
                        'likes' => ['type' => 'integer', 'description' => 'Likes received within the timeframe.'],
                        'comments' => ['type' => 'integer', 'description' => 'Comments received within the timeframe.'],
                        'saves' => ['type' => 'integer'],
                        'shares' => ['type' => 'integer'],
                        'impressions' => ['type' => 'integer'],
                        'reach' => ['type' => 'integer'],
                        'plays' => ['type' => 'integer', 'description' => 'Reels only.'],
                        'clicks' => self::COUNTER_MAP_SCHEMA,
                        'activeDays' => ['type' => 'integer'],
                        'lifetime' => [
                            'type' => 'object',
                            'description' => 'Lifetime likes/comments as of the last day of the timeframe.',
                            'properties' => [
                                'asOf' => ['type' => 'string'],
                                'likes' => ['type' => 'integer'],
                                'comments' => ['type' => 'integer'],
                            ],
                        ],
                        'engagementRate' => ['type' => 'number', 'description' => '(likes + comments + saves + shares) / reach.'],
                    ],
                    'required' => ['shortcode', 'mediaType', 'likes', 'comments', 'clicks', 'lifetime', 'engagementRate'],
                ],
            ],
        ],
        'required' => ['account', 'timeframe', 'totalCount', 'posts'],
    ];

    public function __construct(
        private StreamServiceInterface $streamService,
    ) {
    }

    #[McpTool(
        servers: [self::SERVER],
        name: 'list_tracking_accounts',
        description: 'Lists the etracker tracking accounts with their channels (website domain, Instagram handle) and the tracked period. Call this first to learn valid account identifiers and dates.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        ),
        outputSchema: self::ACCOUNT_LIST_SCHEMA,
    )]
    public function listTrackingAccounts(): array
    {
        try {
            return ['accounts' => $this->streamService->getAccounts()];
        } catch (\Throwable $e) {
            return ['error' => $e];
        }
    }

    /**
     * @param string|null $account Tracking account identifier (see list_tracking_accounts). Defaults to the default account.
     * @param string $channel Channel: 'website' | 'instagram' | 'all'. Defaults to 'all'.
     * @param string $timeframe Preset counted back from the last tracked day: 'last_day' | 'last_7_days' | 'last_14_days' | 'last_30_days' | 'last_90_days' | 'all'. Ignored when from/to is given. Defaults to 'last_30_days'.
     * @param string|null $from Custom start date (YYYY-MM-DD, inclusive). Overrides timeframe.
     * @param string|null $to Custom end date (YYYY-MM-DD, inclusive). Overrides timeframe.
     */
    #[McpTool(
        servers: [self::SERVER],
        name: 'get_click_stream',
        description: 'Returns the daily click stream (one entry per day) of an account: website pageviews, sessions, visitors and clicks and/or Instagram likes, comments, clicks, reach and followers. Filter by channel and timeframe.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        ),
        outputSchema: self::CLICK_STREAM_SCHEMA,
    )]
    public function getClickStream(
        ?string $account = null,
        string $channel = Channel::ALL,
        string $timeframe = Timeframe::DEFAULT_PRESET,
        ?string $from = null,
        ?string $to = null,
    ): array {
        try {
            $channels = Channel::listFromParameter($channel);
            $accountId = $this->streamService->resolveAccount($account);
            $range = $this->streamService->resolveTimeframe($accountId, $timeframe, $from, $to);

            return [
                'account' => $accountId,
                'channels' => array_map(static fn (Channel $c): string => $c->value, $channels),
                'timeframe' => $range->toArray(),
                'days' => $this->streamService->getClickStream($accountId, $range, $channels),
            ];
        } catch (\Throwable $e) {
            return ['error' => $e];
        }
    }

    /**
     * @param string|null $account Tracking account identifier (see list_tracking_accounts). Defaults to the default account.
     * @param string $channel Channel: 'website' | 'instagram' | 'all'. Defaults to 'all'.
     * @param string $timeframe Preset counted back from the last tracked day: 'last_day' | 'last_7_days' | 'last_14_days' | 'last_30_days' | 'last_90_days' | 'all'. Ignored when from/to is given. Defaults to 'last_30_days'.
     * @param string|null $from Custom start date (YYYY-MM-DD, inclusive). Overrides timeframe.
     * @param string|null $to Custom end date (YYYY-MM-DD, inclusive). Overrides timeframe.
     */
    #[McpTool(
        servers: [self::SERVER],
        name: 'get_channel_summary',
        description: 'Aggregates the click stream over a timeframe per channel: website totals, bounce rate, traffic sources, devices and peak day; Instagram likes, comments, clicks, reach, engagement rate and follower growth.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        ),
        outputSchema: self::SUMMARY_SCHEMA,
    )]
    public function getChannelSummary(
        ?string $account = null,
        string $channel = Channel::ALL,
        string $timeframe = Timeframe::DEFAULT_PRESET,
        ?string $from = null,
        ?string $to = null,
    ): array {
        try {
            $channels = Channel::listFromParameter($channel);
            $accountId = $this->streamService->resolveAccount($account);
            $range = $this->streamService->resolveTimeframe($accountId, $timeframe, $from, $to);

            return [
                'account' => $accountId,
                'timeframe' => $range->toArray(),
                'channels' => $this->streamService->getChannelSummary($accountId, $range, $channels),
            ];
        } catch (\Throwable $e) {
            return ['error' => $e];
        }
    }

    /**
     * @param string|null $account Tracking account identifier (see list_tracking_accounts). Defaults to the default account.
     * @param string $channel Channel: 'website' | 'instagram' | 'all'. Defaults to 'all'.
     * @param string $timeframe Preset counted back from the last tracked day: 'last_day' | 'last_7_days' | 'last_14_days' | 'last_30_days' | 'last_90_days' | 'all'. Ignored when from/to is given. Defaults to 'last_30_days'.
     * @param string|null $from Custom start date (YYYY-MM-DD, inclusive). Overrides timeframe.
     * @param string|null $to Custom end date (YYYY-MM-DD, inclusive). Overrides timeframe.
     */
    #[McpTool(
        servers: [self::SERVER],
        name: 'get_hourly_click_distribution',
        description: 'Returns the distribution over the 24 hours of the day (Europe/Berlin), summed over the timeframe: website clicks and/or Instagram likes per hour, with each hour\'s share.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        ),
        outputSchema: self::HOURLY_SCHEMA,
    )]
    public function getHourlyClickDistribution(
        ?string $account = null,
        string $channel = Channel::ALL,
        string $timeframe = Timeframe::DEFAULT_PRESET,
        ?string $from = null,
        ?string $to = null,
    ): array {
        try {
            $channels = Channel::listFromParameter($channel);
            $accountId = $this->streamService->resolveAccount($account);
            $range = $this->streamService->resolveTimeframe($accountId, $timeframe, $from, $to);

            return [
                'account' => $accountId,
                'timeframe' => $range->toArray(),
                'channels' => $this->streamService->getHourlyDistribution($accountId, $range, $channels),
            ];
        } catch (\Throwable $e) {
            return ['error' => $e];
        }
    }

    /**
     * @param string|null $account Tracking account identifier (see list_tracking_accounts). Defaults to the default account.
     * @param string $timeframe Preset counted back from the last tracked day: 'last_day' | 'last_7_days' | 'last_14_days' | 'last_30_days' | 'last_90_days' | 'all'. Ignored when from/to is given. Defaults to 'last_30_days'.
     * @param string|null $from Custom start date (YYYY-MM-DD, inclusive). Overrides timeframe.
     * @param string|null $to Custom end date (YYYY-MM-DD, inclusive). Overrides timeframe.
     * @param string|null $section Only pages of this site section, e.g. 'foerderung', 'privatpersonen', 'unternehmen', 'gruender', 'service', 'presse', 'nrw-bank', 'home'.
     * @param string $sortBy Sort (descending) by: 'pageviews' | 'clicks' | 'ctr' | 'timeOnPage'. Defaults to 'pageviews'.
     * @param int $limit Maximum number of pages to return (1-200). Defaults to 25.
     */
    #[McpTool(
        servers: [self::SERVER],
        name: 'get_website_page_statistics',
        description: 'Returns per-page website statistics aggregated over a timeframe: pageviews, unique pageviews, clicks, click-through rate, clicks by target (navigation, CTA, PDF download, calculator, contact, external link), time on page and exit rate.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        ),
        outputSchema: self::PAGE_STATISTICS_SCHEMA,
    )]
    public function getWebsitePageStatistics(
        ?string $account = null,
        string $timeframe = Timeframe::DEFAULT_PRESET,
        ?string $from = null,
        ?string $to = null,
        ?string $section = null,
        string $sortBy = 'pageviews',
        int $limit = 25,
    ): array {
        try {
            $accountId = $this->streamService->resolveAccount($account);
            $range = $this->streamService->resolveTimeframe($accountId, $timeframe, $from, $to);

            return [
                'account' => $accountId,
                'timeframe' => $range->toArray(),
            ] + $this->streamService->getWebsitePageStatistics($accountId, $range, $section, $sortBy, $limit);
        } catch (\Throwable $e) {
            return ['error' => $e];
        }
    }

    /**
     * @param string|null $account Tracking account identifier (see list_tracking_accounts). Defaults to the default account.
     * @param string $timeframe Preset counted back from the last tracked day: 'last_day' | 'last_7_days' | 'last_14_days' | 'last_30_days' | 'last_90_days' | 'all'. Ignored when from/to is given. Defaults to 'last_30_days'.
     * @param string|null $from Custom start date (YYYY-MM-DD, inclusive). Overrides timeframe.
     * @param string|null $to Custom end date (YYYY-MM-DD, inclusive). Overrides timeframe.
     * @param string|null $mediaType Only posts of this type: 'photo' | 'reel' | 'carousel'.
     * @param string $sortBy Sort (descending) by: 'likes' | 'comments' | 'clicks' | 'impressions' | 'engagement'. Defaults to 'likes'.
     * @param int $limit Maximum number of posts to return (1-200). Defaults to 25.
     */
    #[McpTool(
        servers: [self::SERVER],
        name: 'get_instagram_post_statistics',
        description: 'Returns per-post Instagram statistics aggregated over a timeframe: likes and comments received, saves, shares, impressions, reach, plays (reels), clicks (profile visits, link in bio, tagged account), engagement rate and lifetime likes/comments.',
        annotations: new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        ),
        outputSchema: self::POST_STATISTICS_SCHEMA,
    )]
    public function getInstagramPostStatistics(
        ?string $account = null,
        string $timeframe = Timeframe::DEFAULT_PRESET,
        ?string $from = null,
        ?string $to = null,
        ?string $mediaType = null,
        string $sortBy = 'likes',
        int $limit = 25,
    ): array {
        try {
            $accountId = $this->streamService->resolveAccount($account);
            $range = $this->streamService->resolveTimeframe($accountId, $timeframe, $from, $to);

            return [
                'account' => $accountId,
                'timeframe' => $range->toArray(),
            ] + $this->streamService->getInstagramPostStatistics($accountId, $range, $mediaType, $sortBy, $limit);
        } catch (\Throwable $e) {
            return ['error' => $e];
        }
    }
}
