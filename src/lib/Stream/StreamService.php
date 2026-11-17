<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\McpEtracker\Stream;

use Ibexa\Contracts\McpEtracker\Exception\InvalidArgumentException;
use Ibexa\Contracts\McpEtracker\Exception\StreamDataException;
use Ibexa\Contracts\McpEtracker\Stream\Channel;
use Ibexa\Contracts\McpEtracker\Stream\StreamDataSourceInterface;
use Ibexa\Contracts\McpEtracker\Stream\StreamServiceInterface;
use Ibexa\Contracts\McpEtracker\Stream\Timeframe;

final readonly class StreamService implements StreamServiceInterface
{
    public const int MAX_LIMIT = 200;

    private const array PAGE_SORT_FIELDS = ['pageviews', 'clicks', 'ctr', 'timeOnPage'];

    private const array POST_SORT_FIELDS = ['likes', 'comments', 'clicks', 'impressions', 'engagement'];

    public function __construct(
        private StreamDataSourceInterface $dataSource,
    ) {
    }

    public function getAccounts(): array
    {
        return array_map(
            fn (string $account): array => $this->getAccount($account),
            $this->dataSource->getAccountIdentifiers(),
        );
    }

    public function getAccount(?string $account = null): array
    {
        $account = $this->resolveAccount($account);
        $stream = $this->dataSource->load($account);
        $meta = $stream['meta'];
        $sources = self::arr($meta['sources'] ?? []);
        $website = self::arr($sources['website'] ?? []);
        $instagram = self::arr($sources['instagram'] ?? []);
        [$first, $last] = $this->getTrackedPeriod($stream['days']);

        return [
            'identifier' => $account,
            'name' => $this->dataSource->getAccountName($account),
            'isDefault' => $account === $this->dataSource->getDefaultAccountIdentifier(),
            'simulated' => (bool)($meta['simulated'] ?? false),
            'timezone' => self::str($meta['timezone'] ?? 'Europe/Berlin'),
            'trackedPeriod' => (new Timeframe($first, $last))->toArray(),
            'channels' => [
                [
                    'channel' => Channel::Website->value,
                    'handle' => self::str($website['domain'] ?? ''),
                    'url' => 'https://' . self::str($website['domain'] ?? ''),
                    'trackedItems' => self::int($website['tracked_pages'] ?? 0),
                ],
                [
                    'channel' => Channel::Instagram->value,
                    'handle' => self::str($instagram['account'] ?? ''),
                    'url' => self::str($instagram['profile_url'] ?? ''),
                    'trackedItems' => count(self::arr($instagram['posts'] ?? [])),
                ],
            ],
        ];
    }

    public function resolveAccount(?string $account = null): string
    {
        if ($account === null || trim($account) === '') {
            return $this->dataSource->getDefaultAccountIdentifier();
        }

        // Validates the identifier (throws AccountNotFoundException when unknown).
        $this->dataSource->getAccountName($account);

        return $account;
    }

    public function resolveTimeframe(
        ?string $account = null,
        ?string $preset = null,
        ?string $from = null,
        ?string $to = null,
    ): Timeframe {
        $stream = $this->dataSource->load($this->resolveAccount($account));
        [$first, $last] = $this->getTrackedPeriod($stream['days']);
        $tracked = new Timeframe($first, $last);

        $hasFrom = $from !== null && trim($from) !== '';
        $hasTo = $to !== null && trim($to) !== '';

        if ($hasFrom || $hasTo) {
            $requested = new Timeframe(
                $hasFrom ? Timeframe::parseDate($from) : $first,
                $hasTo ? Timeframe::parseDate($to) : $last,
            );
        } else {
            $requested = Timeframe::fromPreset($preset ?? Timeframe::DEFAULT_PRESET, $last, $first);
        }

        $timeframe = $requested->intersect($tracked);
        if ($timeframe === null) {
            throw new InvalidArgumentException(sprintf(
                'The requested timeframe %s – %s lies outside the tracked period %s – %s.',
                $requested->getFromString(),
                $requested->getToString(),
                $tracked->getFromString(),
                $tracked->getToString(),
            ));
        }

        return $timeframe;
    }

    public function getClickStream(?string $account, Timeframe $timeframe, array $channels): array
    {
        $result = [];
        foreach ($this->getDays($account, $timeframe) as $day) {
            $row = ['date' => self::str($day['date']), 'weekday' => self::str($day['weekday'] ?? '')];
            foreach ($channels as $channel) {
                $data = self::arr($day[$channel->value] ?? []);
                $row[$channel->value] = match ($channel) {
                    Channel::Website => $this->mapWebsiteDay(self::arr($data['totals'] ?? []), self::arr($data['social_referrals'] ?? [])),
                    Channel::Instagram => $this->mapInstagramDay(self::arr($data['totals'] ?? [])),
                };
            }
            $result[] = $row;
        }

        return $result;
    }

    public function getChannelSummary(?string $account, Timeframe $timeframe, array $channels): array
    {
        $days = $this->getDays($account, $timeframe);
        $summary = [];
        foreach ($channels as $channel) {
            $summary[$channel->value] = match ($channel) {
                Channel::Website => $this->summarizeWebsite($days),
                Channel::Instagram => $this->summarizeInstagram($days),
            };
        }

        return $summary;
    }

    public function getHourlyDistribution(?string $account, Timeframe $timeframe, array $channels): array
    {
        $days = $this->getDays($account, $timeframe);
        $result = [];
        foreach ($channels as $channel) {
            [$listKey, $valueKey, $outputKey] = match ($channel) {
                Channel::Website => ['clicks_by_hour', 'clicks', 'clicks'],
                Channel::Instagram => ['interactions_by_hour', 'likes', 'likes'],
            };

            $buckets = array_fill(0, 24, 0);
            foreach ($days as $day) {
                foreach (self::arr(self::arr($day[$channel->value] ?? [])[$listKey] ?? []) as $entry) {
                    $entry = self::arr($entry);
                    $hour = self::int($entry['hour'] ?? -1);
                    if ($hour >= 0 && $hour < 24) {
                        $buckets[$hour] += self::int($entry[$valueKey] ?? 0);
                    }
                }
            }

            $total = array_sum($buckets);
            $result[$channel->value] = array_map(
                static fn (int $hour, int $value): array => [
                    'hour' => $hour,
                    $outputKey => $value,
                    'share' => $total > 0 ? round($value / $total, 4) : 0.0,
                ],
                array_keys($buckets),
                $buckets,
            );
        }

        return $result;
    }

    public function getWebsitePageStatistics(
        ?string $account,
        Timeframe $timeframe,
        ?string $section = null,
        string $sortBy = 'pageviews',
        int $limit = 25,
    ): array {
        if (!in_array($sortBy, self::PAGE_SORT_FIELDS, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported sort field "%s". Use one of: %s.', $sortBy, implode(', ', self::PAGE_SORT_FIELDS)));
        }

        $section = $section !== null && trim($section) !== '' ? strtolower(trim($section)) : null;

        /** @var array<string, array<string, mixed>> $pages */
        $pages = [];
        foreach ($this->getDays($account, $timeframe) as $day) {
            foreach (self::arr(self::arr($day['website'] ?? [])['pages'] ?? []) as $page) {
                $page = self::arr($page);
                $url = self::str($page['url'] ?? '');
                if ($url === '' || ($section !== null && strtolower(self::str($page['section'] ?? '')) !== $section)) {
                    continue;
                }

                $pageviews = self::int($page['pageviews'] ?? 0);
                $agg = $pages[$url] ?? [
                    'url' => $url,
                    'title' => self::str($page['title'] ?? ''),
                    'section' => self::str($page['section'] ?? ''),
                    'pageviews' => 0,
                    'uniquePageviews' => 0,
                    'clicks' => 0,
                    'clicksByTarget' => [],
                    'weightedTimeOnPage' => 0.0,
                    'weightedExitRate' => 0.0,
                ];
                $agg['pageviews'] = self::int($agg['pageviews']) + $pageviews;
                $agg['uniquePageviews'] = self::int($agg['uniquePageviews']) + self::int($page['unique_pageviews'] ?? 0);
                $agg['clicks'] = self::int($agg['clicks']) + self::int($page['clicks'] ?? 0);
                $agg['clicksByTarget'] = self::sumMaps(self::arr($agg['clicksByTarget']), self::camelKeys(self::arr($page['clicks_by_target'] ?? [])));
                $agg['weightedTimeOnPage'] = self::float($agg['weightedTimeOnPage']) + $pageviews * self::float($page['avg_time_on_page_s'] ?? 0);
                $agg['weightedExitRate'] = self::float($agg['weightedExitRate']) + $pageviews * self::float($page['exit_rate'] ?? 0);
                $pages[$url] = $agg;
            }
        }

        $result = [];
        foreach ($pages as $agg) {
            $pageviews = self::int($agg['pageviews']);
            $clicks = self::int($agg['clicks']);
            $result[] = [
                'url' => $agg['url'],
                'title' => $agg['title'],
                'section' => $agg['section'],
                'pageviews' => $pageviews,
                'uniquePageviews' => $agg['uniquePageviews'],
                'clicks' => $clicks,
                'clickThroughRate' => self::ratio($clicks, $pageviews),
                'clicksByTarget' => $agg['clicksByTarget'],
                'avgTimeOnPageSeconds' => $pageviews > 0 ? round(self::float($agg['weightedTimeOnPage']) / $pageviews, 1) : 0.0,
                'exitRate' => $pageviews > 0 ? round(self::float($agg['weightedExitRate']) / $pageviews, 3) : 0.0,
            ];
        }

        $sortKey = match ($sortBy) {
            'ctr' => 'clickThroughRate',
            'timeOnPage' => 'avgTimeOnPageSeconds',
            default => $sortBy,
        };
        usort($result, static fn (array $a, array $b): int => $b[$sortKey] <=> $a[$sortKey]);

        return ['totalCount' => count($result), 'pages' => array_slice($result, 0, self::normalizeLimit($limit))];
    }

    public function getInstagramPostStatistics(
        ?string $account,
        Timeframe $timeframe,
        ?string $mediaType = null,
        string $sortBy = 'likes',
        int $limit = 25,
    ): array {
        if (!in_array($sortBy, self::POST_SORT_FIELDS, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported sort field "%s". Use one of: %s.', $sortBy, implode(', ', self::POST_SORT_FIELDS)));
        }

        $mediaType = $mediaType !== null && trim($mediaType) !== '' ? strtolower(trim($mediaType)) : null;
        $catalog = $this->getPostCatalog($account);

        /** @var array<string, array<string, mixed>> $posts */
        $posts = [];
        foreach ($this->getDays($account, $timeframe) as $day) {
            $date = self::str($day['date']);
            foreach (self::arr(self::arr($day['instagram'] ?? [])['posts'] ?? []) as $post) {
                $post = self::arr($post);
                $code = self::str($post['shortcode'] ?? '');
                $type = self::str($post['media_type'] ?? '');
                if ($code === '' || ($mediaType !== null && $type !== $mediaType)) {
                    continue;
                }

                $clicks = self::arr($post['clicks'] ?? []);
                $info = $catalog[$code] ?? [];
                $agg = $posts[$code] ?? [
                    'shortcode' => $code,
                    'url' => isset($post['url']) ? self::str($post['url']) : null,
                    'mediaType' => $type,
                    'publishedAt' => isset($info['published']) ? self::str($info['published']) : null,
                    'pinned' => (bool)($info['pinned'] ?? false),
                    'source' => self::str($post['source'] ?? $info['source'] ?? 'observed'),
                    'topic' => isset($info['topic']) ? self::str($info['topic']) : null,
                    'likes' => 0,
                    'comments' => 0,
                    'saves' => 0,
                    'shares' => 0,
                    'impressions' => 0,
                    'reach' => 0,
                    'plays' => null,
                    'clicks' => [],
                    'activeDays' => 0,
                ];

                foreach (['likes', 'comments', 'saves', 'shares', 'impressions', 'reach'] as $metric) {
                    $agg[$metric] = self::int($agg[$metric]) + self::int($post[$metric] ?? 0);
                }
                if (isset($post['plays'])) {
                    $agg['plays'] = self::int($agg['plays'] ?? 0) + self::int($post['plays']);
                }
                $agg['clicks'] = self::sumMaps(self::arr($agg['clicks']), [
                    'profileVisits' => $clicks['profile_visits'] ?? 0,
                    'linkInBio' => $clicks['link_in_bio'] ?? 0,
                    'taggedAccount' => $clicks['tagged_account'] ?? 0,
                ]);
                $agg['activeDays'] = self::int($agg['activeDays']) + 1;
                $agg['lifetime'] = [
                    'asOf' => $date,
                    'likes' => self::int($post['likes_total'] ?? 0),
                    'comments' => self::int($post['comments_total'] ?? 0),
                ];
                $posts[$code] = $agg;
            }
        }

        $result = [];
        foreach ($posts as $agg) {
            $clicks = self::arr($agg['clicks']);
            $agg['clicks']['total'] = array_sum(array_map(self::int(...), $clicks));
            $agg['engagementRate'] = self::ratio(
                self::int($agg['likes']) + self::int($agg['comments']) + self::int($agg['saves']) + self::int($agg['shares']),
                self::int($agg['reach']),
            );
            if ($agg['plays'] === null) {
                unset($agg['plays']);
            }
            $result[] = $agg;
        }

        usort($result, static function (array $a, array $b) use ($sortBy): int {
            $value = static fn (array $post): int|float => match ($sortBy) {
                'clicks' => self::int(self::arr($post['clicks'])['total'] ?? 0),
                'engagement' => self::float($post['engagementRate']),
                default => self::int($post[$sortBy]),
            };

            return $value($b) <=> $value($a);
        });

        return ['totalCount' => count($result), 'posts' => array_slice($result, 0, self::normalizeLimit($limit))];
    }

    /**
     * @param list<array<string, mixed>> $days
     *
     * @return array<string, mixed>
     */
    private function summarizeWebsite(array $days): array
    {
        $totals = ['pageviews' => 0, 'sessions' => 0, 'uniqueVisitors' => 0, 'clicks' => 0];
        $weightedBounce = 0.0;
        $weightedDuration = 0.0;
        $sources = [];
        $devices = [];
        $instagramReferrals = 0;
        $peak = null;

        foreach ($days as $day) {
            $website = self::arr($day['website'] ?? []);
            $t = self::arr($website['totals'] ?? []);
            $sessions = self::int($t['sessions'] ?? 0);
            $totals['pageviews'] += self::int($t['pageviews'] ?? 0);
            $totals['sessions'] += $sessions;
            $totals['uniqueVisitors'] += self::int($t['unique_visitors'] ?? 0);
            $totals['clicks'] += self::int($t['clicks'] ?? 0);
            $weightedBounce += $sessions * self::float($t['bounce_rate'] ?? 0);
            $weightedDuration += $sessions * self::float($t['avg_session_duration_s'] ?? 0);
            $sources = self::sumMaps($sources, self::camelKeys(self::arr($website['traffic_sources'] ?? [])));
            $devices = self::sumMaps($devices, self::arr($website['devices'] ?? []));
            $instagramReferrals += self::int(self::arr($website['social_referrals'] ?? [])['instagram'] ?? 0);

            $clicks = self::int($t['clicks'] ?? 0);
            if ($peak === null || $clicks > $peak['clicks']) {
                $peak = ['date' => self::str($day['date']), 'clicks' => $clicks];
            }
        }

        $count = count($days);

        return $totals + [
            'bounceRate' => $totals['sessions'] > 0 ? round($weightedBounce / $totals['sessions'], 3) : 0.0,
            'avgSessionDurationSeconds' => $totals['sessions'] > 0 ? round($weightedDuration / $totals['sessions'], 1) : 0.0,
            'clicksPerPageview' => self::ratio($totals['clicks'], $totals['pageviews']),
            'dailyAverage' => [
                'pageviews' => $count > 0 ? round($totals['pageviews'] / $count, 1) : 0.0,
                'sessions' => $count > 0 ? round($totals['sessions'] / $count, 1) : 0.0,
                'clicks' => $count > 0 ? round($totals['clicks'] / $count, 1) : 0.0,
            ],
            'peakDay' => $peak,
            'trafficSources' => $sources,
            'devices' => $devices,
            'instagramReferrals' => $instagramReferrals,
        ];
    }

    /**
     * @param list<array<string, mixed>> $days
     *
     * @return array<string, mixed>
     */
    private function summarizeInstagram(array $days): array
    {
        $totals = [
            'likes' => 0, 'comments' => 0, 'clicks' => 0, 'impressions' => 0, 'reach' => 0,
            'websiteClicks' => 0, 'postsPublished' => 0,
        ];
        $followersStart = null;
        $followersEnd = null;
        $peak = null;

        foreach ($days as $day) {
            $t = self::arr(self::arr($day['instagram'] ?? [])['totals'] ?? []);
            $totals['likes'] += self::int($t['likes'] ?? 0);
            $totals['comments'] += self::int($t['comments'] ?? 0);
            $totals['clicks'] += self::int($t['clicks'] ?? 0);
            $totals['impressions'] += self::int($t['impressions'] ?? 0);
            $totals['reach'] += self::int($t['reach'] ?? 0);
            $totals['websiteClicks'] += self::int($t['website_clicks'] ?? 0);
            $totals['postsPublished'] += self::int($t['posts_published'] ?? 0);

            $followers = self::int($t['followers'] ?? 0);
            $followersStart ??= $followers;
            $followersEnd = $followers;

            $likes = self::int($t['likes'] ?? 0);
            if ($peak === null || $likes > $peak['likes']) {
                $peak = ['date' => self::str($day['date']), 'likes' => $likes];
            }
        }

        $count = count($days);

        return $totals + [
            'engagementRate' => self::ratio($totals['likes'] + $totals['comments'], $totals['reach']),
            'followers' => [
                'start' => $followersStart,
                'end' => $followersEnd,
                'change' => $followersStart === null ? 0 : (int) $followersEnd - $followersStart,
            ],
            'dailyAverage' => [
                'likes' => $count > 0 ? round($totals['likes'] / $count, 1) : 0.0,
                'comments' => $count > 0 ? round($totals['comments'] / $count, 1) : 0.0,
                'clicks' => $count > 0 ? round($totals['clicks'] / $count, 1) : 0.0,
            ],
            'peakDay' => $peak,
        ];
    }

    /**
     * @param array<mixed> $totals
     * @param array<mixed> $socialReferrals
     *
     * @return array<string, int|float>
     */
    private function mapWebsiteDay(array $totals, array $socialReferrals): array
    {
        return [
            'pageviews' => self::int($totals['pageviews'] ?? 0),
            'sessions' => self::int($totals['sessions'] ?? 0),
            'uniqueVisitors' => self::int($totals['unique_visitors'] ?? 0),
            'clicks' => self::int($totals['clicks'] ?? 0),
            'bounceRate' => self::float($totals['bounce_rate'] ?? 0),
            'avgSessionDurationSeconds' => self::float($totals['avg_session_duration_s'] ?? 0),
            'instagramReferrals' => self::int($socialReferrals['instagram'] ?? 0),
        ];
    }

    /**
     * @param array<mixed> $totals
     *
     * @return array<string, int>
     */
    private function mapInstagramDay(array $totals): array
    {
        return [
            'followers' => self::int($totals['followers'] ?? 0),
            'postsPublished' => self::int($totals['posts_published'] ?? 0),
            'likes' => self::int($totals['likes'] ?? 0),
            'comments' => self::int($totals['comments'] ?? 0),
            'clicks' => self::int($totals['clicks'] ?? 0),
            'impressions' => self::int($totals['impressions'] ?? 0),
            'reach' => self::int($totals['reach'] ?? 0),
            'websiteClicks' => self::int($totals['website_clicks'] ?? 0),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getDays(?string $account, Timeframe $timeframe): array
    {
        $stream = $this->dataSource->load($this->resolveAccount($account));

        return array_values(array_filter(
            $stream['days'],
            static fn (array $day): bool => $timeframe->contains(self::str($day['date'])),
        ));
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function getPostCatalog(?string $account): array
    {
        $meta = $this->dataSource->load($this->resolveAccount($account))['meta'];
        $posts = self::arr(self::arr(self::arr($meta['sources'] ?? [])['instagram'] ?? [])['posts'] ?? []);

        $catalog = [];
        foreach ($posts as $post) {
            $post = self::arr($post);
            if (isset($post['shortcode'])) {
                $catalog[self::str($post['shortcode'])] = $post;
            }
        }

        return $catalog;
    }

    /**
     * @param list<array<string, mixed>> $days
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private function getTrackedPeriod(array $days): array
    {
        if ($days === []) {
            throw new StreamDataException('The click stream of this account contains no days.');
        }

        return [
            Timeframe::parseDate(self::str($days[0]['date'])),
            Timeframe::parseDate(self::str($days[array_key_last($days)]['date'])),
        ];
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     *
     * @return array<string, int>
     */
    private static function sumMaps(array $a, array $b): array
    {
        $result = [];
        foreach ($a as $key => $value) {
            $result[(string) $key] = self::int($value);
        }
        foreach ($b as $key => $value) {
            $result[(string) $key] = ($result[(string) $key] ?? 0) + self::int($value);
        }

        return $result;
    }

    /**
     * @param array<mixed> $map
     *
     * @return array<string, mixed>
     */
    private static function camelKeys(array $map): array
    {
        $result = [];
        foreach ($map as $key => $value) {
            $result[lcfirst(str_replace('_', '', ucwords((string) $key, '_')))] = $value;
        }

        return $result;
    }

    private static function ratio(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator, 4) : 0.0;
    }

    private static function normalizeLimit(int $limit): int
    {
        return max(1, min(self::MAX_LIMIT, $limit));
    }

    /**
     * @return array<mixed>
     */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
