<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // View testleri derlenmiş asset (public/build) ya da çalışan Vite sunucusu istemez.
        $this->withoutVite();
    }
}
