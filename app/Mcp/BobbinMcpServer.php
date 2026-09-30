<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Tools\CapturesTool;
use App\Mcp\Tools\DeliveryStatusTool;
use App\Mcp\Tools\EndpointsTool;
use App\Mcp\Tools\ReplayCaptureTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Bobbin')]
#[Version('1.0.0')]
#[Instructions('Inspect endpoint-scoped webhook captures and delivery state. Capture content is redacted by Bobbin before it is returned.')]
final class BobbinMcpServer extends Server
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        EndpointsTool::class,
        CapturesTool::class,
        DeliveryStatusTool::class,
        ReplayCaptureTool::class,
    ];
}
