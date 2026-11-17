<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Tests\Bundle\McpEtracker\DependencyInjection;

use Ibexa\Bundle\McpEtracker\DependencyInjection\IbexaMcpEtrackerExtension;
use Ibexa\Contracts\McpEtracker\Stream\StreamServiceInterface;
use Ibexa\McpEtracker\Stream\JsonFileStreamDataSource;
use Ibexa\McpEtracker\Tool\Tracking\ClickStreamTools;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class IbexaMcpEtrackerExtensionTest extends TestCase
{
    public function testDefaultsToBundledAccounts(): void
    {
        $container = $this->load([]);

        self::assertSame(IbexaMcpEtrackerExtension::BUNDLED_ACCOUNTS, $container->getParameter(IbexaMcpEtrackerExtension::PARAM_ACCOUNTS));
        self::assertNull($container->getParameter(IbexaMcpEtrackerExtension::PARAM_DEFAULT_ACCOUNT));
        foreach (IbexaMcpEtrackerExtension::BUNDLED_ACCOUNTS as $account) {
            self::assertFileExists($account['data_file']);
        }

        self::assertTrue($container->hasDefinition(ClickStreamTools::class));
        self::assertTrue($container->hasDefinition(JsonFileStreamDataSource::class));
        self::assertTrue($container->hasAlias(StreamServiceInterface::class));
    }

    public function testConfiguredAccountsReplaceBundledOnes(): void
    {
        $container = $this->load([
            'default_account' => 'acme',
            'accounts' => ['acme' => ['name' => 'ACME', 'data_file' => '/data/acme.json']],
        ]);

        self::assertSame(['acme' => ['name' => 'ACME', 'data_file' => '/data/acme.json']], $container->getParameter(IbexaMcpEtrackerExtension::PARAM_ACCOUNTS));
        self::assertSame('acme', $container->getParameter(IbexaMcpEtrackerExtension::PARAM_DEFAULT_ACCOUNT));
    }

    public function testBundledDataIsServedThroughTheContainer(): void
    {
        $container = $this->load([]);
        $container->getAlias(StreamServiceInterface::class)->setPublic(true);
        $container->compile();

        $service = $container->get(StreamServiceInterface::class);
        self::assertInstanceOf(StreamServiceInterface::class, $service);
        self::assertSame(['nrwbank', 'nrwbank_sample'], array_column($service->getAccounts(), 'identifier'));
        self::assertSame(92, $service->getAccount('nrwbank')['trackedPeriod']['days']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new IbexaMcpEtrackerExtension())->load([$config], $container);

        return $container;
    }
}
