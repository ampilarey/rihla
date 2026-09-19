<?php

namespace Tests\Feature;

use App\Support\DeployedCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The path a commit takes from `main` to test.rihla.mv.
 *
 * Two things were wrong with it and neither was tested at all. The deploy
 * fired on the push, so it raced the test suite and usually won — a merge with
 * failing tests reached the server before GitHub finished saying it was
 * broken. And the check afterwards curled the homepage, which the *old* code
 * answers just as well, so a deploy that failed on the server reported green.
 *
 * The webhook itself — the one endpoint in this application that runs shell
 * commands — had no test of any kind.
 */
class DeployPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-long-enough-deploy-secret';

    private function workflow(): string
    {
        return File::get(base_path('.github/workflows/deploy-test-immediate.yml'));
    }

    // ---------------------------------------------------------------- the gate

    /** The whole point: CI first, deploy second. */
    public function test_the_deploy_waits_for_ci_rather_than_the_push(): void
    {
        $workflow = $this->workflow();

        $this->assertStringContainsString('workflow_run', $workflow,
            'The deploy must be triggered by CI finishing, not by the push.');
        $this->assertStringContainsString('workflows: ["CI"]', $workflow);

        $this->assertDoesNotMatchRegularExpression('/^on:\s*\n\s*push:/m', $workflow,
            'A push trigger would deploy in parallel with the tests, which is the race this removes.');
    }

    /** A red CI run must not reach a server. */
    public function test_the_deploy_only_runs_when_ci_succeeded(): void
    {
        $this->assertStringContainsString(
            "github.event.workflow_run.conclusion == 'success'",
            $this->workflow(),
            'Without this the deploy runs whatever CI concluded.',
        );
    }

    /**
     * A pull request's CI run verifies a merge commit that is not on main.
     * Deploying it would put code on the server that was never merged.
     */
    public function test_the_deploy_ignores_pull_request_ci_runs(): void
    {
        $this->assertStringContainsString(
            "github.event.workflow_run.event == 'push'",
            $this->workflow(),
        );
    }

    /**
     * Under workflow_run, GITHUB_SHA is main's tip rather than the commit CI
     * actually verified. On a second merge landing while the first deploys,
     * those differ — and deploying the unverified one is the exact failure
     * this whole gate exists to prevent.
     */
    public function test_the_deploy_uses_the_commit_ci_verified(): void
    {
        $workflow = $this->workflow();

        $this->assertStringContainsString('github.event.workflow_run.head_sha', $workflow);
        $this->assertStringNotContainsString('${GITHUB_SHA}', $workflow,
            'GITHUB_SHA is not the commit CI verified when the trigger is workflow_run.');
    }

    /** A gate nobody can see is indistinguishable from no gate. */
    public function test_a_blocked_deploy_says_so(): void
    {
        $this->assertStringContainsString('report-blocked', $this->workflow());
    }

    // ------------------------------------------------- did the deploy land?

    /** Curling the homepage proves a site is up, not that it changed. */
    public function test_the_deploy_verifies_the_commit_that_is_serving(): void
    {
        $workflow = $this->workflow();

        $this->assertStringContainsString('/api/health', $workflow);
        $this->assertStringContainsString('.commit', $workflow);
        $this->assertMatchesRegularExpression('/if \[ "\$live" = "\$\{DEPLOY_SHA\}" \]/', $workflow,
            'The smoke check must compare the live commit against the one it deployed.');
    }

    public function test_the_health_endpoint_reports_the_running_commit_to_the_deploy_pipeline(): void
    {
        config(['deploy.test_webhook_secret' => self::SECRET]);

        $this->withHeader('Authorization', 'Bearer '.self::SECRET)
            ->getJson('/api/health')
            ->assertOk()
            ->assertJson(['ok' => true, 'commit' => DeployedCommit::sha()]);
    }

    /** The commit it reports must be the commit that is checked out. */
    public function test_the_reported_commit_is_the_one_git_has(): void
    {
        $git = new Process(['git', 'rev-parse', 'HEAD'], base_path());
        $git->run();

        if (! $git->isSuccessful()) {
            $this->markTestSkipped('No git checkout to compare against.');
        }

        $this->assertSame(trim($git->getOutput()), DeployedCommit::sha());
    }

    /**
     * Everyone else gets exactly the response this endpoint has always given.
     * The repository is public today, so the commit is not a secret — but if
     * it is ever made private, naming the running revision tells an attacker
     * which advisories apply, and that change should not be a disclosure.
     */
    public function test_the_health_endpoint_tells_anonymous_callers_nothing_new(): void
    {
        config(['deploy.test_webhook_secret' => self::SECRET]);

        $response = $this->getJson('/api/health')->assertOk();

        $this->assertSame(['ok', 'app', 'env'], array_keys($response->json()));
    }

    public function test_the_health_endpoint_refuses_a_wrong_secret(): void
    {
        config(['deploy.test_webhook_secret' => self::SECRET]);

        $this->withHeader('Authorization', 'Bearer not-the-secret-at-all')
            ->getJson('/api/health')
            ->assertOk()
            ->assertJsonMissingPath('commit');
    }

    /**
     * An unset secret must not authorise an unset header. Both are empty, and
     * comparing them naively would let anybody through on a host that has not
     * been configured yet.
     */
    public function test_an_unconfigured_secret_authorises_nobody(): void
    {
        config(['deploy.test_webhook_secret' => null]);

        $this->withHeader('Authorization', 'Bearer ')
            ->getJson('/api/health')
            ->assertOk()
            ->assertJsonMissingPath('commit');

        $this->withHeader('X-Deploy-Secret', '')
            ->getJson('/api/health')
            ->assertOk()
            ->assertJsonMissingPath('commit');
    }

    /** A guessable secret is worse than none: it looks like protection. */
    public function test_a_short_secret_counts_as_no_secret(): void
    {
        config(['deploy.test_webhook_secret' => 'short']);

        $this->withHeader('Authorization', 'Bearer short')
            ->getJson('/api/health')
            ->assertOk()
            ->assertJsonMissingPath('commit');
    }

    // ------------------------------------------------------- the webhook itself

    /**
     * Production must never honour this endpoint — it runs a shell script.
     * These are the three refusals that keep it that way, and none of them had
     * ever been executed by a test.
     */
    public function test_the_deploy_webhook_is_absent_when_no_secret_is_configured(): void
    {
        config(['deploy.test_webhook_secret' => null]);

        $this->postJson('/api/deploy/test-pull', ['sha' => str_repeat('a', 40)])
            ->assertNotFound();
    }

    public function test_the_deploy_webhook_refuses_a_host_it_does_not_recognise(): void
    {
        config([
            'deploy.test_webhook_secret' => self::SECRET,
            'deploy.test_allowed_hosts' => ['test.rihla.mv'],
            'app.url' => 'https://rihla.mv',
        ]);

        // Correct secret, wrong host. Still nothing.
        $this->withHeader('Authorization', 'Bearer '.self::SECRET)
            ->postJson('http://rihla.mv/api/deploy/test-pull', ['sha' => str_repeat('a', 40)])
            ->assertNotFound();
    }

    public function test_the_deploy_webhook_refuses_a_wrong_secret(): void
    {
        config([
            'deploy.test_webhook_secret' => self::SECRET,
            'deploy.test_allowed_hosts' => ['localhost'],
        ]);

        $this->withHeader('Authorization', 'Bearer not-the-secret-at-all')
            ->postJson('/api/deploy/test-pull', ['sha' => str_repeat('a', 40)])
            ->assertUnauthorized();

        // And no credential at all is not a credential.
        $this->postJson('/api/deploy/test-pull', ['sha' => str_repeat('a', 40)])
            ->assertUnauthorized();
    }
}
