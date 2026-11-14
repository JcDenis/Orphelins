<?php

declare(strict_types=1);

namespace Dotclear\Plugin\Orphelins;

use ArrayObject;
use Dotclear\App;
use Dotclear\Core\Backend\Favorites;
use Dotclear\Core\Backend\UserPrefFilter;
use Dotclear\Plugin\maintenance\Maintenance;
use Dotclear\Plugin\Orphelins\Maintenance\CheckTask;

/**
 * @brief       Orphelins backend behaviors.
 * @ingroup     Orphelins
 *
 * Everything here is only registered for users allowed to use the plugin on the blog, see Backend.
 *
 * @author      Jean-Christian Paul Denis
 * @copyright   AGPL-3.0
 */
class BackendBehaviors
{
    /**
     * Display options of the two lists (sort and number per page), also set from user preferences.
     *
     * @param   ArrayObject<int, mixed>     $filters    The lists options
     */
    public static function adminFiltersListsV3(ArrayObject $filters): string
    {
        $filters->append(new UserPrefFilter(
            My::id(),
            My::name(),
            ManageVars::sortbyCombo(),
            ManageVars::SORTBY,
            'desc',
            __('lines per page'),
            ManageVars::PER_PAGE
        ));
        $filters->append(new UserPrefFilter(
            ManageVars::FILTERS_MEDIA,
            __('Unused media'),
            ManageVars::sortbyComboMedia(),
            ManageVars::SORTBY,
            'desc',
            __('media per page'),
            ManageVars::PER_PAGE
        ));

        return '';
    }

    /**
     * Favorite: users choose to put it in their menu and on their dashboard from their preferences.
     */
    public static function adminDashboardFavoritesV2(Favorites $favs): string
    {
        $favs->register(My::id(), [
            'title'          => My::name(),
            'url'            => My::manageUrl(),
            'permissions'    => App::auth()->makePermissions([App::auth()::PERMISSION_CONTENT_ADMIN]),
            'active_cb'      => self::adminDashboardFavoritesActive(...),
            'dashboard_cb'   => self::adminDashboardFavoritesCallback(...),
            'menu-icon'      => My::icon(),
            'dashboard-icon' => My::icon(),
        ]);

        return '';
    }

    /**
     * Is a page of the plugin displayed.
     *
     * @param   string                  $request    The request
     * @param   array<string, mixed>    $params     The parameters
     */
    public static function adminDashboardFavoritesActive(string $request, array $params): bool
    {
        return isset($params['p']) && $params['p'] === My::id();
    }

    /**
     * The dashboard icon tells how many items are to fix.
     *
     * @param   ArrayObject<string, mixed>  $icon   The icon
     */
    public static function adminDashboardFavoritesCallback(ArrayObject $icon): void
    {
        $count = Items::countProblems();
        if ($count > 0) {
            $icon['title'] = sprintf(__('One problem to fix', '%d problems to fix', $count), $count);
        }
    }

    /**
     * The check is also a task of the plugin maintenance, which reminds to do it again from time to time.
     */
    public static function dcMaintenanceInit(Maintenance $maintenance): string
    {
        $maintenance->addTask(CheckTask::class);

        return '';
    }
}
