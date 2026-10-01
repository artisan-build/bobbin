<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Tools\ReplayCaptureTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Bobbin Write')]
#[Version('1.0.0')]
#[Instructions('Replay an exact scoped capture to an exact active destination with caller-provided idempotency.')]
final class BobbinWriteMcpServer extends Server
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        ReplayCaptureTool::class,
    ];
}
