<?php

namespace Infrastructure\Banks;

use CORE_CONFIG\CountryBankRegistry;
use CORE_CONFIG\SystemCountry;

class BankFactory
{
    public static function make(string $bankCode)
    {
        $country = SystemCountry::get();

        $config = CountryBankRegistry::get($country, $bankCode);

        return new GenericBankClient($config);
    }
}
