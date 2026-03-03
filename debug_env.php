<?php

echo "<pre>";

echo "=== DATABASE_URL ===\n";
var_dump(getenv('DATABASE_URL'));

echo "\n\n=== _ENV ===\n";
print_r($_ENV);

echo "\n\n=== _SERVER ===\n";
print_r($_SERVER);

echo "\n\n=== PHP VERSION ===\n";
echo phpversion();

echo "\n</pre>";
