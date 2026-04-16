<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

class PhoneHelper {
    public static function normalize(string $phone): string {
        return preg_replace('/[^\d\+]/','',$phone);
    }
}
