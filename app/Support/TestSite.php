<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Whether a request is being served by the test site — test.rihla.mv, which
 * updates itself from `main` once CI passes (docs/TEST_AUTO_DEPLOY.md) — and
 * not by rihla.mv, which is only ever deployed by hand.
 *
 * The rule is the one the deploy webhook uses to refuse production
 * (TestDeployWebhookController::isTestHost): the request's host, or the host
 * of APP_URL, is one of `deploy.test_allowed_hosts`. So the red bar this
 * drives can ride `main` to production and never show there.
 */
final class TestSite
{
    public static function is(?Request $request = null): bool
    {
        /** @var list<string> $allowed */
        $allowed = config('deploy.test_allowed_hosts', ['test.rihla.mv']);
        $allowed = array_map(static fn (string $host): string => strtolower($host), $allowed);

        $hosts = [
            strtolower(($request ?? request())->getHost()),
            strtolower((string) (parse_url((string) config('app.url', ''), PHP_URL_HOST) ?: '')),
        ];

        foreach ($hosts as $host) {
            if ($host !== '' && in_array($host, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    /** The commit the site is running, shortened as GitHub shows it, or null when it cannot be read. */
    public static function build(): ?string
    {
        $sha = DeployedCommit::sha();

        return $sha === null ? null : substr($sha, 0, 7);
    }
}
