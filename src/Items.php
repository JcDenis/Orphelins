<?php

declare(strict_types=1);

namespace Dotclear\Plugin\Orphelins;

use Dotclear\App;
use Dotclear\Database\Cursor;
use Dotclear\Database\MetaRecord;
use Dotclear\Database\Statement\DeleteStatement;
use Dotclear\Database\Statement\JoinStatement;
use Dotclear\Database\Statement\SelectStatement;
use Dotclear\Database\Statement\UpdateStatement;
use Dotclear\Helper\Text;
use Throwable;

/**
 * @brief       Orphelins items handler.
 * @ingroup     Orphelins
 *
 * All database work on the table of checked items: the links and the files used by the entries of a blog,
 * and the media of its media library no entry uses.
 *
 * All methods work on current blog and do NOT check permissions: callers must do it.
 *
 * @author      Jean-Christian Paul Denis
 * @copyright   AGPL-3.0
 */
class Items
{
    /**
     * The items table name (lower case, whatever the database is).
     *
     * @var     string  TABLE_NAME
     */
    public const TABLE_NAME = 'orphelins';

    /**
     * A link of an entry, checked on the web.
     *
     * @var     int     TYPE_LINK
     */
    public const TYPE_LINK = 1;

    /**
     * A file of the media library used by an entry, looked for on the disk.
     *
     * @var     int     TYPE_FILE
     */
    public const TYPE_FILE = 2;

    /**
     * A media of the media library no entry uses.
     *
     * @var     int     TYPE_ORPHAN
     */
    public const TYPE_ORPHAN = 3;

    /**
     * Waiting for its check.
     *
     * @var     int     STATUS_PENDING
     */
    public const STATUS_PENDING = 0;

    /**
     * @var     int     STATUS_OK
     */
    public const STATUS_OK = 1;

    /**
     * Dead link, missing file or unused media.
     *
     * @var     int     STATUS_BROKEN
     */
    public const STATUS_BROKEN = 2;

    /**
     * The check gave no clear answer (site refusing robots, timeout…): to verify by hand.
     *
     * @var     int     STATUS_UNSURE
     */
    public const STATUS_UNSURE = 3;

    /**
     * The user asked not to hear about this item anymore.
     *
     * @var     int     STATUS_IGNORED
     */
    public const STATUS_IGNORED = 4;

    /**
     * Not checked on purpose (ignored domain, local address): evaluated again by each check.
     *
     * @var     int     STATUS_SKIPPED
     */
    public const STATUS_SKIPPED = 5;

    /**
     * The statuses of the items to fix.
     *
     * @var     array<int, int>     PROBLEMS
     */
    public const PROBLEMS = [self::STATUS_BROKEN, self::STATUS_UNSURE];

    /**
     * The results of a check, saved with the item (optionally followed by a space and a detail).
     */
    public const INFO_HTTP      = 'http';
    public const INFO_REDIRECT  = 'redirect';
    public const INFO_REDIRECTS = 'redirects';
    public const INFO_HOST      = 'host';
    public const INFO_CONNECT   = 'connect';
    public const INFO_TIMEOUT   = 'timeout';
    public const INFO_DOMAIN    = 'domain';
    public const INFO_LOCAL     = 'local';
    public const INFO_FOUND     = 'found';
    public const INFO_MISSING   = 'missing';
    public const INFO_UNUSED    = 'unused';

    /**
     * @var     int     INFO_LENGTH
     */
    public const INFO_LENGTH = 255;

    /**
     * The fields a list of items can be sorted by.
     *
     * @var     array<int, string>  SORTS
     */
    public const SORTS = ['item_url', 'post_title', 'item_type', 'item_status', 'item_dt'];

    /**
     * Get full table name.
     */
    public static function table(): string
    {
        return App::db()->con()->prefix() . self::TABLE_NAME;
    }

    /**
     * Get the key of an address: items with the same address share their check.
     */
    public static function hash(string $url): string
    {
        return sha1($url);
    }

