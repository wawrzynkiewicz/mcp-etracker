<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Contracts\McpEtracker\Stream;

use Ibexa\Contracts\McpEtracker\Exception\InvalidArgumentException;

/**
 * Inclusive date range (day granularity) used to filter the click stream.
 */
final readonly class Timeframe
{
    public const string PRESET_LAST_DAY = 'last_day';
    public const string PRESET_LAST_7_DAYS = 'last_7_days';
    public const string PRESET_LAST_14_DAYS = 'last_14_days';
    public const string PRESET_LAST_30_DAYS = 'last_30_days';
    public const string PRESET_LAST_90_DAYS = 'last_90_days';
    public const string PRESET_ALL = 'all';

    public const string DEFAULT_PRESET = self::PRESET_LAST_30_DAYS;

    private const array PRESET_DAYS = [
        self::PRESET_LAST_DAY => 1,
        self::PRESET_LAST_7_DAYS => 7,
        self::PRESET_LAST_14_DAYS => 14,
        self::PRESET_LAST_30_DAYS => 30,
        self::PRESET_LAST_90_DAYS => 90,
    ];

    public \DateTimeImmutable $from;

    public \DateTimeImmutable $to;

    public function __construct(\DateTimeInterface $from, \DateTimeInterface $to)
    {
        $this->from = \DateTimeImmutable::createFromInterface($from)->setTime(0, 0);
        $this->to = \DateTimeImmutable::createFromInterface($to)->setTime(0, 0);

        if ($this->from > $this->to) {
            throw new InvalidArgumentException(sprintf(
                'Invalid timeframe: start date %s is after end date %s.',
                $this->getFromString(),
                $this->getToString(),
            ));
        }
    }

    /**
     * Builds a timeframe from a preset, counted backwards from (and including) $anchor.
     * The "all" preset spans from $earliest to $anchor.
     */
    public static function fromPreset(string $preset, \DateTimeInterface $anchor, \DateTimeInterface $earliest): self
    {
        $preset = strtolower(trim($preset));
        if ($preset === self::PRESET_ALL) {
            return new self($earliest, $anchor);
        }

        if (!isset(self::PRESET_DAYS[$preset])) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported timeframe "%s". Use one of: %s.',
                $preset,
                implode(', ', self::getPresets()),
            ));
        }

        $to = \DateTimeImmutable::createFromInterface($anchor);

        return new self($to->modify(sprintf('-%d days', self::PRESET_DAYS[$preset] - 1)), $to);
    }

    public static function fromStrings(string $from, string $to): self
    {
        return new self(self::parseDate($from), self::parseDate($to));
    }

    public static function parseDate(string $date): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($date);
        } catch (\Exception $e) {
            throw new InvalidArgumentException(sprintf('Invalid date "%s". Use the YYYY-MM-DD format.', $date), 0, $e);
        }
    }

    /**
     * @return list<string>
     */
    public static function getPresets(): array
    {
        return [...array_keys(self::PRESET_DAYS), self::PRESET_ALL];
    }

    /**
     * Returns the intersection with another timeframe, or null when they do not overlap.
     */
    public function intersect(self $other): ?self
    {
        $from = max($this->from, $other->from);
        $to = min($this->to, $other->to);

        return $from > $to ? null : new self($from, $to);
    }

    /**
     * @param string $date Date in YYYY-MM-DD format.
     */
    public function contains(string $date): bool
    {
        return $date >= $this->getFromString() && $date <= $this->getToString();
    }

    public function getDays(): int
    {
        return (int) $this->from->diff($this->to)->days + 1;
    }

    public function getFromString(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function getToString(): string
    {
        return $this->to->format('Y-m-d');
    }

    /**
     * @return array{from: string, to: string, days: int}
     */
    public function toArray(): array
    {
        return ['from' => $this->getFromString(), 'to' => $this->getToString(), 'days' => $this->getDays()];
    }
}
