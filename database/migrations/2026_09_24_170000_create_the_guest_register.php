<?php

use App\Casts\EncryptedIdentifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who actually slept there — §15.6 (Phase 11).
 *
 * Maldivian law requires a guest register, and the plan says so plainly:
 * *"because the law requires the register and somebody will ask for it."*
 * A stay records who booked and paid; this records who stayed, which is
 * not the same list — one person books a room for four.
 *
 * ## The identifier is encrypted, and the column type says so
 *
 * `id_number` is `text` rather than `string`, because ciphertext is
 * several times longer than the six or eight characters it protects, and
 * a `varchar(255)` that silently truncates one would produce a register
 * entry that decrypts to nothing.
 *
 * {@see EncryptedIdentifier} is the cast, and it tolerates
 * plaintext on purpose: `data:anonymise` writes its stand-ins through the
 * query builder rather than through Eloquent, so a strict cast would
 * throw on every read after a scrub — on the one command whose job is
 * keeping real ID numbers off the public test server.
 *
 * ## Cascading, unlike everything else in this domain
 *
 * A stay is restricted from deletion by its customer, property and room;
 * a guest is not a record in its own right, and a register entry for a
 * stay that no longer exists is a name and a passport number belonging to
 * nothing. It goes with the stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stay_guests', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('stay_id')->constrained()->cascadeOnDelete();

            $table->string('full_name');
            $table->string('nationality', 100)->nullable();
            $table->date('date_of_birth')->nullable();

            $table->string('id_type', 20)->default('passport');
            $table->text('id_number')->nullable();

            // Which of them is the person the booking is in the name of.
            // Not derived from the customer: the person who paid is often
            // not one of the guests, and a register that assumes otherwise
            // is wrong the first time somebody books a room for a relative.
            $table->boolean('is_lead')->default(false);

            $table->timestamps();

            $table->index(['stay_id', 'is_lead']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stay_guests');
    }
};
