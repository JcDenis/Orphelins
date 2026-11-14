<?php

declare(strict_types=1);

namespace Dotclear\Plugin\Orphelins;

use Dotclear\Helper\Process\TraitProcess;
use Dotclear\Plugin\Uninstaller\Uninstaller;

/**
 * @brief       Orphelins module uninstall.
 * @ingroup     Orphelins
 *
 * @author      Jean-Christian Paul Denis
 * @copyright   AGPL-3.0
 */
class Uninstall
{
    use TraitProcess;

    public static function init(): bool
    {
        return self::status(My::checkContext(My::UNINSTALL));
    }

    public static function process(): bool
    {
        if (!self::status()) {
            return false;
        }

        Uninstaller::instance()
            ->addUserAction(
                'tables',
                'delete',
                Items::TABLE_NAME
            )
            ->addUserAction(
                'settings',
                'delete_all',
                My::id()
            )
            ->addUserAction(
                'plugins',
                'delete',
                My::id()
            )
            ->addUserAction(
                'versions',
                'delete',
                My::id()
            )
            ->addDirectAction(
                'tables',
                'delete',
                Items::TABLE_NAME
            )
            ->addDirectAction(
                'settings',
                'delete_all',
                My::id()
            )
            ->addDirectAction(
                'plugins',
                'delete',
                My::id()
            )
            ->addDirectAction(
                'versions',
                'delete',
                My::id()
            )
        ;

        // no custom action
        return false;
    }
}
