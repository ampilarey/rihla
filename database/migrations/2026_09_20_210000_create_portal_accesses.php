<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a customer gets into the Pilgrim Portal — §6.1.
 *
 * ## Why a staff-issued link and not a password
 *
 * There is no way to send a customer anything automatically. SMTP
 * credentials have not been provided and there is no SMS or WhatsApp
 * gateway, so a password login would have **no password reset**, and an
 * emailed magic link would have nothing to send it with. A login somebody
 * can be locked out of for ever is worse than no login.
 *
 * What does exist is a member of staff with WhatsApp open, which is how this
 * operator already talks to every customer. So staff issue a link and send
 * it themselves. When a delivery channel arrives, self-service sign-in is
 * added behind the same portal and this stays as the fallback for the
 * customer who cannot manage one.
 *
 * ## The link is the credential, so it is treated as one
 *
 * - **The token is stored hashed.** A leaked database backup is not a set of
 *   working keys to everybody's bookings.
 * - **It expires**, and the window is configuration rather than a constant.
 * - **It can be revoked**, immediately, by staff — a link sent to the wrong
 *   number has to be killable without waiting for it to lapse.
 * - **Every use is stamped**, so "has anybody actually opened this?" has an
 *   answer, and so does "how many times".
 *
 * ## What it does not open
 *
 * Reading a document *file* is deliberately not reachable with this alone —
 * see App\Http\Middleware\PortalSession. A bearer link forwarded in a family
 * group chat must not be a passport scan in a family group chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_accesses', function (Blueprint $table) {
            $table->id();

            // restrictOnDelete, like everything else hanging off a booking:
            // the record of who was given access outlives the access.
            $table->foreignId('booking_id')->constrained()->restrictOnDelete();

            // SHA-256 of the token. Unique so a lookup is a single indexed
            // read and so two links can never collide.
            $table->string('token_hash', 64)->unique();

            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('uses')->default(0);

            // The first address that used it. Not a security control — a
            // customer's address changes with every mobile tower — but it
            // answers "was this opened from somewhere unexpected?" when
            // somebody asks.
            $table->string('first_used_ip', 45)->nullable();

            $table->timestamps();

            $table->index(['booking_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_accesses');
    }
};
