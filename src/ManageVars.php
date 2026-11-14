<?php

declare(strict_types=1);

namespace Dotclear\Plugin\Orphelins;

use Dotclear\Core\Backend\Filter\Filters;
use Dotclear\Core\Backend\Filter\FiltersLibrary;
use Dotclear\Helper\Stack\Filter;

/**
 * @brief       Orphelins manage page request values.
 * @ingroup     Orphelins
 *
 * The items list uses the filters and display options of Dotclear lists.
 *
 * @author      Jean-Christian Paul Denis
 * @copyright   AGPL-3.0
 */
class ManageVars
{
    /**
     * The default number of items per page.
     *
     * @var     int     PER_PAGE
     */
    public const PER_PAGE = 30;

    /**
     * The default sort field.
     *
     * @var     string  SORTBY
     */
    public const SORTBY = 'item_dt';

    /**
     * The type filter values (no value means all types).
     *
     * @var     array<string, int>  TYPES
     */
    public const TYPES = [
        'link'  => Items::TYPE_LINK,
        'file'  => Items::TYPE_FILE,
        'media' => Items::TYPE_ORPHAN,
    ];

    /**
     * The status filter values (no value means items to fix).
     *
     * @var     array<string, array<int, int>>  STATUSES
     */
    public const STATUSES = [
        ''          => Items::PROBLEMS,
        'broken'    => [Items::STATUS_BROKEN],
        'unsure'    => [Items::STATUS_UNSURE],
        'ignored'   => [Items::STATUS_IGNORED],
        'ok'        => [Items::STATUS_OK],
        'unchecked' => [Items::STATUS_PENDING, Items::STATUS_SKIPPED],
        'all'       => [],
    ];

    /**
     * The list filters and display options.
     */
    public readonly Filters $filters;

    /**
     * The type to show, empty for all types.
     */
    public readonly string $type;

    /**
     * The status to show, empty for items to fix.
     */
    public readonly string $status;

    /**
     * The searched text.
     */
    public readonly string $query;

    /**
     * The page number.
     */
    public readonly int $page;

    /**
     * The number of items per page.
     */
    public readonly int $nb;

    /**
     * The sort field.
     */
    public readonly string $sortby;

    /**
     * The sort order is descending.
     */
    public readonly bool $desc;

    /**
     * Show the page of the check.
     */
    public readonly bool $check;

    public function __construct()
    {
        // Sort and number per page options come from user preferences, see Backend
        $this->filters = new Filters(My::id());
        $this->filters->add([
            FiltersLibrary::getPageFilter(),
            FiltersLibrary::getSearchFilter(),
            self::typeFilter(),
            self::statusFilter(),
        ]);

        $nb     = (int) $this->value('nb');
        $sortby = $this->value('sortby');
        $type   = $this->value('type');
        $status = $this->value('status');

        $this->type   = isset(self::TYPES[$type]) ? $type : '';
        $this->status = isset(self::STATUSES[$status]) ? $status : '';
        $this->query  = mb_substr(trim($this->value('q')), 0, 100);
        $this->page   = max(1, (int) $this->value('page'));
        $this->nb     = $nb > 0 ? $nb : self::PER_PAGE;
        $this->sortby = in_array($sortby, Items::SORTS, true) ? $sortby : self::SORTBY;
        $this->desc   = $this->value('order') !== 'asc';

        $this->check = self::request('check') !== '';
    }

    /**
     * Get the items type to show, 0 for all.
     */
    public function itemType(): int
    {
        return self::TYPES[$this->type] ?? 0;
    }

    /**
     * Get the items statuses to show, none for all.
     *
     * @return  array<int, int>
     */
    public function itemStatuses(): array
    {
        return self::STATUSES[$this->status];
    }

    /**
     * Get the URL parameters to come back to the same list.
     *
     * Only the values which are not the default ones.
     *
     * @return  array<string, string|int>
     */
    public function params(): array
    {
        $order  = $this->desc ? 'desc' : 'asc';
        $params = [];
        if ($this->type !== '') {
            $params['type'] = $this->type;
        }
        if ($this->status !== '') {
            $params['status'] = $this->status;
        }
        if ($this->query !== '') {
            $params['q'] = $this->query;
        }
        if ($this->filters->userOptionSortby() !== '' && $this->sortby !== $this->filters->userOptionSortby()) {
            $params['sortby'] = $this->sortby;
        }
        if ($this->filters->userOptionOrder() !== '' && $order !== $this->filters->userOptionOrder()) {
            $params['order'] = $order;
        }
        if ($this->filters->userOptionNb() > 0 && $this->nb !== $this->filters->userOptionNb()) {
            $params['nb'] = $this->nb;
        }
        if ($this->page > 1) {
            $params['page'] = $this->page;
        }

        return $params;
    }

    /**
     * Get the sort fields of the items list.
     *
     * @return  array<string, string>
     */
    public static function sortbyCombo(): array
    {
        return [
            __('Last check') => 'item_dt',
            __('Address')    => 'item_url',
            __('Entry')      => 'post_title',
            __('Type')       => 'item_type',
            __('Status')     => 'item_status',
        ];
    }

    /**
     * Get the names of the items types.
     *
     * @return  array<int, string>
     */
    public static function typeNames(): array
    {
        return [
            Items::TYPE_LINK   => __('Link'),
            Items::TYPE_FILE   => __('File'),
            Items::TYPE_ORPHAN => __('Unused media'),
        ];
    }

    /**
     * Get a text from the request.
     */
    public static function request(string $name): string
    {
        return isset($_REQUEST[$name]) && is_string($_REQUEST[$name]) ? trim($_REQUEST[$name]) : '';
    }

    /**
     * Get a text from a submitted form.
     */
    public static function post(string $name): string
    {
        return isset($_POST[$name]) && is_string($_POST[$name]) ? trim($_POST[$name]) : '';
    }

    private static function typeFilter(): Filter
    {
        return (new Filter('type'))
            ->title(__('Type:'))
            ->options([
                __('All types')     => '',
                __('Links')         => 'link',
                __('Files')         => 'file',
                __('Unused media')  => 'media',
            ])
            ->prime(true);
    }

    /**
     * Get the status filter: without value, items to fix are shown.
     */
    private static function statusFilter(): Filter
    {
        return (new Filter('status'))
            ->title(__('Status:'))
            ->options([
                __('To fix')       => '',
                __('Problem')      => 'broken',
                __('To verify')    => 'unsure',
                __('Ignored')      => 'ignored',
                __('Without problem') => 'ok',
                __('Not checked')  => 'unchecked',
                __('All statuses') => 'all',
            ])
            ->prime(true);
    }

    /**
     * Get a filter value as a text.
     */
    private function value(string $id): string
    {
        $value = $this->filters->value($id);

        return is_scalar($value) ? (string) $value : '';
    }
}
