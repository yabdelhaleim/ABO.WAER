<?php

namespace Tests;

use App\Models\ShippingSetting;
use App\Models\StoreSetting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The settings tables are memoized in a static property, and a
        // static property outlives the transaction rollback between
        // tests: a test that read a setting would otherwise see the
        // previous test's value. Clearing the memo here keeps every
        // test's settings its own.
        StoreSetting::flush();
        ShippingSetting::flush();
    }
}
