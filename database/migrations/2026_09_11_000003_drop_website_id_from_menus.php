<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One menu tree for the whole installation, not one per website.
     *
     * The table carried a website_id and MenuSeeder built the same 28 rows
     * three times over, once per site in config('sites.ids'). Nothing ever
     * made them differ: the sidebar is the admin application, and the admin
     * application is the same whichever site it is administering. So every
     * menu migration had to loop over the sites, every one of them had to
     * resolve a parent per site, and a new website meant a new copy of a tree
     * that was identical to the other two.
     *
     * The rows are collapsed onto the tree of the lowest website id and the
     * column goes. What is left is 28 rows that every website shares.
     *
     * Access does not change, and this is the part worth being careful about.
     * A menu was never what scoped an admin to their site -- the grant is:
     * users_menugroupdetail names a group, users_menugroup belongs to a
     * website, and a user reaches a screen only through their group. Those
     * grants pointed at the duplicate a group's own website held, so each one
     * is moved onto the surviving row before the duplicate goes. An admin sees
     * exactly the menus they saw yesterday.
     *
     * The scoping that matters is untouched by any of this: what a screen then
     * reads is scoped by website_id off the verified token (see
     * ServiceProxy::headers and admin_website_id()), so two admins opening the
     * same Products menu still see their own catalogues.
     *
     * A row from another site with no counterpart on the canonical tree is
     * kept rather than dropped -- it becomes part of the shared tree, with its
     * parent re-pointed. There is no such row today; the three trees are
     * identical. It is handled because a migration that silently deleted a
     * menu somebody had would be the worse failure.
     */
    public function up(): void
    {
        // Already collapsed, or a fresh database whose tree MenuSeeder has yet
        // to build.
        if (!$this->hasWebsiteColumn()) {
            return;
        }

        $canonical = DB::table('menus')->whereNotNull('website_id')->min('website_id');

        if ($canonical !== null) {
            $this->collapse((int) $canonical);
        }

        Schema::table('menus', function (Blueprint $table) {
            // MySQL drops the single-column index with the column.
            $table->dropColumn('website_id');
        });
    }

    /**
     * Is the column still there?
     *
     * Asked with SHOW COLUMNS rather than Schema::hasColumn() because this
     * installation runs MariaDB 10.1, and Laravel's introspection reads
     * information_schema.columns.generation_expression -- a column MariaDB
     * only grew in 10.2, so hasColumn() fails outright here. SHOW COLUMNS is
     * understood by every version of both servers.
     */
    protected function hasWebsiteColumn(): bool
    {
        return count(DB::select("SHOW COLUMNS FROM menus LIKE 'website_id'")) > 0;
    }

    /**
     * Fold every other website's rows onto $canonical's, keyed on name_en --
     * the same key MenuSeeder matches on, and the only thing the three trees
     * ever had in common besides their shape.
     */
    protected function collapse(int $canonical): void
    {
        $rows = DB::table('menus')->get(['id', 'website_id', 'parent_id', 'name_en']);

        $keep = [];

        foreach ($rows as $row) {
            if ((int) $row->website_id === $canonical) {
                $keep[$row->name_en] = $row->id;
            }
        }

        // Duplicate id => the row it folds onto.
        $map = [];

        foreach ($rows as $row) {
            if ((int) $row->website_id === $canonical) {
                continue;
            }

            if (isset($keep[$row->name_en])) {
                $map[$row->id] = $keep[$row->name_en];
            }
        }

        if ($map === []) {
            return;
        }

        $this->moveGrants($map);

        /*
         * A survivor whose parent is going away is re-pointed at the parent
         * that stays. Canonical rows already point inside their own tree, so
         * this only ever touches a row that had no counterpart -- see the
         * class note.
         */
        foreach ($rows as $row) {
            if (isset($map[$row->id]) || $row->parent_id === null) {
                continue;
            }

            if (isset($map[$row->parent_id])) {
                DB::table('menus')->where('id', $row->id)->update(['parent_id' => $map[$row->parent_id]]);
            }
        }

        // The grants are off them by now, so the foreign key is satisfied.
        DB::table('menus')->whereIn('id', array_keys($map))->delete();
    }

    /**
     * Point every grant at the surviving menu.
     *
     * A group that somehow held both the duplicate and the survivor would end
     * up with the same menu granted twice, so the second grant is dropped
     * rather than moved: the pair (group, menu) is what the sync in
     * GroupMenuRepository treats as one row, and a duplicate would show up as
     * a menu that cannot be un-granted.
     *
     * Done row by row in PHP because MySQL will not let a DELETE or UPDATE
     * read the table it is writing through a subquery.
     */
    protected function moveGrants(array $map): void
    {
        $held = DB::table('users_menugroupdetail')
            ->whereIn('menu_id', array_values($map))
            ->get(['usergroup_id', 'menu_id']);

        $already = [];

        foreach ($held as $grant) {
            $already[$grant->usergroup_id . ':' . $grant->menu_id] = true;
        }

        foreach ($map as $duplicate => $target) {
            $grants = DB::table('users_menugroupdetail')
                ->where('menu_id', $duplicate)
                ->get(['id', 'usergroup_id']);

            foreach ($grants as $grant) {
                $key = $grant->usergroup_id . ':' . $target;

                if (isset($already[$key])) {
                    DB::table('users_menugroupdetail')->where('id', $grant->id)->delete();

                    continue;
                }

                DB::table('users_menugroupdetail')->where('id', $grant->id)->update(['menu_id' => $target]);

                $already[$key] = true;
            }
        }
    }

    /**
     * Reverse: a tree per website again.
     *
     * The shared rows become the first site's, then a copy is inserted for
     * each of the others and every group's grants are moved onto its own
     * website's copy -- which is what made this a per-website table in the
     * first place.
     *
     * Ids do not survive a round trip, and cannot: two of the three trees are
     * being created here, so only the site that kept the shared rows keeps its
     * grants pointing at the same ids. The grants themselves all survive,
     * which is what an admin would notice.
     */
    public function down(): void
    {
        if ($this->hasWebsiteColumn()) {
            return;
        }

        Schema::table('menus', function (Blueprint $table) {
            $table->unsignedBigInteger('website_id')->nullable()->index()->after('id');
        });

        $siteIds   = array_values((array) config('sites.ids'));
        $canonical = (int) ($siteIds[0] ?? 1);

        DB::table('menus')->update(['website_id' => $canonical]);

        foreach (array_slice($siteIds, 1) as $websiteId) {
            $this->copyTree($canonical, (int) $websiteId);
        }
    }

    /**
     * Copy one website's tree to another, and hand that website's groups the
     * copies.
     *
     * Parents before children, by inserting whatever is already resolvable and
     * going round again: the tree is three deep now (Commerce > Masterdata >
     * Products), so ordering by parent_id would not be enough.
     */
    protected function copyTree(int $from, int $to): void
    {
        $pending = DB::table('menus')->where('website_id', $from)->get()->all();

        // Original id => the id of its copy.
        $map = [];

        while ($pending !== []) {
            $progress = false;

            foreach ($pending as $index => $row) {
                if ($row->parent_id !== null && !isset($map[$row->parent_id])) {
                    continue;
                }

                $copy = (array) $row;
                unset($copy['id']);

                $copy['website_id'] = $to;
                $copy['parent_id']  = $row->parent_id === null ? null : $map[$row->parent_id];

                $map[$row->id] = DB::table('menus')->insertGetId($copy);

                unset($pending[$index]);
                $progress = true;
            }

            // Unreachable for a tree; a guard against looping for ever if the
            // rows ever stop being one.
            if (!$progress) {
                break;
            }
        }

        $groupIds = DB::table('users_menugroup')->where('website_id', $to)->pluck('id');

        foreach ($groupIds as $groupId) {
            $grants = DB::table('users_menugroupdetail')
                ->where('usergroup_id', $groupId)
                ->get(['id', 'menu_id']);

            foreach ($grants as $grant) {
                if (isset($map[$grant->menu_id])) {
                    DB::table('users_menugroupdetail')
                        ->where('id', $grant->id)
                        ->update(['menu_id' => $map[$grant->menu_id]]);
                }
            }
        }
    }
};
