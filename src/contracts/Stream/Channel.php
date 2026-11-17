<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Contracts\McpEtracker\Stream;

use Ibexa\Contracts\McpEtracker\Exception\InvalidArgumentException;

/**
 * Tracked channel of an etracker account.
 */
enum Channel: string
{
    case Website = 'website';
    case Instagram = 'instagram';

    public const string ALL = 'all';

    /**
     * Resolves a channel parameter ("website", "instagram" or "all") into a list of channels.
     *
     * @return non-empty-list<self>
     */
    public static function listFromParameter(?string $channel): array
    {
        $channel = strtolower(trim((string) $channel));
        if ($channel === '' || $channel === self::ALL) {
            return self::cases();
        }

        $resolved = self::tryFrom($channel);
        if ($resolved === null) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported channel "%s". Use one of: %s, %s.',
                $channel,
                implode(', ', array_map(static fn (self $case): string => $case->value, self::cases())),
                self::ALL,
            ));
        }

        return [$resolved];
    }
}
