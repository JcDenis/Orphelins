<?php

declare(strict_types=1);

namespace Dotclear\Plugin\Orphelins;

use Dotclear\App;
use Dotclear\Core\Backend\Menus;
use Dotclear\Helper\Process\TraitProcess;

/**
 * @brief       Orphelins backend class.
 * @ingroup     Orphelins
 *
 * @author      Jean-Christian Paul Denis
 * @copyright   AGPL-3.0
 */
class Backend
{
    use TraitProcess;

    public static function init(): bool
    {
        return self::status(My::checkContext(My::BACKEND));
    }

    public static function process(): bool
    {
        if (!self::status()) {
            return false;
        }

        My::addBackendMenuItem(Menus::MENU_BLOG);

        App::behavior()->addBehaviors([
            'adminFiltersListsV3'       => BackendBehaviors::adminFiltersListsV3(...),
            'adminDashboardFavoritesV2' => BackendBehaviors::adminDashboardFavoritesV2(...),
            'dcMaintenanceInit'         => BackendBehaviors::dcMaintenanceInit(...),
        ]);

        return true;
    }
}
