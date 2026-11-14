<?php

declare(strict_types=1);

namespace Dotclear\Plugin\Orphelins;

use Dotclear\App;
use Dotclear\Module\MyPlugin;

/**
 * @brief       Orphelins module definition.
 * @ingroup     Orphelins
 *
 * @author      Jean-Christian Paul Denis
 * @copyright   AGPL-3.0
 */
class My extends MyPlugin
{
    public static function checkCustomContext(int $context): ?bool
    {
        return match ($context) {
            // The report lists the links of all the entries of the blog: it is for users who manage all of them
            self::BACKEND, self::MENU, self::MANAGE => App::task()->checkContext('BACKEND')
                && App::blog()->isDefined()
                && self::isAllowed(),

            default => null,
        };
    }

    /**
     * Check if current user can read and run the check of current blog.
     *
     * No permission of its own: this is the core permission to manage all the entries of the blog.
     */
    public static function isAllowed(): bool
    {
        return App::auth()->check(App::auth()->makePermissions([App::auth()::PERMISSION_CONTENT_ADMIN]), App::blog()->id());
    }
}
