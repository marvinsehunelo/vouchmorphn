<?php
class PinHelper {
    public static function generate(int $digits=6): string {
        return str_pad((string)random_int(0, (int)pow(10,$digits)-1), $digits, '0', STR_PAD_LEFT);
    }
}
