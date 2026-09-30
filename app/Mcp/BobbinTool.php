<?php

declare(strict_types=1);

namespace App\Mcp;

use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

abstract class BobbinTool extends Tool
{
    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function validateArguments(Request $request, array $rules): array
    {
        $arguments = $request->all();
        $allowed = array_values(array_unique(array_map(
            static fn (string $key): string => explode('.', $key, 2)[0],
            array_keys($rules),
        )));

        foreach (array_keys($arguments) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw ValidationException::withMessages([
                    'arguments' => 'Tool arguments must be a closed object with named keys.',
                ]);
            }
        }

        return $request->validate($rules);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $tool = parent::toArray();
        $tool['inputSchema']['additionalProperties'] = false;

        return $tool;
    }
}
