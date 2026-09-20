<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The document wallet, versioned from the first row.
 *
 * **[R-8]: this is a schema decision, not a feature toggle.** A replaced
 * passport creates version 2 and supersedes version 1; it never overwrites
 * one. Retrofitting that onto flat document rows means migrating live
 * passport data, which is why the plan puts it in Phase 3 rather than
 * "later".
 *
 * Why versions matter operationally, not just tidily: a visa was applied for
 * against a particular passport. When the traveller renews mid-process, the
 * application on file still refers to the old number, and the only way to
 * answer "which document did we send them?" is to still have it.
 *
 * Files never live in this table. Each version records where its file is,
 * how big it is and its SHA-256 checksum, and the file itself sits on a
 * private disk reachable only through a short-lived signed URL, with every
 * download written to the audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();

            // Documents belong to the person they are about, not to the
            // booking. A traveller who comes back next year brings the same
            // passport, and it should not have to be collected twice.
            $table->foreignId('traveller_id')->constrained()->cascadeOnDelete();

            // Which booking it was collected for, when that is known. Null
            // for anything gathered outside one.
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();

            // travel, identity, financial, medical. A string rather than an
            // enum: the categories will grow, and a migration to add one
            // value is not worth the constraint.
            $table->string('category', 30);
            $table->string('type', 40);

            $table->string('status', 20)->default('pending');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason')->nullable();

            // When the document itself stops being valid — a passport's
            // expiry, a visa's. Whether that is *soon enough to be a
            // problem* is computed against a configurable window, never
            // stored: a stored "expiring soon" flag is wrong from the moment
            // the clock passes it.
            $table->date('expires_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['traveller_id', 'category']);
            $table->index(['status', 'created_at']);
            $table->index('expires_at');
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            // 1, 2, 3… per document. Unique with the document below, so two
            // simultaneous uploads cannot both claim to be version 2.
            $table->unsignedInteger('version');

            $table->string('disk', 40);
            $table->string('path');
            $table->string('original_filename');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);

            // SHA-256 of the file as stored. Two jobs: proving a file has
            // not changed under us, and spotting that the "new" passport
            // somebody uploaded is byte-for-byte the old one.
            $table->string('checksum', 64)->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            // Set when a newer version replaces this one. The row and the
            // file both stay.
            $table->timestamp('superseded_at')->nullable();

            $table->timestamps();

            $table->unique(['document_id', 'version']);
            $table->index(['document_id', 'superseded_at']);
            $table->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
    }
};
