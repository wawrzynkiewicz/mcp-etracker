<?php

/**
 * @copyright Copyright (C) Ibexa AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 */
declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Ibexa\McpEtracker\Tool\Tracking\ClickStreamTools;

/*
 * Tool classes implement `Ibexa\Contracts\Mcp\McpCapabilityInterface` and are therefore auto-tagged
 * `ibexa.mcp.capability` by ibexa/mcp. ClickStreamTools declares `servers: ['etracker']` in its
 * `#[McpTool]` attributes; configure an `etracker` MCP server for the repository to expose them
 * (or list the class under `ibexa.repositories.<alias>.mcp.<server>.tools` for another server).
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    $services->set(ClickStreamTools::class);
};
