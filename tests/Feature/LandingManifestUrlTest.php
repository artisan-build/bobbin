<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\LandingManifest;

/*
|--------------------------------------------------------------------------
| Landing manifest URLs
|--------------------------------------------------------------------------
|
| Every URL in `built-for-cloud.manifest` is published to visitors: the package
| renders the icon as an <img src> and the product URL as an <a href> on both
| `/bfc/ui` (home.blade.php) and the landing page (landing.blade.php). A URL that
| does not resolve is therefore a broken image or a dead link on every deployed
| instance. See issue #10.
|
| The invariant is "every URL the manifest publishes resolves publicly". CI must
| not depend on the network, so the check is deterministic: each URL has to be one
| of the scalpels.app URLs below, each confirmed with `curl` to answer HTTP 200.
| Add a URL here only after you have checked it the same way, and record the date.
|
| Verified 2026-09-26 with `curl -o /dev/null -w '%{http_code}'`:
|
|   200  https://scalpels.app/img/products/transparent/bobbin.png   (image/png)
|   200  https://scalpels.app/products/bobbin                       (text/html)
|
*/

/**
 * Absolute URLs that have been verified to resolve publicly.
 *
 * @var list<string>
 */
const VERIFIED_LIVE_MANIFEST_URLS = [
    'https://scalpels.app/img/products/transparent/bobbin.png',
    'https://scalpels.app/products/bobbin',
];

/**
 * Every configured manifest value that is an absolute URL, keyed by its config path.
 *
 * The manifest is scanned rather than read field by field so that a URL added to a
 * new manifest key is covered by this guard too.
 *
 * @return array<string, string>
 */
function configuredManifestUrls(): array
{
    $urls = [];

    $walk = static function (mixed $value, string $path) use (&$walk, &$urls): void {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $walk($child, $path === '' ? (string) $key : $path.'.'.$key);
            }

            return;
        }

        if (is_string($value) && preg_match('#^[a-z][a-z0-9+.-]*://#i', $value) === 1) {
            $urls[$path] = $value;
        }
    };

    $walk(config('built-for-cloud.manifest'), '');

    ksort($urls);

    return $urls;
}

it('publishes a URL in every manifest field the package renders', function (): void {
    $manifest = LandingManifest::fromConfiguration();

    expect(array_keys(configuredManifestUrls()))
        ->toContain('icon', 'product_url')
        ->and([$manifest->icon, $manifest->productUrl])
        ->toBe([config('built-for-cloud.manifest.icon'), config('built-for-cloud.manifest.product_url')]);
});

it('only publishes manifest URLs that have been verified to resolve', function (): void {
    $unverified = [];

    foreach (configuredManifestUrls() as $field => $url) {
        if (! in_array($url, VERIFIED_LIVE_MANIFEST_URLS, true)) {
            $unverified[] = "[{$field}] {$url}";
        }
    }

    expect($unverified)->toBe(
        [],
        'These landing manifest URLs are not among the URLs verified to answer HTTP 200: '.implode(', ', $unverified).'. '
        .'They are rendered to visitors on /bfc/ui and the landing page, so a URL that 404s ships a broken image or a dead link. '
        .'Check the replacement with curl, then add it to VERIFIED_LIVE_MANIFEST_URLS with the date.',
    );
});
