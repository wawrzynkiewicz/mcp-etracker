<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Bundle\McpEtracker\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class IbexaMcpEtrackerExtension extends Extension
{
    public const string PARAM_ACCOUNTS = 'ibexa.mcp_etracker.accounts';
    public const string PARAM_DEFAULT_ACCOUNT = 'ibexa.mcp_etracker.default_account';

    private const string BUNDLED_DATA_DIR = __DIR__ . '/../Resources/data';

    /**
     * Simulated data sets shipped with the bundle, used when no accounts are configured.
     */
    public const array BUNDLED_ACCOUNTS = [
        'nrwbank' => [
            'name' => 'NRW.BANK (simulated, 3 months)',
            'data_file' => self::BUNDLED_DATA_DIR . '/nrwbank_clickstream_3months.json',
        ],
        'nrwbank_sample' => [
            'name' => 'NRW.BANK (simulated, 1-day sample)',
            'data_file' => self::BUNDLED_DATA_DIR . '/nrwbank_clickstream_1day.json',
        ],
        'eram' => [
            'name' => 'Eram (simulated, 3 months)',
            'data_file' => self::BUNDLED_DATA_DIR . '/eram_clickstream_3months.json',
        ],
    ];

    public function getAlias(): string
    {
        return Configuration::ROOT;
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{default_account: string|null, accounts: array<string, array{name: string, data_file: string}>} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $accounts = $config['accounts'] === [] ? self::BUNDLED_ACCOUNTS : $config['accounts'];

        $container->setParameter(self::PARAM_ACCOUNTS, $accounts);
        $container->setParameter(self::PARAM_DEFAULT_ACCOUNT, $config['default_account']);

        $phpFileLoader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $phpFileLoader->load('services.php');
    }
}
