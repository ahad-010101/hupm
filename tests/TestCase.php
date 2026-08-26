<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Vite;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         | Ignore the dev server, if one happens to be running.
         |
         | `npm run dev` writes `public/hot`, and from then on the @vite
         | directive stops emitting built asset tags and emits its client
         | <script type="module"> instead — into every page, including the eight
         | Blade public pages, which must ship no JavaScript at all (D-05,
         | AC-PUB-01). PublicLayoutTest then fails for the whole time somebody
         | has a dev server open, and passes again once they close it.
         |
         | That is the worst shape a failing test can have: real-looking, tied
         | to nothing in the diff, and reproducible only on the machine that has
         | Vite running. Pointing the hot file at a path that never exists makes
         | every test read the built manifest, which is what the assertions are
         | actually about.
        */
        Vite::useHotFile(storage_path('framework/testing/vite-hot-never'));
    }
}
