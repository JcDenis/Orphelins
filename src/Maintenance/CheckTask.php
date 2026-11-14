<?php

declare(strict_types=1);

namespace Dotclear\Plugin\Orphelins\Maintenance;

use Dotclear\App;
use Dotclear\Plugin\maintenance\MaintenanceTask;
use Dotclear\Plugin\Orphelins\Checker;
use Dotclear\Plugin\Orphelins\My;

/**
 * @brief       Orphelins task for the plugin maintenance: check the links and the media of the blog.
 * @ingroup     Orphelins
 *
 * The same check as the one of the page of the plugin, with the reminders of the plugin maintenance.
 * This class is only loaded by the plugin maintenance.
 *
 * @author      Jean-Christian Paul Denis
 * @copyright   AGPL-3.0
 */
class CheckTask extends MaintenanceTask
{
    /**
     * Task ID.
     *
     * @var     string  ID
     */
    public const ID = 'OrphelinsCheck';

    /**
     * Task ID (class name).
     */
    protected ?string $id = self::ID;

    /**
     * Task use AJAX.
     */
    protected bool $ajax = true;

    /**
     * Task limited to current blog.
     */
    protected bool $blog = true;

    /**
     * Task group container.
     */
    protected string $group = 'other';

    /**
     * Next step label.
     */
    protected string $step_task;

    protected function init(): void
    {
        $this->perm      = App::auth()::PERMISSION_CONTENT_ADMIN;
        $this->name      = __('Links and media');
        $this->task      = __('Check links and media of the entries');
        $this->step_task = __('Next');
        $this->success   = __('Links and media check done.');
        $this->error     = __('Failed to check links and media.');

        $this->description = __('Look for dead links, missing files and unused media of the blog. The result is on the page of the plugin.');
    }

    public function execute(): bool|int
    {
        if (!My::isAllowed()) {
            return false;
        }

        // A check started from the page of the plugin is continued
        if (!Checker::isRunning()) {
            if ((int) $this->code > 0) {
                // The check has been stopped from the page of the plugin
                return true;
            }
            Checker::start();
        }

        if (Checker::step()) {
            return true;
        }

        $this->code = (int) $this->code + 1;

        return $this->code;
    }

    public function task(): string
    {
        return (bool) $this->code ? $this->step_task : $this->task;
    }

    public function step(): ?string
    {
        return (bool) $this->code ? Checker::message() : null;
    }

    public function success(): string
    {
        return (bool) $this->code && Checker::isRunning() ? Checker::message() : $this->success;
    }
}
