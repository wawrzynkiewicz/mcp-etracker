<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\McpEtracker\Stream;

use Ibexa\Contracts\McpEtracker\Exception\AccountNotFoundException;
use Ibexa\Contracts\McpEtracker\Exception\StreamDataException;
use Ibexa\Contracts\McpEtracker\Stream\StreamDataSourceInterface;

/**
 * Reads the simulated etracker click stream from JSON files, one file per account.
 * Documents are decoded once per process and kept in memory.
 */
final class JsonFileStreamDataSource implements StreamDataSourceInterface
{
    /** @var array<string, array{meta: array<string, mixed>, days: list<array<string, mixed>>}> */
    private array $loaded = [];

    /**
     * @param array<string, array{name: string, data_file: string}> $accounts Keyed by account identifier.
     */
    public function __construct(
        private readonly array $accounts,
        private readonly ?string $defaultAccount = null,
    ) {
    }

    public function getAccountIdentifiers(): array
    {
        return array_map('strval', array_keys($this->accounts));
    }

    public function getDefaultAccountIdentifier(): string
    {
        if ($this->defaultAccount !== null && $this->defaultAccount !== '') {
            $this->assertAccountExists($this->defaultAccount);

            return $this->defaultAccount;
        }

        $first = array_key_first($this->accounts);
        if ($first === null) {
            throw new StreamDataException('No etracker tracking accounts are configured.');
        }

        return (string) $first;
    }

    public function getAccountName(string $account): string
    {
        $this->assertAccountExists($account);

        return $this->accounts[$account]['name'];
    }

    public function load(string $account): array
    {
        $this->assertAccountExists($account);

        return $this->loaded[$account] ??= $this->readFile($this->accounts[$account]['data_file']);
    }

    /**
     * @return array{meta: array<string, mixed>, days: list<array<string, mixed>>}
     */
    private function readFile(string $file): array
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new StreamDataException(sprintf('Click-stream data file "%s" does not exist or is not readable.', $file));
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new StreamDataException(sprintf('Unable to read click-stream data file "%s".', $file));
        }

        try {
            $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new StreamDataException(sprintf('Click-stream data file "%s" is not valid JSON: %s', $file, $e->getMessage()), 0, $e);
        }

        if (!is_array($document) || !is_array($document['meta'] ?? null) || !is_array($document['days'] ?? null)) {
            throw new StreamDataException(sprintf('Click-stream data file "%s" must contain "meta" and "days" keys.', $file));
        }

        $days = array_values(array_filter(
            $document['days'],
            static fn (mixed $day): bool => is_array($day) && is_string($day['date'] ?? null),
        ));
        usort($days, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        return ['meta' => $document['meta'], 'days' => $days];
    }

    private function assertAccountExists(string $account): void
    {
        if (!isset($this->accounts[$account])) {
            throw AccountNotFoundException::forIdentifier($account, $this->getAccountIdentifiers());
        }
    }
}
