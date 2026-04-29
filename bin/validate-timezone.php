#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Core/Helpers/TimezoneHelper.php';

use VouchMorph\Core\Helpers\TimezoneHelper;

echo "=== Timezone Validation ===\n";
echo "Detected timezone: " . TimezoneHelper::getValidTimezone() . "\n";
echo "All timezones for country (" . ($_ENV['COUNTRY_CODE'] ?? 'unknown') . "): \n";
print_r(TimezoneHelper::getTimezonesByCountry($_ENV['COUNTRY_CODE'] ?? ''));
