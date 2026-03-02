<?php
class PhoneHelper {
    public static function normalize(string $phone): string {
        return preg_replace('/[^\d\+]/','',$phone);
    }
}
