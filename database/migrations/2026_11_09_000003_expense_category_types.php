<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One expense category can serve several expense types (e.g. "Embellishment" for both Order and Labour).
 * `types` (JSON list) is the source of truth; `type` stays as the first of them for older code / backups.
 * Categories that were created twice with the same name under different types (the filter showed the name
 * twice) are merged into ONE category: lowest id is kept, types are combined and the expenses that used the
 * duplicate are moved onto it (the expense title / amounts are untouched). Query builder only, no audit rows.
 */
return new class extends Migration
{
    private const ORDER = ['order', 'unit', 'labour', 'other'];

    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $t) {
            $t->json('types')->nullable()->after('type');
        });

        $keep = [];   // lower(name) => id of the category that stays
        foreach (DB::table('expense_categories')->orderBy('id')->get() as $c) {
            $key = mb_strtolower(trim($c->name));
            if (! isset($keep[$key])) {
                $keep[$key] = $c->id;
                DB::table('expense_categories')->where('id', $c->id)->update(['types' => json_encode([$c->type])]);
                continue;
            }
            $id = $keep[$key];
            $main = DB::table('expense_categories')->find($id);
            $types = array_values(array_unique(array_merge(json_decode($main->types, true), [$c->type])));
            usort($types, fn ($a, $b) => array_search($a, self::ORDER) <=> array_search($b, self::ORDER));
            DB::table('expenses')->where('category_id', $c->id)->update(['category_id' => $id]);
            DB::table('expense_categories')->where('id', $id)->update([
                'types' => json_encode($types), 'type' => $types[0], 'is_active' => $main->is_active || $c->is_active,
            ]);
            DB::table('expense_categories')->where('id', $c->id)->delete();
        }
    }

    public function down(): void
    {
        // merged duplicates are not split again (they are in the pre-migration backup)
        Schema::table('expense_categories', function (Blueprint $t) {
            $t->dropColumn('types');
        });
    }
};
