<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Contracts\McpEtracker\Exception;

/**
 * Thrown when the underlying click-stream data cannot be read or is malformed.
 */
final class StreamDataException extends \RuntimeException implements McpEtrackerException
{
}
