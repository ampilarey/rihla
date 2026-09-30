<?php

namespace App\Services\Stays;

use App\Exceptions\CalendarFeedRefused;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Fetches a calendar link a host typed in — §16 Phase 16.
 *
 * The server is about to make a request to an address a member of the
 * public chose, which is the shape of a server-side request forgery. So:
 *
 * - **https only**, on the default port;
 * - the name must resolve, and **every** address it resolves to must be a
 *   public one — no loopback, private, link-local or reserved range, so
 *   the link cannot reach this server, its database or the cloud metadata
 *   address;
 * - the connection is **pinned** to an address that was checked, so a
 *   name that resolves differently a moment later (DNS rebinding) is not
 *   followed there;
 * - **no redirects**, which would otherwise be a second, unchecked address;
 * - fifteen seconds and two megabytes at most, the size checked while it
 *   downloads rather than after.
 */
class CalendarFetcher
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** @var (Closure(string): list<string>)|null */
    private static ?Closure $resolver = null;

    /**
     * Replace DNS resolution — for tests, which must not depend on the
     * network. Null restores the real resolver.
     *
     * @param  (Closure(string): list<string>)|null  $resolver
     */
    public static function resolveUsing(?Closure $resolver): void
    {
        self::$resolver = $resolver;
    }

    /** Throws with a reason a host can act on; never follows a redirect. */
    public function fetch(string $url): string
    {
        $host = $this->checkedHost($url);
        $address = $this->publicAddress($host);

        try {
            $response = Http::timeout(15)
                ->connectTimeout(5)
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$address]],
                    'progress' => self::stopPast(self::MAX_BYTES),
                ])
                ->withHeaders(['Accept' => 'text/calendar'])
                ->get($url);
        } catch (ConnectionException) {
            throw new CalendarFeedRefused('The calendar did not answer.');
        }

        if ($response->redirect()) {
            throw new CalendarFeedRefused('The link redirects somewhere else. Use the calendar\'s own link.');
        }

        if (! $response->successful()) {
            throw new CalendarFeedRefused('The calendar answered '.$response->status().'.');
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_BYTES) {
            throw new CalendarFeedRefused('The calendar is too large to import.');
        }

        if (! str_contains($body, 'BEGIN:VCALENDAR')) {
            throw new CalendarFeedRefused('That link is not a calendar (iCal) file.');
        }

        return $body;
    }

    /**
     * Stop the download the moment it passes the limit — security review
     * of §16. Without it the whole body is buffered first and measured
     * afterwards, so a feed that never ends is read to the timeout: 50 MB
     * from a local test server was held in full before being refused.
     * Guzzle ignores this callback's return value, so it throws, and
     * curl abandons the transfer.
     *
     * @return Closure(int, int): void
     */
    public static function stopPast(int $maxBytes): Closure
    {
        return static function (int $expected, int $received) use ($maxBytes): void {
            if ($expected > $maxBytes || $received > $maxBytes) {
                throw new CalendarFeedRefused('The calendar is too large to import.');
            }
        };
    }

    /** The link's host, if the link is one this may fetch at all. */
    public function checkedHost(string $url): string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])) {
            throw new CalendarFeedRefused('Use the calendar\'s https:// link.');
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new CalendarFeedRefused('Use the calendar\'s ordinary https:// link.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new CalendarFeedRefused('A calendar link does not carry a password.');
        }

        return strtolower($parts['host']);
    }

    /** One public address the host resolves to — refused if any is not public. */
    public function publicAddress(string $host): string
    {
        $addresses = self::$resolver !== null
            ? (self::$resolver)($host)
            : (filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []));

        if ($addresses === []) {
            throw new CalendarFeedRefused('That address could not be found.');
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new CalendarFeedRefused('That address is not on the public internet.');
            }
        }

        return $addresses[0];
    }
}
