<?php

declare(strict_types=1);

namespace Dotclear\Plugin\Orphelins;

use Dotclear\App;
use Dotclear\Core\Backend\Listing\Pager;
use Dotclear\Helper\Date;
use Dotclear\Helper\File\Files;
use Dotclear\Helper\Html\Form\Caption;
use Dotclear\Helper\Html\Form\Checkbox;
use Dotclear\Helper\Html\Form\Div;
use Dotclear\Helper\Html\Form\Form;
use Dotclear\Helper\Html\Form\Hidden;
use Dotclear\Helper\Html\Form\Img;
use Dotclear\Helper\Html\Form\Label;
use Dotclear\Helper\Html\Form\Li;
use Dotclear\Helper\Html\Form\Link;
use Dotclear\Helper\Html\Form\Note;
use Dotclear\Helper\Html\Form\Option;
use Dotclear\Helper\Html\Form\Para;
use Dotclear\Helper\Html\Form\Select;
use Dotclear\Helper\Html\Form\Submit;
use Dotclear\Helper\Html\Form\Table;
use Dotclear\Helper\Html\Form\Tbody;
use Dotclear\Helper\Html\Form\Td;
use Dotclear\Helper\Html\Form\Text;
use Dotclear\Helper\Html\Form\Th;
use Dotclear\Helper\Html\Form\Thead;
use Dotclear\Helper\Html\Form\Tr;
use Dotclear\Helper\Html\Form\Ul;
use Dotclear\Helper\Html\Html;
use Dotclear\Helper\Process\TraitProcess;
use Exception;
use Throwable;

/**
 * @brief       Orphelins report management.
 * @ingroup     Orphelins
 *
 * The result of the last check of current blog, in two lists on two pages (pseudo tabs):
 * the links and the files of the entries, and the unused media of the media library.
 *
 * The check is started and followed on another page, opened by the button on top of the lists.
 *
 * @author      Jean-Christian Paul Denis
 * @copyright   AGPL-3.0
 */
class Manage
{
    use TraitProcess;

    /**
     * The longest address shown in the list, in characters.
     *
     * @var     int     URL_LENGTH
     */
    private const URL_LENGTH = 70;

    private static ManageVars $vars;

    /**
     * Show the page of the check.
     */
    private static bool $check = false;

    public static function init(): bool
    {
        if (self::status(My::checkContext(My::MANAGE))) {
            self::$vars = new ManageVars();

            self::$check = self::$vars->check && ManageCheck::init();
        }

        return self::status();
    }

    public static function process(): bool
    {
        if (!self::status()) {
            return false;
        }

        if (self::$check) {
            return ManageCheck::process();
        }

        $vars = self::$vars;

        if ($vars->export) {
            Export::send($vars);
        }

        try {
            // Selected items action
            if (isset($_POST['do-action'])) {
                $ids = [];
                if (isset($_POST['items']) && is_array($_POST['items'])) {
                    foreach ($_POST['items'] as $id) {
                        if (is_numeric($id) && (int) $id > 0) {
                            $ids[] = (int) $id;
                        }
                    }
                }

                // Only lines of the list shown
                $items = array_values(array_filter(
                    Items::getByIds($ids),
                    fn (Item $item): bool => in_array($item->type, $vars->itemTypes(), true)
                ));
                if ($items === []) {
                    throw new Exception(__('No line selected.'));
                }
                $ids = array_map(fn (Item $item): int => $item->id, $items);

                switch (ManageVars::post('action')) {
                    case 'ignore':
                        Items::setStatus($ids, Items::STATUS_IGNORED);
                        $message = __('Selected lines are now ignored.');

                        break;
                    case 'forget':
                        // Nothing is kept: the next check finds these lines again if they are still there
                        Items::delete($ids);
                        $message = __('Selected lines are forgotten until the next check.');

                        break;
                    case 'recheck':
                        // Also the way to stop ignoring a line
                        Items::setStatus($ids, Items::STATUS_PENDING);
                        $left    = Checker::recheck(Items::getByIds($ids));
                        $message = __('Selected lines have been checked again.');
                        if ($left > 0) {
                            $message .= ' ' . sprintf(__('%d link is still waiting for the next check.', '%d links are still waiting for the next check.', $left), $left);
                        }

                        break;
                    case 'delete':
                        if (!$vars->media) {
                            throw new Exception(__('Unknown action.'));
                        }
                        $message = self::deleteMedia($items);

                        break;
                    default:
                        throw new Exception(__('Unknown action.'));
                }

                App::backend()->notices()->addSuccessNotice($message);
                My::redirect($vars->params());
            }
        } catch (Exception $exception) {
            App::error()->add($exception->getMessage());
        }

        return true;
    }

