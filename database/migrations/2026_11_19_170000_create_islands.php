<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The inhabited islands by atoll (companies plan, Phase 3d, from Hijaz), so
 * an address can say which island rather than only spell one. The ministry
 * list asks for it, and a free-text "Hdh. Kulhudhuffushi" and
 * "Kulhudhuffushi, HDh" are the same island to a person and two to a filter.
 *
 * The list is written here as literals, not read from a file or a seeder:
 * nothing seeds production (deploy-production.sh), and a migration records
 * what it wrote on the day it ran (AGENTS.md). A later correction is a
 * later migration, or an edit in the panel.
 *
 * `customers.island_id` and `travellers.island_id` are plain indexed
 * columns, not foreign keys, because adding a constrained column makes
 * SQLite rebuild the table and cascade-delete what hangs off it. Islands
 * are never deleted, only switched off, so nothing is left pointing nowhere.
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: string, 2: list<string>}> */
    private const ISLANDS = [
        ['HA', 'Haa Alif', ['Baarah', 'Dhiddhoo', 'Filladhoo', 'Hoarafushi', 'Ihavandhoo', 'Kelaa', 'Maarandhoo', 'Mulhadhoo', 'Muraidhoo', 'Thakandhoo', 'Thuraakunu', 'Uligamu', 'Utheemu', 'Vashafaru']],
        ['HDh', 'Haa Dhaalu', ['Hanimaadhoo', 'Finey', 'Naivaadhoo', 'Nolhivaranfaru', 'Nellaidhoo', 'Nolhivaram', 'Kurinbi', 'Kulhudhuffushi', 'Kumundhoo', 'Neykurendhoo', 'Vaikaradhoo', 'Makunudhoo', 'Hirimaradhoo']],
        ['Sh', 'Shaviyani', ['Bileffahi', 'Feevah', 'Feydhoo', 'Foakaidhoo', 'Funadhoo', 'Goidhoo', 'Kanditheemu', 'Komandoo', 'Lhaimagu', 'Maaungoodhoo', 'Maroshi', 'Milandhoo', 'Narudhoo', 'Noomaraa']],
        ['N', 'Noonu', ['Foddhoo', 'Henbadhoo', 'Holhudhoo', 'Kendhikulhudhoo', 'Kudafari', 'Landhoo', 'Lhohi', 'Maafaru', 'Maalhendhoo', 'Magoodhoo', 'Manadhoo', 'Miladhoo', 'Velidhoo']],
        ['R', 'Raa', ['Alifushi', 'Angolhitheemu', 'Fainu', 'Hulhudhuffaaru', 'Inguraidhoo', 'Innamaadhoo', 'Dhuvaafaru', 'Kinolhas', 'Maakurathu', 'Maduvvaree', 'Meedhoo', 'Rasgetheemu', 'Rasmaadhoo', 'Ungoofaaru', 'Vaadhoo']],
        ['B', 'Baa', ['Dharavandhoo', 'Dhonfanu', 'Eydhafushi', 'Fehendhoo', 'Fulhadhoo', 'Goidhoo', 'Hithaadhoo', 'Kamadhoo', 'Kendhoo', 'Kihaadhoo', 'Kudarikilu', 'Maalhos', 'Thulhaadhoo']],
        ['Lh', 'Lhaviyani', ['Hinnavaru', 'Kurendhoo', 'Maafilaafushi', 'Naifaru', 'Olhuvelifushi', 'Ookolhufinolhu']],
        ['K', 'Kaafu', ['Dhiffushi', 'Gaafaru', 'Gulhi', 'Guraidhoo', 'Himmafushi', 'Hulhumalé', 'Huraa', 'Kaashidhoo', 'Malé', 'Maafushi', 'Thulusdhoo', 'Villimalé']],
        ['AA', 'Alif Alif', ['Bodufolhudhoo', 'Feridhoo', 'Himandhoo', 'Maalhos', 'Mathiveri', 'Rasdhoo', 'Thoddoo', 'Ukulhas', 'Fesdhoo']],
        ['ADh', 'Alif Dhaal', ['Dhangethi', 'Dhiddhoo', 'Dhigurah', 'Fenfushi', 'Haggnaameedhoo', 'Kunburudhoo', 'Maamingili', 'Mahibadhoo', 'Mandhoo', 'Omadhoo']],
        ['V', 'Vaavu', ['Felidhoo', 'Fulidhoo', 'Keyodhoo', 'Rakeedhoo', 'Thinadhoo']],
        ['M', 'Meemu', ['Mulak', 'Maduvvaree', 'Dhiggaru', 'Kolhufushi', 'Muli', 'Naalaafushi', 'Raimmandhoo', 'Veyvah']],
        ['F', 'Faafu', ['Bileddhoo', 'Dharanboodhoo', 'Feeali', 'Magoodhoo', 'Nilandhoo']],
        ['Dh', 'Dhaalu', ['Bandidhoo', 'Hulhudheli', 'Kudahuvadhoo', 'Maaenboodhoo', 'Meedhoo', 'Rinbudhoo', 'Vaanee']],
        ['Th', 'Thaa', ['Burunee', 'Vilufushi', 'Madifushi', 'Dhiyamingili', 'Guraidhoo', 'Gaadhiffushi', 'Thimarafushi', 'Veymandoo', 'Kinbidhoo', 'Omadhoo', 'Hirilandhoo', 'Kandoodhoo', 'Vandhoo']],
        ['L', 'Laamu', ['Dhanbidhoo', 'Fonadhoo', 'Gan', 'Hithadhoo', 'Isdhoo', 'Kunahandhoo', 'Maabaidhoo', 'Maamendhoo', 'Maavah', 'Mundoo']],
        ['GA', 'Gaafu Alif', ['Dhaandhoo', 'Dhevvadhoo', 'Gemanafushi', 'Kanduhulhudhoo', 'Kolamaafushi', 'Kondey', 'Maamendhoo', 'Nilandhoo', 'Villingili']],
        ['GDh', 'Gaafu Dhaalu', ['Fares-Maathodaa', 'Fiyoaree', 'Gadhdhoo', 'Hoandeddhoo', 'Madaveli', 'Nadellaa', 'Rathafandhoo', 'Thinadhoo', 'Vaadhoo']],
        ['Gn', 'Gnaviyani', ['Fuvahmulah']],
        ['S', 'Seenu', ['Hithadhoo', 'Maradhoo', 'Maradhoo-Feydhoo', 'Feydhoo', 'Hulhudhoo', 'Meedhoo']],
    ];

    public function up(): void
    {
        Schema::create('islands', function (Blueprint $table): void {
            $table->id();
            $table->string('atoll_code', 8);
            $table->string('atoll_name', 60);
            $table->string('name', 80);
            $table->string('name_dv', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['atoll_code', 'name']);
            $table->index('name');
        });

        $now = now();
        $rows = [];

        foreach (self::ISLANDS as [$code, $atoll, $islands]) {
            foreach ($islands as $island) {
                $rows[] = ['atoll_code' => $code, 'atoll_name' => $atoll, 'name' => $island, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        DB::table('islands')->insert($rows);

        Schema::table('customers', function (Blueprint $table): void {
            $table->unsignedBigInteger('island_id')->nullable()->after('address')->index();
        });

        Schema::table('travellers', function (Blueprint $table): void {
            $table->unsignedBigInteger('island_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        // The index, then the column, each in its own call (AGENTS.md).
        foreach (['travellers', 'customers'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropIndex(['island_id']);
            });

            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('island_id');
            });
        }

        Schema::dropIfExists('islands');
    }
};
