<?php
declare(strict_types=1);

/**
 * Central country resolver for DDD country structure.
 *
 * Returns:
 * - name: display / directory name under src/Core/Config/Countries
 * - slug: lowercase directory name under config/countries
 * - code: ISO-style short code
 */

$raw =
    getenv('SYSTEM_COUNTRY')
    ?: getenv('APP_COUNTRY')
    ?: getenv('COUNTRY')
    ?: 'Botswana';

$normalized = strtolower(trim((string) $raw));

$map = [
    'bw' => [
        'name' => 'Botswana',
        'slug' => 'botswana',
        'code' => 'BW',
    ],
    'botswana' => [
        'name' => 'Botswana',
        'slug' => 'botswana',
        'code' => 'BW',
    ],
    'ng' => [
        'name' => 'Nigeria',
        'slug' => 'nigeria',
        'code' => 'NG',
    ],
    'nigeria' => [
        'name' => 'Nigeria',
        'slug' => 'nigeria',
        'code' => 'NG',
    ],
];

if (!isset($map[$normalized])) {
    throw new RuntimeException("Unsupported system country: {$raw}");
}

$resolved = $map[$normalized];

if (!defined('SYSTEM_COUNTRY')) {
    define('SYSTEM_COUNTRY', $resolved['name']);
}

if (!defined('SYSTEM_COUNTRY_SLUG')) {
    define('SYSTEM_COUNTRY_SLUG', $resolved['slug']);
}

if (!defined('SYSTEM_COUNTRY_CODE')) {
    define('SYSTEM_COUNTRY_CODE', $resolved['code']);
}

return $resolved;