    public static function render(): void
    {
        if (!self::status()) {
            return;
        }

        if (self::$check) {
            ManageCheck::render();

            return;
        }

        $vars  = self::$vars;
        $total = Items::count($vars->itemTypes(), $vars->itemStatuses(), $vars->query, $vars->result);
        $page  = min($vars->page, max(1, (int) ceil($total / $vars->nb)));
        $items = Items::search($vars->itemTypes(), $vars->itemStatuses(), $vars->query, $vars->nb, ($page - 1) * $vars->nb, $vars->sortby, $vars->desc, $vars->result);
        $part  = $vars->media ? ['part' => ManageVars::PART_MEDIA] : [];

        App::backend()->page()->openModule(My::name(), self::head() . $vars->filters->js(My::manageUrl($part, '&')));

        echo
        App::backend()->page()->breadcrumb([
            Html::escapeHTML(App::blog()->name()) => '',
            My::name()                            => '',
        ]) .
        // The two lists of the plugin are two pages
        (new Div())
            ->class('pseudo-tabs')
            ->items([
                (new Ul())
                    ->items([
                        (new Li())
                            ->class($vars->media ? '' : 'active')
                            ->items([
                                (new Link())
                                    ->href(My::manageUrl())
                                    ->text(__('Links and files')),
                            ]),
                        (new Li())
                            ->class($vars->media ? 'active' : '')
                            ->items([
                                (new Link())
                                    ->href(My::manageUrl(['part' => ManageVars::PART_MEDIA]))
                                    ->text(__('Unused media')),
                            ]),
                    ]),
            ])
            ->render() .
        App::backend()->notices()->getNotices() .
        (new Para())
            ->class('new-stuff')
            ->items([
                (new Link())
                    ->class(['button', 'add'])
                    ->href(My::manageUrl([... $vars->params(), 'check' => 1]))
                    ->text(Checker::isRunning() ? __('Resume the check') : __('New check')),
            ])
            ->render() .
        self::summary()->render();

        // Filters and display options, folded like in other lists of Dotclear
        $vars->filters->display('admin.plugin.' . My::id(), $vars->media ? (new Hidden('part', ManageVars::PART_MEDIA))->render() : '');

        if ($items === []) {
            if ($vars->filters->show()) {
                $message = __('No line matches the filter.');
            } elseif (Checker::lastCheck() === 0) {
                $message = __('Start a first check with the button "New check".');
            } else {
                $message = __('There is nothing to fix.');
            }

            echo (new Note())
                ->class('info')
                ->text($message)
                ->render();
        } else {
            $pager = new Pager($page, $total, $vars->nb, 10);

            echo
            $pager->getLinks() .
            self::itemsForm($items, $total)->render() .
            $pager->getLinks() .
            (new Para())
                ->items([
                    (new Link())
                        ->class('button')
                        // All the lines of the list, not only those of the page
                        ->href(My::manageUrl([... array_diff_key($vars->params(), ['page' => 0]), 'export' => 1]))
                        ->text(sprintf(__('Export this list as a CSV file (%d line)', 'Export this list as a CSV file (%d lines)', $total), $total)),
                ])
                ->render();
        }

        App::backend()->page()->helpBlock($vars->media ? My::id() . '-media' : My::id());

        App::backend()->page()->closeModule();
    }

    /**
     * Get the request values of the page.
     */
    public static function vars(): ManageVars
    {
        return self::$vars;
    }

    /**
     * Get page head.
     */
    public static function head(): string
    {
        return App::backend()->page()->jsJson('orphelins', [
            'confirm_delete' => __('Are you sure you want to delete selected media from the media library? This can not be undone.'),
        ]) .
        My::jsLoad('manage');
    }

    /**
     * Get a date of the plugin (server time), as the user reads it.
     */
    public static function date(string $date): string
    {
        $user_tz = is_string($user_tz = App::auth()->getInfo('user_tz')) ? $user_tz : 'UTC';

        return $date === '' ? '' : Date::dt2str(__('%Y-%m-%d %H:%M'), $date, $user_tz);
    }

    /**
     * Get the result of the check of an item, in one or two words.
     */
    public static function info(Item $item): string
    {
        return ManageVars::resultName($item->result());
    }

    /**
     * Get the result of the check of an item, in a sentence.
     */
    public static function infoDetail(Item $item): string
    {
        if ($item->status === Items::STATUS_PENDING) {
            return __('Waiting for its check');
        }

        [$info, $detail] = [... explode(' ', $item->info, 2), ''];

        return match ($info) {
            Items::INFO_HTTP      => sprintf(__('Answer of the site: %s'), $item->code),
            Items::INFO_REDIRECT  => $item->status === Items::STATUS_OK ? sprintf(__('Moved to %s'), $detail) : sprintf(__('Moved to %s, which does not answer correctly'), $detail),
            Items::INFO_REDIRECTS => __('Too many redirections'),
            Items::INFO_HOST      => __('This site does not exist'),
            Items::INFO_CONNECT   => __('This site does not answer'),
            Items::INFO_TIMEOUT   => __('No answer in time'),
            Items::INFO_DOMAIN    => __('Domain not to check'),
            Items::INFO_LOCAL     => __('Local address, not checked'),
            Items::INFO_FOUND     => __('File found'),
            Items::INFO_MISSING   => __('File not found in the media library'),
            Items::INFO_UNUSED    => __('Used by no entry'),
            default               => '',
        };
    }

