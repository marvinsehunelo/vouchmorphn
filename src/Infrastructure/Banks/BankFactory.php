<?php

namespace Infrastructure\Banks;

use Core\Config\CountryBankRegistry;
use Core\Config\SystemCountry;

class BankFactory
{
    public static function make(string $bankCode)
    {
        $country = SystemCountry::get();

        $config = CountryBankRegistry::get($country, $bankCode);

        return new GenericBankClient($config);
    }
}
