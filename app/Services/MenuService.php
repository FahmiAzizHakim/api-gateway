<?php

namespace App\Services;

use App\Repositories\MenuRepository;
use App\Services\Gateway\ServiceStatus;
use Illuminate\Support\Facades\Log;

/**
 * The signed-in user's navigation, as a tree.
 *
 * The shape is the point: the repository answers one level at a time, and the
 * recursion that turns those levels into nested arrays is the logic that lives
 * here.
 *
 * The other thing that lives here is the pruning. A menu names the service its
 * screen cannot work without (menus.service), and a service that is not
 * answering makes that screen a page of 502s -- so the row is left out while
 * it is down. An admin sees a smaller sidebar rather than a menu that breaks
 * when clicked, which is the difference between an outage that looks like an
 * outage and one that looks like a bug.
 *
 * Grants are untouched by any of this. The row stays granted, stays in the
 * access group's tree, and comes back on its own the moment the service does.
 */
class MenuService
{
    protected $menus;
    protected $status;

    /**
     * Services answering right now, read once per build.
     *
     * Null until asked for: a tree is walked level by level, and the answer
     * must not change halfway down it.
     */
    protected $available = null;

    public function __construct(MenuRepository $menus, ServiceStatus $status)
    {
        $this->menus  = $menus;
        $this->status = $status;
    }

    /**
     * The menus $role_id may see under $parent_id, with folders carrying their
     * own children, and nothing in it that a down service would break.
     */
    public function getMenus($role_id, $parent_id = null)
    {
        $this->available = null;

        return $this->prune($this->build($role_id, $parent_id));
    }

    /**
     * The tree as it is stored: every granted, active menu, unpruned.
     *
     * Only a FOLDER recurses -- a leaf is a destination, so descending into it
     * would be a query per menu item for nothing.
     */
    protected function build($role_id, $parent_id = null)
    {
        return $this->menus->childrenForRole($role_id, $parent_id)
            ->map(function ($menu) use ($role_id) {
                $row = [
                    'id'        => $menu->id,
                    'parent_id' => $menu->parent_id,
                    'name_in'   => $menu->name_in,
                    'name_en'   => $menu->name_en,
                    'menu_url'  => $menu->menu_url,
                    'menu_icon' => $menu->menu_icon,
                    'menu_type' => $menu->menu_type,
                    'menu_desc' => $menu->menu_desc,
                    // Which service answers this screen, or null for one the
                    // gateway answers itself. Carried into the response so the
                    // frontend can say why a menu it remembers is missing.
                    'service'   => $menu->service,
                ];

                if ($row['menu_type'] === 'FOLDER') {
                    $row['children'] = $this->build($role_id, $menu->id);
                }

                return $row;
            })
            ->values()
            ->all();
    }

    /**
     * Drop what is not reachable.
     *
     * A folder goes when the outage empties it -- a Commerce folder holding
     * nothing is worse than no Commerce folder -- but only then: a folder that
     * was already empty is empty for grant reasons, which is the tree the
     * access group asked for and not this method's business.
     */
    protected function prune(array $rows): array
    {
        $kept = [];

        foreach ($rows as $row) {
            if (!$this->reachable($row['service'] ?? null)) {
                continue;
            }

            if (($row['menu_type'] ?? null) === 'FOLDER') {
                $had = count($row['children']);
                $row['children'] = $this->prune($row['children']);

                if ($had > 0 && $row['children'] === []) {
                    continue;
                }
            }

            $kept[] = $row;
        }

        return $kept;
    }

    /**
     * Is the service behind this menu answering?
     *
     * No service named is always reachable: that is a screen the gateway
     * answers itself, and it is also what an unclassified row holds -- a menu
     * nobody has assigned should stay in the sidebar rather than quietly
     * vanish from it.
     *
     * Fails open. If the check itself cannot be made, every menu is shown:
     * a sidebar missing half its rows because a health ping failed would be a
     * worse fault than the one it is meant to report, and the screens are
     * still there to be clicked.
     */
    protected function reachable($service): bool
    {
        if ($service === null || $service === '') {
            return true;
        }

        if ($this->available === null) {
            try {
                $this->available = $this->status->available();
            } catch (\Throwable $e) {
                Log::warning('gateway: menu service check failed, showing every menu: ' . $e->getMessage());

                $this->available = array_keys((array) config('gateway.services', []));
            }
        }

        return in_array($service, $this->available, true);
    }
}