    /**
     * Get the name and the icon of a status.
     *
     * @return  array{0: string, 1: string, 2: string}  The name, the image and the class of the image
     */
    public static function statusLabel(int $status): array
    {
        return match ($status) {
            Items::STATUS_OK      => [__('Without problem'), 'images/check-on.svg', 'mark-check-on'],
            Items::STATUS_BROKEN  => [__('Problem'), 'images/check-off.svg', 'mark-check-off'],
            Items::STATUS_UNSURE  => [__('To verify'), 'images/check-wrn.svg', 'mark-check-wrn'],
            Items::STATUS_IGNORED => [__('Ignored'), 'images/junk.svg', 'mark-junk'],
            default               => [__('Not checked'), 'images/pending.svg', 'mark-pending'],
        };
    }

    /**
     * Delete from the media library the unused media of some items.
     *
     * The list may be old: each media is looked for again in the entries before being deleted.
     * The core checks the permissions of the user on the media.
     *
     * @param   array<int, Item>    $items  The selected items
     *
     * @throws  Exception   If nothing can be deleted
     *
     * @return  string  The success message
     */
    private static function deleteMedia(array $items): string
    {
        $ids = [];
        foreach ($items as $item) {
            if ($item->type === Items::TYPE_ORPHAN) {
                $ids[] = $item->id;
            }
        }
        if ($ids === []) {
            throw new Exception(__('Only unused media can be deleted.'));
        }

        Checker::recheck(Items::getByIds($ids));

        $deleted = 0;
        $kept    = count($ids);
        $error   = '';
        foreach (Items::getByIds($ids) as $item) {
            if ($item->status === Items::STATUS_IGNORED) {
                continue;
            }

            try {
                App::media()->removeFile($item->url);
            } catch (Throwable $exception) {
                // Not always a failure: the core deletes the media from the media library, then fails on a file already missing
                $error = $exception->getMessage();
            }

            // What happened is read in the media library, and the lines are deleted here: not all databases do it by themselves
            if (!Items::mediaExists($item->media_id)) {
                Items::deleteOfMedia($item->media_id);
                $deleted++;
                $kept--;
            }
        }

        if ($deleted === 0) {
            throw new Exception($error !== '' ? $error : __('Selected media are used by an entry since the last check: they have not been deleted.'));
        }

        $message = sprintf(__('%d media file deleted.', '%d media files deleted.', $deleted), $deleted);
        if ($kept > 0) {
            $message .= ' ' . sprintf(__('%d media file kept (used since the last check, ignored or not allowed).', '%d media files kept (used since the last check, ignored or not allowed).', $kept), $kept);
        }

        return $message;
    }

    /**
     * Get the summary of the last check, for the list shown.
     */
    private static function summary(): Note
    {
        $last = Checker::lastCheck();
        if ($last === 0) {
            return (new Note())
                ->text(__('This blog has not been checked yet.'));
        }

        $counts = Items::counts();
        $count  = fn (int $type): int => $counts[$type][Items::STATUS_BROKEN] + $counts[$type][Items::STATUS_UNSURE];
        $date   = Html::escapeHTML(self::date(date('Y-m-d H:i:s', $last)));

        $number = fn (int $type, string $one, string $many): string => sprintf(__($one, $many, $count($type)), $count($type));
        $to_fix = self::$vars->media ?
            [$number(Items::TYPE_ORPHAN, '%d unused media file', '%d unused media files')] :
            [$number(Items::TYPE_LINK, '%d link', '%d links'), $number(Items::TYPE_FILE, '%d missing file', '%d missing files')];

        return (new Note())
            ->text(sprintf(__('Last check: %1$s. To fix: %2$s.'), $date, implode(', ', $to_fix)));
    }

