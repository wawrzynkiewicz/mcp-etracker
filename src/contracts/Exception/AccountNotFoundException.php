<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Contracts\McpEtracker\Exception;

final class AccountNotFoundException extends \InvalidArgumentException implements McpEtrackerException
{
    /**
     * @param list<string> $available
     */
    public static function forIdentifier(string $identifier, array $available): self
    {
        return new self(sprintf(
            'Unknown tracking account "%s". Available accounts: %s.',
            $identifier,
            $available === [] ? '(none)' : implode(', ', $available),
        ));
    }
}
