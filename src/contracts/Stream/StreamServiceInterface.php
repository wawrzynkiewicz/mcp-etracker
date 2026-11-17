<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Contracts\McpEtracker\Stream;

/**
 * Read API over the (simulated) etracker click stream of the configured accounts.
 *
 * All methods accept a null account, which resolves to the configured default account.
 * Results are plain arrays with camelCase keys, ready to be serialized.
 */
interface StreamServiceInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function getAccounts(): array;

    /**
     * @return array<string, mixed>
     */
    public function getAccount(?string $account = null): array;

    /**
     * Resolves the account identifier (null = default account).
     */
    public function resolveAccount(?string $account = null): string;

    /**
     * Resolves a timeframe for an account. Explicit $from / $to take precedence over the preset;
     * a missing bound is filled with the first / last tracked day. Presets are counted backwards
     * from the last tracked day. The result is clipped to the tracked period.
     */
    public function resolveTimeframe(
        ?string $account = null,
        ?string $preset = null,
        ?string $from = null,
        ?string $to = null,
    ): Timeframe;

    /**
     * Daily totals per channel.
     *
     * @param list<\Ibexa\Contracts\McpEtracker\Stream\Channel> $channels
     *
     * @return list<array<string, mixed>>
     */
    public function getClickStream(?string $account, Timeframe $timeframe, array $channels): array;

    /**
     * Aggregated totals per channel over the timeframe.
     *
     * @param list<\Ibexa\Contracts\McpEtracker\Stream\Channel> $channels
     *
     * @return array<string, array<string, mixed>>
     */
    public function getChannelSummary(?string $account, Timeframe $timeframe, array $channels): array;

    /**
     * Click / like distribution over the hours of the day, summed over the timeframe.
     *
     * @param list<\Ibexa\Contracts\McpEtracker\Stream\Channel> $channels
     *
     * @return array<string, list<array<string, int|float>>>
     */
    public function getHourlyDistribution(?string $account, Timeframe $timeframe, array $channels): array;

    /**
     * Website page statistics aggregated over the timeframe.
     *
     * @param string $sortBy 'pageviews' | 'clicks' | 'ctr' | 'timeOnPage'
     *
     * @return array{totalCount: int, pages: list<array<string, mixed>>}
     */
    public function getWebsitePageStatistics(
        ?string $account,
        Timeframe $timeframe,
        ?string $section = null,
        string $sortBy = 'pageviews',
        int $limit = 25,
    ): array;

    /**
     * Instagram post statistics (likes, comments, clicks, …) aggregated over the timeframe.
     *
     * @param string $sortBy 'likes' | 'comments' | 'clicks' | 'impressions' | 'engagement'
     *
     * @return array{totalCount: int, posts: list<array<string, mixed>>}
     */
    public function getInstagramPostStatistics(
        ?string $account,
        Timeframe $timeframe,
        ?string $mediaType = null,
        string $sortBy = 'likes',
        int $limit = 25,
    ): array;
}