    /**
     * Get the numbers of items of current blog, by type then by status.
     *
     * @return  array<int, array<int, int>>
     */
    public static function counts(): array
    {
        $counts = [];
        foreach ([self::TYPE_LINK, self::TYPE_FILE, self::TYPE_ORPHAN] as $type) {
            $counts[$type] = array_fill_keys([
                self::STATUS_PENDING,
                self::STATUS_OK,
                self::STATUS_BROKEN,
                self::STATUS_UNSURE,
                self::STATUS_IGNORED,
                self::STATUS_SKIPPED,
            ], 0);
        }

        $sql = new SelectStatement();
        $rs  = $sql
            ->columns([
                'item_type',
                'item_status',
                $sql->count('item_id', 'nb_item'),
            ])
            ->from(self::table())
            ->where('blog_id = ' . $sql->quote(App::blog()->id()))
            ->group(['item_type', 'item_status'])
            ->select();

        if ($rs instanceof MetaRecord) {
            while ($rs->fetch()) {
                if (isset($counts[$rs->intField('item_type')][$rs->intField('item_status')])) {
                    $counts[$rs->intField('item_type')][$rs->intField('item_status')] = $rs->intField('nb_item');
                }
            }
        }

        return $counts;
    }

    /**
     * Count the items to fix of current blog.
     */
    public static function countProblems(): int
    {
        return self::count(0, self::PROBLEMS);
    }

    /**
     * Count items of current blog.
     *
     * @param   int                 $type       The type, 0 for all
     * @param   array<int, int>     $statuses   The statuses, none for all
     * @param   string              $query      Some text to find in the address or in the entry title, whatever the case and the accents
     */
    public static function count(int $type = 0, array $statuses = [], string $query = ''): int
    {
        if (trim($query) !== '') {
            return count(self::match(self::search($type, $statuses), $query));
        }

        $sql = new SelectStatement();
        $sql
            ->column($sql->count('I.item_id'))
            ->from($sql->as(self::table(), 'I'));

        self::filter($sql, $type, $statuses);

        $rs = $sql->select();

        return $rs instanceof MetaRecord ? $rs->cardinal() : 0;
    }

    /**
     * Search items of current blog.
     *
     * Without sort field, items to fix come first, then the last checked ones.
     *
     * @param   int                 $type       The type, 0 for all
     * @param   array<int, int>     $statuses   The statuses, none for all
     * @param   string              $query      Some text to find in the address or in the entry title, whatever the case and the accents
     * @param   int                 $limit      The maximum number of items, 0 for all
     * @param   int                 $offset     The number of items to skip
     * @param   string              $sortby     The sort field, one of SORTS
     * @param   bool                $desc       The sort order is descending
     *
     * @return  array<int, Item>
     */
    public static function search(int $type = 0, array $statuses = [], string $query = '', int $limit = 0, int $offset = 0, string $sortby = '', bool $desc = true): array
    {
        if (trim($query) !== '') {
            // The text is not searched by the database: case, accents and escaping of LIKE differ from one database to another
            $items = self::match(self::search($type, $statuses, '', 0, 0, $sortby, $desc), $query);

            return $limit > 0 ? array_slice($items, max(0, $offset), $limit) : $items;
        }

        $sql = self::select();

        if (in_array($sortby, self::SORTS, true)) {
            $order = $desc ? ' DESC' : ' ASC';
            $field = match ($sortby) {
                'post_title' => App::db()->con()->lexFields('P.post_title'),
                default      => 'I.' . $sortby,
            };
            $sql->order([$field . $order, 'I.item_id' . $order]);
        } else {
            $sql->order(['I.item_status ASC', 'I.item_dt DESC', 'I.item_id DESC']);
        }

        self::filter($sql, $type, $statuses);

        if ($limit > 0) {
            $sql->limit([max(0, $offset), $limit]);
        }

        return self::fetch($sql);
    }

    /**
     * Get some items of current blog.
     *
     * @param   array<int, int>     $ids    The items IDs
     *
     * @return  array<int, Item>
     */
    public static function getByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $sql = self::select();
        $sql
            ->where('I.blog_id = ' . $sql->quote(App::blog()->id()))
            ->and('I.item_id' . $sql->in(array_values(array_map(intval(...), $ids)), 'int'));

