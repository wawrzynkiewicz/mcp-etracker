<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Ibexa\Bundle\McpEtracker\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * ibexa_mcp_etracker:
 *     default_account: nrwbank
 *     accounts:
 *         nrwbank:
 *             name: 'NRW.BANK'
 *             data_file: '%kernel.project_dir%/var/etracker/nrwbank.json'
 *
 * When no accounts are configured, the bundled simulated NRW.BANK data sets are used.
 */
final class Configuration implements ConfigurationInterface
{
    public const string ROOT = 'ibexa_mcp_etracker';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ROOT);

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('default_account')
                    ->defaultNull()
                    ->info('Account used when a tool is called without "account". Defaults to the first configured account.')
                ->end()
                ->arrayNode('accounts')
                    ->info('Tracking accounts keyed by identifier. Each account points to a click-stream JSON file.')
                    ->useAttributeAsKey('identifier')
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('name')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('data_file')->isRequired()->cannotBeEmpty()->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
