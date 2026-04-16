<?php

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

use PHPUnit\Framework\TestCase;

class LicenceEnforcementTest extends TestCase
{
    public function testValidLicenceAllowsFeature()
    {
        $this->assertTrue(
            LicenceEnforcementService::enforce('wallet')
        );
    }

    public function testDisabledFeatureThrowsException()
    {
        $this->expectException(Exception::class);
        LicenceEnforcementService::enforce('nonexistent_feature');
    }
}