        return self::fetch($sql);
    }

    /**
     * Get the items of an entry.
     *
     * @return  array<string, array{id: int, type: int, status: int}>   The items by address key
     */
    public static function ofPost(int $post_id): array
    {
        $sql = new SelectStatement();
        $rs  = $sql
            ->columns(['item_id', 'item_type', 'item_status', 'item_hash'])
            ->from(self::table())
            ->where('blog_id = ' . $sql->quote(App::blog()->id()))
            ->and('post_id = ' . $post_id)
            ->select();

        $items = [];
        if ($rs instanceof MetaRecord) {
            while ($rs->fetch()) {
                $items[self::str($rs, 'item_hash')] = [
                    'id'     => $rs->intField('item_id'),
                    'type'   => $rs->intField('item_type'),
                    'status' => $rs->intField('item_status'),
                ];
            }
        }

        return $items;
    }

    /**
     * Get the unused media known so far.
     *
     * @return  array<int, array{id: int, status: int}>     The items by media ID
     */
    public static function orphans(): array
    {
        $sql = new SelectStatement();
        $rs  = $sql
            ->columns(['item_id', 'item_status', 'media_id'])
            ->from(self::table())
            ->where('blog_id = ' . $sql->quote(App::blog()->id()))
            ->and('item_type = ' . self::TYPE_ORPHAN)
            ->select();

        $items = [];
        if ($rs instanceof MetaRecord) {
            while ($rs->fetch()) {
                $items[$rs->intField('media_id')] = [
                    'id'     => $rs->intField('item_id'),
                    'status' => $rs->intField('item_status'),
                ];
            }
        }

        return $items;
    }

    /**
     * Get the addresses of the files of the media library used by the entries.
     *
     * @return  array<int, string>
     */
    public static function files(): array
    {
        $sql = new SelectStatement();
        $rs  = $sql
            ->column('item_url')
            ->distinct()
            ->from(self::table())
            ->where('blog_id = ' . $sql->quote(App::blog()->id()))
            ->and('item_type = ' . self::TYPE_FILE)
            ->select();

        $urls = [];
        if ($rs instanceof MetaRecord) {
            while ($rs->fetch()) {
                $urls[] = self::str($rs, 'item_url');
            }
        }

        return $urls;
    }

    /**
     * Get links waiting for their check.
     *
     * @param   int                 $limit      The maximum number of links
     * @param   array<int, int>     $posts      Limit to the links of these entries
     *
     * @return  array<string, string>   The addresses by key
     */
    public static function pendingLinks(int $limit, array $posts = []): array
    {
        $sql = new SelectStatement();
        $sql
            ->columns(['item_hash', 'item_url'])
            ->from(self::table())
            ->where('blog_id = ' . $sql->quote(App::blog()->id()))
            ->and('item_type = ' . self::TYPE_LINK)
            ->and('item_status = ' . self::STATUS_PENDING)
            ->order('item_id ASC')
            // The same address may be used by several entries
            ->limit($limit * 4);

        if ($posts !== []) {
            $sql->and('post_id' . $sql->in(array_values(array_map(intval(...), $posts)), 'int'));
        }

        $rs    = $sql->select();
        $links = [];
        if ($rs instanceof MetaRecord) {
            while ($rs->fetch() && count($links) < $limit) {
                $links[self::str($rs, 'item_hash')] = self::str($rs, 'item_url');
            }
        }

        return $links;
    }

    /**
     * Count links waiting for their check.
     */
    public static function countPendingLinks(): int
    {
        return self::count(self::TYPE_LINK, [self::STATUS_PENDING]);
    }

    /**
     * Add items to current blog.
     *
     * @param   array<int, array{type: int, url: string, post_id?: int, media_id?: int, status?: int, code?: int, info?: string}>   $rows   The items
     */
    public static function add(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $date = date('Y-m-d H:i:s');

        App::db()->con()->writeLock(self::table());

        try {
            $sql = new SelectStatement();
            $rs  = $sql
                ->column($sql->max('item_id'))
                ->from(self::table())
                ->select();

            $id = $rs instanceof MetaRecord ? $rs->cardinal() : 0;

            foreach ($rows as $row) {
                $status = $row['status'] ?? self::STATUS_PENDING;

                $cur = App::db()->con()->openCursor(self::table());
                $cur->setIntField('item_id', ++$id);
                $cur->setStrField('blog_id', App::blog()->id());
                $cur->setIntField('post_id', empty($row['post_id']) ? null : $row['post_id']);
                $cur->setIntField('media_id', empty($row['media_id']) ? null : $row['media_id']);
                $cur->setIntField('item_type', $row['type']);
                $cur->setStrField('item_url', $row['url']);
                $cur->setStrField('item_hash', self::hash($row['url']));
                $cur->setIntField('item_status', $status);
                $cur->setIntField('item_code', $row['code'] ?? 0);
                $cur->setStrField('item_info', mb_substr($row['info'] ?? '', 0, self::INFO_LENGTH));
                $cur->setStrField('item_dt', $status === self::STATUS_PENDING ? null : $date);
                $cur->insert();
            }

            App::db()->con()->unlock();
        } catch (Throwable $exception) {
            App::db()->con()->unlock();

            throw $exception;
        }
    }

    /**
     * Save the result of the check of some items of current blog.
     *
     * @param   array<int, int>     $ids    The items IDs
     */
    public static function result(array $ids, int $status, int $code = 0, string $info = ''): void
    {
        if ($ids === []) {
            return;
        }

        $sql = new UpdateStatement();
        $sql
            ->where('blog_id = ' . $sql->quote(App::blog()->id()))
            ->and('item_id' . $sql->in(array_values(array_map(intval(...), $ids)), 'int'))
            ->update(self::resultCursor($status, $code, $info));
    }

    /**
     * Save the result of the check of a link, for all the entries of current blog using it.
     *
     * Ignored items keep their status.
     *
     * @param   string  $hash   The address key
     */
    public static function linkResult(string $hash, int $status, int $code = 0, string $info = ''): void
    {
        $sql = new UpdateStatement();
        $sql
            ->where('blog_id = ' . $sql->quote(App::blog()->id()))
            ->and('item_type = ' . self::TYPE_LINK)
            ->and('item_hash = ' . $sql->quote($hash))
            ->and('item_status <> ' . self::STATUS_IGNORED)
            ->update(self::resultCursor($status, $code, $info));
    }

    /**
     * Change the status of some items of current blog, without check.
     *
     * @param   array<int, int>     $ids    The items IDs
     */
    public static function setStatus(array $ids, int $status): void
    {
        if ($ids === []) {
            return;
        }

        $cur = App::db()->con()->openCursor(self::table());
        $cur->setIntField('item_status', $status);

        $sql = new UpdateStatement();
        $sql
            ->where('blog_id = ' . $sql->quote(App::blog()->id()))
            ->and('item_id' . $sql->in(array_values(array_map(intval(...), $ids)), 'int'))
            ->update($cur);
    }

    /**
     * Put back links in the queue of the links to check.
     *
     * Ignored links stay ignored. Links to fix and links not checked on purpose are always checked again.
     *
     * @param   int     $days   Links without problem checked since less than this number of days are kept, 0 to check all again
     */
    public static function queueLinks(int $days = 0): void
    {
        $cur = App::db()->con()->openCursor(self::table());
        $cur->setIntField('item_status', self::STATUS_PENDING);

        $sql = new UpdateStatement();
        $sql
            ->where('blog_id = ' . $sql->quote(App::blog()->id()))
            ->and('item_type = ' . self::TYPE_LINK);

        if ($days > 0) {
            $sql->and($sql->orGroup([
                'item_status' . $sql->in([self::STATUS_BROKEN, self::STATUS_UNSURE, self::STATUS_SKIPPED], 'int'),
                $sql->andGroup([
                    'item_status = ' . self::STATUS_OK,
                    $sql->orGroup([
                        'item_dt IS NULL',
                        'item_dt < ' . $sql->quote(date('Y-m-d H:i:s', time() - $days * 86400)),
                    ]),
                ]),
            ]));
        } else {
            $sql->and('item_status <> ' . self::STATUS_IGNORED);
        }

        $sql->update($cur);
    }

    /**
     * Delete items of current blog.
     *
     * @param   array<int, int>     $ids    The items IDs
     */
    public static function delete(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $sql = new DeleteStatement();
        $sql
            ->from(self::table())
            ->where('blog_id = ' . $sql->quote(App::blog()->id()))
            ->and('item_id' . $sql->in(array_values(array_map(intval(...), $ids)), 'int'))
            ->delete();
    }

    /**
     * Delete the items of the entries of current blog which do not exist anymore in this blog.
     *
     * The database deletes the items of a deleted entry, not those of an entry moved to another blog.
     */
    public static function deleteStrayed(): void
    {
        $sub = new SelectStatement();
        $sub
            ->column('post_id')
            ->from(App::db()->con()->prefix() . App::blog()::POST_TABLE_NAME)
            ->where('blog_id = ' . $sub->quote(App::blog()->id()));

        $sql = new DeleteStatement();
        $sql
            ->from(self::table())
            ->where('blog_id = ' . $sql->quote(App::blog()->id()))
            ->and('post_id IS NOT NULL')
            ->and('post_id NOT IN (' . $sub->statement() . ')')
            ->delete();
    }

    /**
     * Get a text ready to be compared: lower case, without accents.
     */
    public static function normalize(string $text): string
    {
        return mb_strtolower(Text::deaccent(trim($text)));
    }

    /**
     * Get the cursor of a check result.
     */
    private static function resultCursor(int $status, int $code, string $info): Cursor
    {
        $cur = App::db()->con()->openCursor(self::table());
        $cur->setIntField('item_status', $status);
        $cur->setIntField('item_code', $code);
        $cur->setStrField('item_info', mb_substr($info, 0, self::INFO_LENGTH));
        $cur->setStrField('item_dt', date('Y-m-d H:i:s'));

        return $cur;
    }

    /**
     * Get the query of the items, with their entries.
     */
    private static function select(): SelectStatement
    {
        $sql = new SelectStatement();

        return $sql
            ->columns([
                'I.item_id',
                'I.item_type',
                'I.item_url',
                'I.item_status',
                'I.item_code',
                'I.item_info',
                'I.item_dt',
                'I.post_id',
                'I.media_id',
                'P.post_title',
                'P.post_type',
            ])
            ->from($sql->as(self::table(), 'I'))
            ->join(
                (new JoinStatement())
                    ->left()
                    ->from($sql->as(App::db()->con()->prefix() . App::blog()::POST_TABLE_NAME, 'P'))
                    ->on('P.post_id = I.post_id')
                    ->statement()
            );
    }

    /**
     * Limit a query to current blog, a type and some statuses.
     *
     * @param   array<int, int>     $statuses   The statuses, none for all
     */
    private static function filter(SelectStatement $sql, int $type, array $statuses): void
    {
        $sql->where('I.blog_id = ' . $sql->quote(App::blog()->id()));

        if ($type > 0) {
            $sql->and('I.item_type = ' . $type);
        }

        if ($statuses !== []) {
            $sql->and('I.item_status' . $sql->in(array_values(array_map(intval(...), $statuses)), 'int'));
        }
    }

    /**
     * @return  array<int, Item>
     */
    private static function fetch(SelectStatement $sql): array
    {
        $rs    = $sql->select();
        $items = [];
        if ($rs instanceof MetaRecord) {
            while ($rs->fetch()) {
                $items[] = new Item(
                    id:         $rs->intField('item_id'),
                    type:       $rs->intField('item_type'),
                    url:        self::str($rs, 'item_url'),
                    status:     $rs->intField('item_status'),
                    code:       $rs->intField('item_code'),
                    info:       self::str($rs, 'item_info'),
                    checked:    self::str($rs, 'item_dt'),
                    post_id:    $rs->intField('post_id'),
                    post_title: self::str($rs, 'post_title'),
                    post_type:  self::str($rs, 'post_type'),
                    media_id:   $rs->intField('media_id'),
                );
            }
        }

        return $items;
    }

    /**
     * Keep the items having some text in their address or in the title of their entry.
     *
     * @param   array<int, Item>    $items  The items
     * @param   string              $query  The text to find
     *
     * @return  array<int, Item>
     */
    private static function match(array $items, string $query): array
    {
        $query = self::normalize($query);

        return array_values(array_filter(
            $items,
            fn (Item $item): bool => str_contains(self::normalize($item->url . "\n" . $item->post_title), $query)
        ));
    }

    /**
     * Get a field as a string, even if the database gives a number.
     */
    private static function str(MetaRecord $rs, string $field): string
    {
        $value = $rs->field($field);

        return is_scalar($value) ? (string) $value : '';
    }
}
