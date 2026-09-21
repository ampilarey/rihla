<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Device;
use App\Support\SignedInDevices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Where an account is signed in, and how to stop being — §10.4.
 *
 * Three properties, in the order they matter:
 *
 * 1. **A session that is not yours cannot be signed out by you.** The
 *    session id is the only thing identifying a row, so the account has to
 *    be in the query as well or the screen is a way to sign colleagues out.
 * 2. **A changed password does not leave the old sessions open.** This is
 *    the whole point of changing it after a fright: a session already open
 *    never re-checks the password.
 * 3. **When it cannot see the sessions, it says so.** An empty list reads
 *    as "you are signed in nowhere else", which is the opposite of the
 *    truth and the worst possible answer to somebody checking whether they
 *    have been broken into.
 */
class SignedInDevicesTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME_ON_ANDROID = 'Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36';

    private const SAFARI_ON_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();

        // The suite runs on the array driver (phpunit.xml). Everything here
        // is about the table, which is what production uses.
        config(['session.driver' => 'database']);
    }

    private function signedInOn(User $user, string $id, string $agent = self::CHROME_ON_ANDROID, ?Carbon $at = null): string
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->getKey(),
            'ip_address' => '203.0.113.10',
            'user_agent' => $agent,
            'payload' => base64_encode(serialize([])),
            'last_activity' => ($at ?? now())->getTimestamp(),
        ]);

        return $id;
    }

    // ── Listing ──────────────────────────────────────────────────────────

    public function test_it_lists_every_place_this_account_is_signed_in(): void
    {
        $user = User::factory()->create();

        $this->signedInOn($user, 'older', self::SAFARI_ON_IPHONE, now()->subHour());
        $this->signedInOn($user, 'newer', self::CHROME_ON_ANDROID, now());

        $devices = SignedInDevices::for($user);

        $this->assertSame(['newer', 'older'], $devices->map(fn (Device $d): string => $d->id)->all());
    }

    public function test_it_does_not_list_somebody_elses_sessions(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $this->signedInOn($mine, 'mine');
        $this->signedInOn($theirs, 'theirs');

        $this->assertSame(['mine'], SignedInDevices::for($mine)->map(fn (Device $d): string => $d->id)->all());
    }

    public function test_the_current_device_is_marked_and_only_that_one(): void
    {
        $user = User::factory()->create();

        $this->signedInOn($user, 'here');
        $this->signedInOn($user, 'elsewhere');

        $devices = SignedInDevices::for($user, 'here');

        $this->assertTrue($devices->firstWhere('id', 'here')?->isCurrent);
        $this->assertFalse($devices->firstWhere('id', 'elsewhere')?->isCurrent);
    }

    // ── Signing out ──────────────────────────────────────────────────────

    public function test_signing_out_a_device_removes_its_session(): void
    {
        $user = User::factory()->create();
        $this->signedInOn($user, 'the-old-telephone');

        $this->assertTrue(SignedInDevices::signOut($user, 'the-old-telephone'));
        $this->assertDatabaseMissing('sessions', ['id' => 'the-old-telephone']);
    }

    /**
     * The one that would turn this screen into a weapon.
     *
     * A session id is the only thing naming a row. Without the account in
     * the query as well, anybody who learned or guessed one could sign a
     * colleague out of the panel in the middle of a booking.
     */
    public function test_a_session_belonging_to_somebody_else_cannot_be_signed_out(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $this->signedInOn($theirs, 'not-mine');

        $this->assertFalse(SignedInDevices::signOut($mine, 'not-mine'));
        $this->assertDatabaseHas('sessions', ['id' => 'not-mine']);
    }

    public function test_the_route_will_not_sign_out_somebody_elses_session_either(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $this->signedInOn($theirs, 'not-mine');

        $this->actingAs($mine)
            ->delete(route('devices.destroy', 'not-mine'))
            ->assertRedirect(route('devices.index'));

        $this->assertDatabaseHas('sessions', ['id' => 'not-mine']);
    }

    public function test_signing_out_everywhere_else_keeps_this_one(): void
    {
        $user = User::factory()->create();

        $this->signedInOn($user, 'here');
        $this->signedInOn($user, 'one');
        $this->signedInOn($user, 'two');

        $this->assertSame(2, SignedInDevices::signOutOthers($user, 'here'));

        $this->assertDatabaseHas('sessions', ['id' => 'here']);
        $this->assertDatabaseMissing('sessions', ['id' => 'one']);
        $this->assertDatabaseMissing('sessions', ['id' => 'two']);
    }

    public function test_signing_out_everywhere_leaves_nothing(): void
    {
        $user = User::factory()->create();

        $this->signedInOn($user, 'here');
        $this->signedInOn($user, 'elsewhere');

        $this->assertSame(2, SignedInDevices::signOutEverywhere($user));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->getKey())->count());
    }

    // ── A changed password ───────────────────────────────────────────────

    /**
     * Property 2. A session that is already open never re-checks the
     * password, so without this the person who changed it because they
     * were frightened has changed nothing at all.
     */
    public function test_changing_your_password_signs_out_every_other_session(): void
    {
        $user = User::factory()->create(['password' => Hash::make('the-old-one')]);

        $this->signedInOn($user, 'somebody-elses-browser');

        $this->actingAs($user)->put(route('password.update'), [
            'current_password' => 'the-old-one',
            'password' => 'a-much-better-one',
            'password_confirmation' => 'a-much-better-one',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'somebody-elses-browser']);
    }

    public function test_changing_your_password_leaves_you_signed_in_here(): void
    {
        $user = User::factory()->create(['password' => Hash::make('the-old-one')]);

        $this->actingAs($user)->put(route('password.update'), [
            'current_password' => 'the-old-one',
            'password' => 'a-much-better-one',
            'password_confirmation' => 'a-much-better-one',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_failed_password_change_signs_nothing_out(): void
    {
        $user = User::factory()->create(['password' => Hash::make('the-old-one')]);

        $this->signedInOn($user, 'still-signed-in');

        $this->actingAs($user)->put(route('password.update'), [
            'current_password' => 'not-the-old-one',
            'password' => 'a-much-better-one',
            'password_confirmation' => 'a-much-better-one',
        ])->assertSessionHasErrors('current_password', errorBag: 'updatePassword');

        $this->assertDatabaseHas('sessions', ['id' => 'still-signed-in']);
    }

    /**
     * A reset is the compromise case, so it takes this session too.
     *
     * Somebody following an e-mailed reset link is frequently doing it
     * because they think they are not the only one signed in.
     */
    public function test_resetting_your_password_signs_out_everywhere(): void
    {
        $user = User::factory()->create();

        $this->signedInOn($user, 'whoever-that-is');

        $this->post(route('password.store'), [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'a-much-better-one',
            'password_confirmation' => 'a-much-better-one',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'whoever-that-is']);
    }

    // ── The screen ───────────────────────────────────────────────────────

    public function test_the_page_needs_somebody_signed_in(): void
    {
        $this->get(route('devices.index'))->assertRedirect(route('login'));
    }

    public function test_the_page_names_each_device(): void
    {
        $user = User::factory()->create();

        $this->signedInOn($user, 'a-telephone', self::SAFARI_ON_IPHONE);

        $this->actingAs($user)->get(route('devices.index'))
            ->assertSuccessful()
            ->assertSee('Safari on iPhone');
    }

    /**
     * Property 3, and the reason this page has two empty states.
     *
     * On a driver that does not store sessions in the database there is
     * nothing to list. Showing an empty list would tell somebody checking
     * for an intruder that there is nobody else signed in, which is not
     * something this can know.
     */
    public function test_it_says_it_cannot_see_rather_than_showing_an_empty_list(): void
    {
        config(['session.driver' => 'file']);

        $user = User::factory()->create();

        $this->actingAs($user)->get(route('devices.index'))
            ->assertSuccessful()
            ->assertSee('This cannot be shown on this server.')
            ->assertDontSee('One row for every browser');
    }

    public function test_nothing_is_signed_out_on_a_driver_it_cannot_see(): void
    {
        $user = User::factory()->create();
        $this->signedInOn($user, 'untouched');

        config(['session.driver' => 'file']);

        $this->assertSame(0, SignedInDevices::signOutOthers($user, null));
        $this->assertDatabaseHas('sessions', ['id' => 'untouched']);
    }

    // ── Naming a device ──────────────────────────────────────────────────

    /**
     * Every one of these lies about the others, which is why the order of
     * the matcher is the whole of its correctness.
     *
     * @param  non-empty-string  $agent
     */
    #[DataProvider('agents')]
    public function test_a_device_is_named_from_what_it_claims_to_be(string $agent, string $expected): void
    {
        $device = new Device('id', null, $agent, now(), false);

        $this->assertSame($expected, $device->description());
    }

    /** @return array<string, array{string, string}> */
    public static function agents(): array
    {
        return [
            'Edge says Chrome and Safari too' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36 Edg/126.0',
                'Edge on Windows',
            ],
            'Opera says Chrome too' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36 OPR/112.0',
                'Opera on Windows',
            ],
            'Chrome says Safari' => [
                'Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36',
                'Chrome on Android',
            ],
            'Safari says only Safari' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
                'Safari on iPhone',
            ],
            'Firefox on a Mac' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:127.0) Gecko/20100101 Firefox/127.0',
                'Firefox on a Mac',
            ],
        ];
    }

    /** It says it does not know rather than guessing. */
    public function test_an_unrecognisable_device_is_not_given_a_name(): void
    {
        $this->assertSame(
            'A device that did not say what it is',
            (new Device('id', null, 'curl/8.5.0', now(), false))->description(),
        );
    }
}
