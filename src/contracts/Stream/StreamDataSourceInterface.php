<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Contracts\McpEtracker\Stream;

/**
 * Provides the raw click-stream documents of the configured tracking accounts.
 *
 * The bundled implementation reads simulated JSON files; a real etracker API client can
 * replace it by implementing this interface.
 *
 * Expected document shape: {"meta": {...}, "days": [{"date": "YYYY-MM-DD", "website": {...}, "instagram": {...}}, ...]}
 */
interface StreamDataSourceInterface
{
    /**
     * @return list<string>
     */
    public function getAccountIdentifiers(): array;

    public function getDefaultAccountIdentifier(): string;

    /**
     * @throws \Ibexa\Contracts\McpEtracker\Exception\AccountNotFoundException
     */
    public function getAccountName(string $account): string;

    /**
     * @return array{meta: array<string, mixed>, days: list<array<string, mixed>>}
     *
     * @throws \Ibexa\Contracts\McpEtracker\Exception\AccountNotFoundException
     * @throws \Ibexa\Contracts\McpEtracker\Exception\StreamDataException
     */
    public function load(string $account): array;
}
