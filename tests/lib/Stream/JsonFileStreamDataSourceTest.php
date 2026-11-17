<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Tests\McpEtracker\Stream;

use Ibexa\Contracts\McpEtracker\Exception\AccountNotFoundException;
use Ibexa\Contracts\McpEtracker\Exception\StreamDataException;
use Ibexa\McpEtracker\Stream\JsonFileStreamDataSource;
use PHPUnit\Framework\TestCase;

final class JsonFileStreamDataSourceTest extends TestCase
{
    public const string FIXTURE = __DIR__ . '/../_fixtures/stream.json';

    public function testLoadSortsDaysChronologically(): void
    {
        $dataSource = new JsonFileStreamDataSource(['test' => ['name' => 'Test', 'data_file' => self::FIXTURE]]);

        $stream = $dataSource->load('test');

        self::assertSame(['2026-01-01', '2026-01-02', '2026-01-03'], array_column($stream['days'], 'date'));
        self::assertSame('Test', $dataSource->getAccountName('test'));
        self::assertSame(['test'], $dataSource->getAccountIdentifiers());
    }

    public function testDefaultAccount(): void
    {
        $accounts = [
            'a' => ['name' => 'A', 'data_file' => self::FIXTURE],
            'b' => ['name' => 'B', 'data_file' => self::FIXTURE],
        ];

        self::assertSame('a', (new JsonFileStreamDataSource($accounts))->getDefaultAccountIdentifier());
        self::assertSame('b', (new JsonFileStreamDataSource($accounts, 'b'))->getDefaultAccountIdentifier());
    }

    public function testUnknownAccountThrows(): void
    {
        $this->expectException(AccountNotFoundException::class);
        $this->expectExceptionMessage('Unknown tracking account "x". Available accounts: test.');

        (new JsonFileStreamDataSource(['test' => ['name' => 'Test', 'data_file' => self::FIXTURE]]))->load('x');
    }

    public function testMissingFileThrows(): void
    {
        $this->expectException(StreamDataException::class);

        (new JsonFileStreamDataSource(['test' => ['name' => 'Test', 'data_file' => __DIR__ . '/missing.json']]))->load('test');
    }

    public function testInvalidDocumentThrows(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'etracker');
        self::assertIsString($file);
        file_put_contents($file, '{"days": []}');

        try {
            $this->expectException(StreamDataException::class);
            (new JsonFileStreamDataSource(['test' => ['name' => 'Test', 'data_file' => $file]]))->load('test');
        } finally {
            unlink($file);
        }
    }
}