    /**
     * @param   array<int, Item>    $items  The items of the page
     * @param   int                 $total  The number of items matching the filter
     */
    private static function itemsForm(array $items, int $total): Form
    {
        $vars  = self::$vars;
        $types = ManageVars::typeNames();
        $rows  = [];
        foreach ($items as $item) {
            [$status, $image, $class] = self::statusLabel($item->status);

            $url  = mb_strlen($item->url) > self::URL_LENGTH ? mb_substr($item->url, 0, self::URL_LENGTH - 1) . '…' : $item->url;
            $cols = [
                (new Td())
                    ->class(['nowrap', 'minimal'])
                    ->items([
                        (new Checkbox(['items[]', 'items_' . $item->id]))
                            ->value($item->id)
                            ->title(__('Select this line')),
                    ]),
                (new Td())
                    // On the list of links, the entry title needs room too
                    ->class($vars->media ? 'maximal' : '')
                    ->items([
                        $vars->media && !$item->fileExists() ?
                            // The media page of the core can not open a media without its file
                            (new Text('span', Html::escapeHTML($url)))
                                ->title(Html::escapeHTML($item->url)) :
                            (new Link())
                                // The media page of the core shows the media and allows to check it by hand
                                ->href($vars->media ? App::backend()->url()->get('admin.media.item', ['id' => $item->media_id]) : Html::escapeHTML($item->url))
                                ->title(Html::escapeHTML($item->url))
                                ->text(Html::escapeHTML($url)),
                    ]),
            ];

            if ($vars->media) {
                $size   = @filesize(App::media()->getRoot() . '/' . $item->url);
                $cols[] = (new Td())
                    ->class('nowrap')
                    ->text($size === false ? Html::escapeHTML(__('Missing')) : Html::escapeHTML(Files::size($size)));
            } else {
                $cols[] = (new Td())
                    ->class('nowrap')
                    ->text($types[$item->type] ?? '');
                $cols[] = (new Td())
                    ->items([
                        (new Link())
                            ->href(App::postTypes()->get($item->post_type)->adminUrl($item->post_id))
                            ->title(__('Edit this entry'))
                            ->text(Html::escapeHTML($item->post_title)),
                    ]);
                $cols[] = (new Td())
                    ->class('nowrap')
                    ->title(Html::escapeHTML(self::infoDetail($item)))
                    ->text(Html::escapeHTML(self::info($item)));
            }

            $cols[] = (new Td())
                ->class('nowrap')
                ->text(Html::escapeHTML(self::date($item->checked)));
            $cols[] = (new Td())
                ->class(['nowrap', 'status'])
                ->items([
                    (new Img($image))
                        ->class(['mark', $class])
                        ->alt($status),
                    (new Text(null, $status)),
                ]);

            $rows[] = (new Tr('item_' . $item->id))
                ->class(in_array($item->status, [Items::STATUS_IGNORED, Items::STATUS_SKIPPED], true) ? ['line', 'offline'] : 'line')
                ->cols($cols);
        }

        if ($vars->filters->show()) {
            $caption = sprintf(__('List of %d line matching the filter.', 'List of %d lines matching the filter.', $total), $total);
        } elseif ($vars->media) {
            $caption = sprintf(__('List of unused media (%s)'), $total);
        } else {
            $caption = sprintf(__('List of links and files to fix (%s)'), $total);
        }

        $heads = $vars->media ? [
            (new Th())->text(__('Media'))->scope('col')->colspan(2)->class('first'),
            (new Th())->text(__('Size'))->scope('col'),
        ] : [
            (new Th())->text(__('Address'))->scope('col')->colspan(2)->class('first'),
            (new Th())->text(__('Type'))->scope('col'),
            (new Th())->text(__('Entry'))->scope('col'),
            (new Th())->text(__('Result'))->scope('col'),
        ];

        $actions = [
            new Option(__('Check again'), 'recheck'),
            new Option(__('Ignore'), 'ignore'),
            new Option(__('Forget until the next check'), 'forget'),
        ];
        if ($vars->media) {
            $actions[] = new Option(__('Delete from the media library'), 'delete');
        }

        return (new Form('items-form'))
            ->method('post')
            ->action(My::manageUrl($vars->params()))
            ->fields([
                (new Div())
                    ->class('table-outer')
                    ->items([
                        (new Table('items'))
                            ->caption(new Caption($caption))
                            ->thead((new Thead())
                                ->rows([
                                    (new Tr())
                                        ->cols([
                                            ...$heads,
                                            (new Th())->text(__('Last check'))->scope('col'),
                                            (new Th())->text(__('Status'))->scope('col'),
                                        ]),
                                ]))
                            ->tbody((new Tbody())->rows($rows)),
                    ]),
                (new Div())
                    ->class('two-cols')
                    ->items([
                        (new Para())
                            ->class(['col', 'checkboxes-helpers']),
                        (new Para())
                            ->class(['col', 'right', 'form-buttons'])
                            ->items([
                                (new Select('action'))
                                    ->items($actions)
                                    ->label((new Label(__('Selected lines action:'), Label::IL_TF))),
                                (new Submit('do-action', __('ok'))),
                                ... My::hiddenFields(),
                            ]),
                    ]),
            ]);
    }
}
