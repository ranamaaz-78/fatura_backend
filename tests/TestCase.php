<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The product defaults to Spanish; most tests were written against the English wording, so they ask for it.
        // LocaleTest drops this to test the default itself.
        $this->defaultHeaders['Accept-Language'] = 'en';
    }
}
