<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUpTraits()
    {
        $database = config('database.connections.'.config('database.default').'.database');

        if ($database !== 'pulsefeed_testing') {
            throw new RuntimeException("Refusing to run tests against database [{$database}].");
        }

        return parent::setUpTraits();
    }
}
