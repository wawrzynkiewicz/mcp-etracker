<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Ibexa\Bundle\McpEtracker\DependencyInjection\IbexaMcpEtrackerExtension;
use Ibexa\Contracts\McpEtracker\Stream\StreamDataSourceInterface;
use Ibexa\Contracts\McpEtracker\Stream\StreamServiceInterface;
use Ibexa\McpEtracker\Stream\JsonFileStreamDataSource;
use Ibexa\McpEtracker\Stream\StreamService;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
            ->private();

    $services->set(JsonFileStreamDataSource::class)
        ->args([
            param(IbexaMcpEtrackerExtension::PARAM_ACCOUNTS),
            param(IbexaMcpEtrackerExtension::PARAM_DEFAULT_ACCOUNT),
        ]);

    // Swap this alias to plug in a real etracker API client.
    $services->alias(StreamDataSourceInterface::class, JsonFileStreamDataSource::class);

    $services->set(StreamService::class);
    $services->alias(StreamServiceInterface::class, StreamService::class)->public();
};
