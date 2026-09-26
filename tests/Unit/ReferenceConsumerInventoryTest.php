<?php

declare(strict_types=1);

use Tests\Support\ReferenceConsumerInventory;
use Tests\Support\ReferenceConsumerInventoryControls;

/** @param array<string, string> $files */
function inventoryControlRoot(array $files): string
{
    $root = sys_get_temp_dir().'/bfc-inventory-'.bin2hex(random_bytes(6));
    mkdir($root, 0700);
    $files += ['config/auth.php' => <<<'PHP'
<?php

use ArtisanBuild\BuiltForCloud\User;

return [
    'guards' => ['web' => ['driver' => 'session', 'provider' => 'users']],
    'providers' => ['users' => ['driver' => 'eloquent', 'model' => User::class]],
];
PHP];

    foreach ($files as $path => $contents) {
        if (! is_dir(dirname($root.'/'.$path))) {
            mkdir(dirname($root.'/'.$path), 0700, true);
        }
        file_put_contents($root.'/'.$path, $contents);
    }

    return $root;
}

it('pins the Bobbin root surface while retaining the thin auth host', function (): void {
    $expected = array_fill_keys(ReferenceConsumerInventory::FAMILIES, []);

    expect(ReferenceConsumerInventory::inspect(dirname(__DIR__, 2)))->toBe($expected);
});

it('observes every independently introduced inventory control red', function (array $families, array $files): void {
    $root = inventoryControlRoot($files);

    try {
        $inventory = ReferenceConsumerInventory::inspect($root);
        foreach ($families as $family) {
            expect($inventory[$family])->not->toBe([]);
        }
    } finally {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($root);
    }
})->with(ReferenceConsumerInventoryControls::cases());
