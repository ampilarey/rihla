<?php

use App\Models\KnowledgeArticle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * References belong to anything that makes a claim, not only to articles.
 *
 * A Ziyarah location page (§7.2) asserts history, significance and
 * etiquette, and §7.2 asks specifically for "common misconceptions" — which
 * is a claim about what is *not* true and needs a source more than most.
 * All of that is the same editorial standard §7.1 sets out.
 *
 * The alternative was a second references table for locations, with its own
 * grading rules and its own hadith check. Two copies of a rule is one copy
 * that drifts, and the drift would be invisible until somebody published an
 * ungraded narration on a page this table was not watching. That is exactly
 * the "style guide people forget" failure, wearing a schema.
 *
 * ## Safe to reshape now, and not later
 *
 * `article_references` shipped one merge ago and carries no production
 * rows: the Knowledge Centre deliberately ships with zero content. The
 * backfill below is therefore a no-op in practice and correct if it is not.
 * Doing this after somebody writes forty articles would be a different
 * conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_references', function (Blueprint $table) {
            $table->nullableMorphs('referenceable');
        });

        // Correct whether or not there is anything to move.
        DB::table('article_references')
            ->whereNotNull('knowledge_article_id')
            ->update([
                'referenceable_type' => KnowledgeArticle::class,
                'referenceable_id' => DB::raw('knowledge_article_id'),
            ]);

        // The index first, then the foreign key, then the column — in that
        // order and in separate statements.
        //
        // `dropConstrainedForeignId()` does the last two in one blueprint
        // and leaves the index alone, which fails on SQLite: dropping a
        // column there rebuilds the table, and a composite index still
        // naming the departing column takes the rebuild down with it
        // ("error in index … after drop column"). The first attempt at this
        // migration failed exactly there and left the morph columns behind
        // with nothing recorded.
        Schema::table('article_references', function (Blueprint $table) {
            $table->dropIndex(['knowledge_article_id', 'sort_order']);
        });

        // On every driver, including SQLite — which needs it most. SQLite
        // keeps the foreign key in the table definition, so dropping the
        // column without removing the constraint first fails the rebuild
        // with "unknown column in foreign key definition". Skipping this on
        // SQLite was the second wrong guess at this migration.
        Schema::table('article_references', function (Blueprint $table) {
            $table->dropForeign(['knowledge_article_id']);
        });

        Schema::table('article_references', function (Blueprint $table) {
            $table->dropColumn('knowledge_article_id');
        });

        // The replacement, on the columns that now carry the relationship.
        Schema::table('article_references', function (Blueprint $table) {
            $table->index(['referenceable_type', 'referenceable_id', 'sort_order'], 'references_owner_order_index');
        });
    }

    public function down(): void
    {
        Schema::table('article_references', function (Blueprint $table) {
            $table->foreignId('knowledge_article_id')->nullable()->constrained()->cascadeOnDelete();
        });

        DB::table('article_references')
            ->where('referenceable_type', KnowledgeArticle::class)
            ->update(['knowledge_article_id' => DB::raw('referenceable_id')]);

        // The same order as up(), for the same reason: every index naming
        // a departing column goes first, in its own statement, or SQLite's
        // table rebuild trips over an index that no longer has a column.
        // `dropMorphs()` bundles the index drop with the column drops and
        // leaves the columns behind here.
        Schema::table('article_references', function (Blueprint $table) {
            $table->dropIndex('references_owner_order_index');
        });

        Schema::table('article_references', function (Blueprint $table) {
            $table->dropIndex(['referenceable_type', 'referenceable_id']);
        });

        Schema::table('article_references', function (Blueprint $table) {
            $table->dropColumn(['referenceable_type', 'referenceable_id']);
        });

        Schema::table('article_references', function (Blueprint $table) {
            $table->index(['knowledge_article_id', 'sort_order']);
        });
    }
};
