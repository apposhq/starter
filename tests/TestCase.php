<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Send a bearer token, resolving authentication from scratch.
     *
     * Guards memoize the user they resolved, and a test reuses one container across every request it
     * makes — so a second request carrying a different API key would otherwise authenticate as the
     * first, and a per-key assertion would quietly measure the wrong key. Serving the same requests
     * over Octane does not have this problem: FlushAuthenticationState clears the guards between them.
     * This makes the test harness behave the way the server already does.
     */
    public function withToken(string $token, string $type = 'Bearer'): static
    {
        $this->app['auth']->forgetGuards();

        return parent::withToken($token, $type);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
