<?php

declare(strict_types=1);

namespace Dotclear\Plugin\Orphelins;

use Dotclear\App;

/**
 * @brief       Orphelins lists export.
 * @ingroup     Orphelins
 *
 * Send a list of the report as a CSV file, to sort it or study it in a spreadsheet:
 * all the lines matching the filters of the list, not only those of the page shown.
 *
 * The caller must have checked the permissions: this is called by Manage only.
 *
 * @author      Jean-Christian Paul Denis
 * @copyright   AGPL-3.0
 */
class Export
{
    /**
     * The fields separator: the one spreadsheets expect in most of the languages using a comma in numbers.
     *
     * @var     string  SEPARATOR
     */
    public const SEPARATOR = ';';

    /**
     * Send the list of a page as a CSV file and stop.
     */
    public static function send(ManageVars $vars): never
    {
        $items = Items::search($vars->itemTypes(), $vars->itemStatuses(), $vars->query, 0, 0, $vars->sortby, $vars->desc, $vars->result);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . self::filename($vars->media) . '"');
        header('Cache-Control: no-store');

        // Spreadsheets need this mark to read accents
        echo "\xEF\xBB\xBF" . self::csv($items, $vars->media);

        exit;
    }

    /**
     * Get a list as CSV.
     *
     * @param   array<int, Item>    $items  The items
     * @param   bool                $media  The items are unused media, else links and files
     */
    public static function csv(array $items, bool $media): string
    {
        $types = ManageVars::typeNames();
        $rows  = [$media ?
            [__('Media'), __('Size (bytes)'), __('Last check'), __('Status')] :
            [__('Address'), __('Type'), __('Entry'), __('Entry ID'), __('Result'), __('Result details'), __('HTTP code'), __('Last check'), __('Status')]];

        foreach ($items as $item) {
            $status = Manage::statusLabel($item->status)[0];
            $date   = Manage::date($item->checked);

            if ($media) {
                $size   = @filesize(App::media()->getRoot() . '/' . $item->url);
                $rows[] = [$item->url, $size === false ? '' : (string) $size, $date, $status];
            } else {
                $rows[] = [
                    $item->url,
                    $types[$item->type] ?? '',
                    $item->post_title,
                    (string) $item->post_id,
                    Manage::info($item),
                    Manage::infoDetail($item),
                    $item->code > 0 ? (string) $item->code : '',
                    $date,
                    $status,
                ];
            }
        }

        return implode("\r\n", array_map(
            fn (array $row): string => implode(self::SEPARATOR, array_map(self::cell(...), $row)),
            $rows
        )) . "\r\n";
    }

    /**
     * Get a value ready for a CSV file.
     *
     * Addresses and titles are written by the authors of the blog: a value starting like a formula
     * is marked as text, so a spreadsheet does not run it.
     */
    private static function cell(string $value): string
    {
        $value = (string) preg_replace('/[\r\n\t]+/', ' ', $value);
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            $value = "'" . $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }

    private static function filename(bool $media): string
    {
        return strtolower(My::id()) . '-' . ($media ? 'media' : 'links') . '-' . date('Y-m-d') . '.csv';
    }
}
