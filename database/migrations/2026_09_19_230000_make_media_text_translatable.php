<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The last content table with no translation mechanism at all.
 *
 * `media.title` and `media.caption` are written by an editor and shown on the
 * gallery and on trip pages, in whatever language they were typed — so the
 * Dhivehi gallery has always carried English captions with no way to change
 * that, short of a second media row nobody would know to make.
 *
 * Same shape as the tables before it: `{"en": …, "dv": …}` per column, read
 * back in the request's locale with an English fallback. See
 * docs/adr/0001-how-content-is-translated.md.
 *
 * `file_path`, `thumb_path` and `video_url` stay single values. A photograph
 * is the same photograph in both languages.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TRANSLATABLE = ['title', 'caption'];

    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            foreach (self::TRANSLATABLE as $field) {
                $table->json($field.'_i18n')->nullable();
            }
        });

        foreach (DB::table('media')->orderBy('id')->get() as $row) {
            $values = [];

            foreach (self::TRANSLATABLE as $field) {
                $values[$field.'_i18n'] = json_encode(
                    filled($row->{$field}) ? ['en' => $row->{$field}] : [],
                    JSON_UNESCAPED_UNICODE,
                );
            }

            DB::table('media')->where('id', $row->id)->update($values);
        }

        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn(self::TRANSLATABLE);
        });

        Schema::table('media', function (Blueprint $table) {
            foreach (self::TRANSLATABLE as $field) {
                $table->renameColumn($field.'_i18n', $field);
            }
        });
    }

    public function down(): void
    {
        $rows = DB::table('media')->orderBy('id')->get();

        Schema::table('media', function (Blueprint $table) {
            foreach (self::TRANSLATABLE as $field) {
                $table->renameColumn($field, $field.'_i18n');
            }
        });

        Schema::table('media', function (Blueprint $table) {
            $table->string('title')->nullable();
            $table->text('caption')->nullable();
        });

        foreach ($rows as $row) {
            $values = [];

            foreach (self::TRANSLATABLE as $field) {
                $translations = json_decode($row->{$field} ?? '{}', true) ?: [];
                $values[$field] = $translations['en'] ?? reset($translations) ?: null;
            }

            DB::table('media')->where('id', $row->id)->update($values);
        }

        Schema::table('media', function (Blueprint $table) {
            foreach (self::TRANSLATABLE as $field) {
                $table->dropColumn($field.'_i18n');
            }
        });
    }
};
