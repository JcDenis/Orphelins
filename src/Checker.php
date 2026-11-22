<?php

declare(strict_types=1);

namespace Dotclear\Plugin\Orphelins;

use ArrayObject;
use Dotclear\App;
use Dotclear\Database\MetaRecord;
use Dotclear\Database\Statement\SelectStatement;

/**
 * @brief       Orphelins check of a blog.
 * @ingroup     Orphelins
 *
 * A check is too long for one page load: it is done by steps of a few seconds, called again until the end.
 * Its progress is saved in the settings of the blog, so a step only needs to know there is a check going on.
 *
 * A check goes through three phases:
 * - entries: the addresses used by each entry (all of them, or those published since the date of the options) are read, and the files of the media library looked for on the disk,
 * - links: each link waiting for its check is requested on the web,
 * - media: each media of the media library is looked for in the entries.
 *
 * All methods work on current blog and do NOT check permissions: callers must do it.
 *
 * @author      Jean-Christian Paul Denis
 * @copyright   AGPL-3.0
 */
class Checker
{
    /**
     * The time given to a step, in seconds. A step ends after the action which goes over it.
     *
     * @var     int     BUDGET
     */
    public const BUDGET = 8;

    /**
     * Default number of days a link without problem is kept before being checked again.
     *
     * @var     int     RECHECK_DAYS
     */
    public const RECHECK_DAYS = 7;

    /**
     * @var     int     RECHECK_MAX
     */
    public const RECHECK_MAX = 365;

    /**
     * Blog settings of the plugin.
     */
    public const SETTING_STATE   = 'state';
    public const SETTING_LAST    = 'last_check';
    public const SETTING_DOMAINS = 'ignored_domains';
    public const SETTING_DAYS    = 'recheck_days';
    public const SETTING_SINCE   = 'since_date';

    /**
     * The phases of a check.
     */
    public const PHASE_POSTS = 'posts';
    public const PHASE_LINKS = 'links';
    public const PHASE_MEDIA = 'media';

    /**
     * The behavior other plugins use to tell the media they use.
     *
     * Its argument is an ArrayObject to append to: a media ID (int), a path from the root of the media library
     * or an address (string). Called once per page load of the check, in backend context, for the current blog.
     *
     * @var     string  BEHAVIOR_USED_MEDIA
     */
    public const BEHAVIOR_USED_MEDIA = 'OrphelinsUsedMedia';

    /**
     * The numbers of entries and media read by a query.
     */
    private const POSTS_BATCH = 50;
    private const MEDIA_BATCH = 200;
    private const LINKS_BATCH = 10;

    /**
     * The options saved by this page load.
     *
     * @var     array{}|array{ignored_domains: array<int, string>, recheck_days: int, since_date: string}    $saved
     */
    private static array $saved = [];

    /**
     * The progress of the check going on has been read.
     */
    private static bool $loaded = false;

    /**
     * The progress of the check going on, null if there is none.
     *
     * @var     null|array{phase: string, cursor: int, done: int, total: int, started: int}     $state
     */
    private static ?array $state = null;

    /**
     * The media the entries are known to use, read once per page load.
     *
     * @var     null|array{ids: array<int, true>, keys: array<string, true>}    $used
     */
    private static ?array $used = null;

    /**
     * Check if a check is going on.
     */
    public static function isRunning(): bool
    {
        return self::state() !== null;
    }

    /**
     * Get the date of the end of the last check.
     *
     * @return  int     The timestamp, 0 if there was no check
     */
    public static function lastCheck(): int
    {
        return (int) My::settings()->getInt(self::SETTING_LAST, false);
    }

    /**
     * Get the domains not to check.
     *
     * @return  array<int, string>
     */
    public static function ignoredDomains(): array
    {
        return self::$saved[self::SETTING_DOMAINS] ?? Http::domains((string) My::settings()->getStr(self::SETTING_DOMAINS, false));
    }

    /**
     * Get the number of days a link without problem is kept before being checked again.
     */
    public static function recheckDays(): int
    {
        if (isset(self::$saved[self::SETTING_DAYS])) {
            return self::$saved[self::SETTING_DAYS];
        }

        $days = My::settings()->getInt(self::SETTING_DAYS);

        return is_null($days) ? self::RECHECK_DAYS : min(max($days, 0), self::RECHECK_MAX);
    }

    /**
     * Get the date since which entries are checked, from their publication date.
     *
     * @return  string  The date (YYYY-MM-DD), empty to check all the entries
     */
    public static function sinceDate(): string
    {
        return self::$saved[self::SETTING_SINCE] ?? self::cleanDate((string) My::settings()->getStr(self::SETTING_SINCE, false));
    }

    /**
     * Clean a date typed by a user.
     *
     * @return  string  The date (YYYY-MM-DD), empty if this is not a date
     */
    public static function cleanDate(string $date): string
    {
        $date = trim($date);

        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $match) === 1 && checkdate((int) $match[2], (int) $match[3], (int) $match[1]) ? $date : '';
    }

    /**
     * Save the options of the checks.
     *
     * @param   string  $domains    The domains not to check
     * @param   int     $days       The number of days a link without problem is kept before being checked again
     * @param   string  $since      The date since which entries are checked (YYYY-MM-DD), empty for all the entries
     */
    public static function saveOptions(string $domains, int $days, string $since = ''): void
    {
        // A saved setting is not read again by the same page load, and a check may start right after
        self::$saved = [
            self::SETTING_DOMAINS => Http::domains($domains),
            self::SETTING_DAYS    => min(max($days, 0), self::RECHECK_MAX),
            self::SETTING_SINCE   => self::cleanDate($since),
        ];

        My::settings()->put(self::SETTING_DOMAINS, implode("\n", self::$saved[self::SETTING_DOMAINS]), App::blogWorkspace()::NS_STRING);
        My::settings()->put(self::SETTING_DAYS, self::$saved[self::SETTING_DAYS], App::blogWorkspace()::NS_INT);
        My::settings()->put(self::SETTING_SINCE, self::$saved[self::SETTING_SINCE], App::blogWorkspace()::NS_STRING);
    }

    /**
     * Start a check.
     *
     * @param   bool    $full   Check again all the links, even those checked a few days ago
     */
    public static function start(bool $full = false): void
    {
        Items::deleteStrayed();
        // Entries published before the date of the options are not in the report anymore
        Items::deleteBefore(self::sinceDate());
        Items::queueLinks($full ? 0 : self::recheckDays());

        self::save([
            'phase'   => self::PHASE_POSTS,
            'cursor'  => 0,
            'done'    => 0,
            'total'   => self::countPosts(),
            // Also the identity of this check: see step()
            'started' => (int) (microtime(true) * 1000),
        ]);
    }

    /**
     * Stop the check going on. What has been checked so far is kept.
     */
    public static function cancel(): void
    {
        self::save(null);
    }

    /**
     * Do a step of the check going on.
     *
     * @return  bool    True if the check is finished
     */
    public static function step(): bool
    {
        $state = self::state();
        if ($state === null) {
            return true;
        }

        $end     = microtime(true) + self::BUDGET;
        $started = $state['started'];

        $state = match ($state['phase']) {
            self::PHASE_POSTS => self::stepPosts($state, $end),
            self::PHASE_LINKS => self::stepLinks($state, $end),
            default           => self::stepMedia($state, $end),
        };

        // A step lasts several seconds: the check may have been stopped, or stopped and started again, by another page load.
        // Then this step must not bring it back
        $current = self::stored();
        if ($current === null || $current['started'] !== $started) {
            self::$state = $current;

            return $current === null;
        }

        if ($state === null) {
            My::settings()->put(self::SETTING_LAST, time(), App::blogWorkspace()::NS_INT);
        }
        self::save($state);

        return $state === null;
    }

    /**
     * Get the progress of the check going on.
     *
     * @return  null|array{phase: string, done: int, total: int}    Null if there is no check going on
     */
    public static function progress(): ?array
    {
        $state = self::state();

        return $state === null ? null : [
            'phase' => $state['phase'],
            'done'  => $state['done'],
            'total' => $state['total'],
        ];
    }

    /**
     * Get the progress of the check going on, in words.
     */
    public static function message(): string
    {
        $progress = self::progress();
        if ($progress === null) {
            return __('The check is finished.');
        }

        return match ($progress['phase']) {
            self::PHASE_POSTS => sprintf(__('Step 1 of 3: reading entries (%1$s of %2$s).'), $progress['done'], $progress['total']),
            self::PHASE_LINKS => sprintf(__('Step 2 of 3: checking links (%1$s of %2$s).'), $progress['done'], $progress['total']),
            default           => sprintf(__('Step 3 of 3: looking for unused media (%1$s of %2$s).'), $progress['done'], $progress['total']),
        };
    }

    /**
     * Check again some items, right now.
     *
     * Their entries are read again, so a fixed entry loses its problems.
     * Links are checked as long as the time of a step allows it, the other ones wait for the next check.
     *
     * @param   array<int, Item>    $items  The items
     *
     * @return  int     The number of links still waiting for their check
     */
    public static function recheck(array $items): int
    {
        $end   = microtime(true) + self::BUDGET;
        $posts = [];
        $links = [];
        $media = [];
        foreach ($items as $item) {
            if ($item->type === Items::TYPE_ORPHAN) {
                $media[] = $item->media_id;

                continue;
            }
            if ($item->type === Items::TYPE_LINK && $item->status !== Items::STATUS_IGNORED) {
                $links[] = $item->id;
            }
            $posts[$item->post_id] = $item->post_id;
        }

        Items::setStatus($links, Items::STATUS_PENDING);

        if ($posts !== []) {
            $sql = new SelectStatement();
            $rs  = self::postsQuery($sql)
                ->and('post_id' . $sql->in(array_values($posts), 'int'))
                ->select();
            if ($rs instanceof MetaRecord) {
                while ($rs->fetch()) {
                    self::syncPost($rs->intField('post_id'), $rs->strField('post_excerpt_xhtml') . "\n" . $rs->strField('post_content_xhtml'));
                }
            }

            $ignored = self::ignoredDomains();
            while (microtime(true) < $end && ($pending = Items::pendingLinks(1, array_values($posts))) !== []) {
                foreach ($pending as $hash => $url) {
                    $answer = Http::check($url, $ignored);
                    Items::linkResult($hash, $answer['status'], $answer['code'], $answer['info']);
                }
            }
        }

        if ($media !== []) {
            $sql = new SelectStatement();
            $rs  = self::mediaQuery($sql)
                ->and('media_id' . $sql->in($media, 'int'))
                ->select();
            if ($rs instanceof MetaRecord) {
                self::syncMedia($rs, $end + self::BUDGET);
            }
        }

        return $posts === [] ? 0 : count(Items::pendingLinks(100, array_values($posts)));
    }

    /**
     * Read the addresses used by the entries.
     *
     * @param   array{phase: string, cursor: int, done: int, total: int, started: int}  $state  The progress
     *
     * @return  array{phase: string, cursor: int, done: int, total: int, started: int}
     */
    private static function stepPosts(array $state, float $end): array
    {
        do {
            $sql = new SelectStatement();
            $rs  = self::postsQuery($sql, self::sinceDate())
                ->and('post_id > ' . $state['cursor'])
                ->order('post_id ASC')
                ->limit(self::POSTS_BATCH)
                ->select();

            if (!$rs instanceof MetaRecord || $rs->isEmpty()) {
                // Next phase
                return [
                    'phase'   => self::PHASE_LINKS,
                    'cursor'  => 0,
                    'done'    => 0,
                    'total'   => Items::countPendingLinks(),
                    'started' => $state['started'],
                ];
            }

            while ($rs->fetch()) {
                self::syncPost($rs->intField('post_id'), $rs->strField('post_excerpt_xhtml') . "\n" . $rs->strField('post_content_xhtml'));

                $state['cursor'] = $rs->intField('post_id');
                $state['done']++;

                if (microtime(true) >= $end) {
                    break;
                }
            }
        } while (microtime(true) < $end);

        return $state;
    }

    /**
     * Check the links waiting for it.
     *
     * @param   array{phase: string, cursor: int, done: int, total: int, started: int}  $state  The progress
     *
     * @return  array{phase: string, cursor: int, done: int, total: int, started: int}
     */
    private static function stepLinks(array $state, float $end): array
    {
        $ignored = self::ignoredDomains();

        do {
            $links = Items::pendingLinks(self::LINKS_BATCH);
            if ($links === []) {
                // Next phase
                return [
                    'phase'   => self::PHASE_MEDIA,
                    'cursor'  => 0,
                    'done'    => 0,
                    'total'   => self::countMedia(),
                    'started' => $state['started'],
                ];
            }

            foreach ($links as $hash => $url) {
                $answer = Http::check($url, $ignored);
                Items::linkResult($hash, $answer['status'], $answer['code'], $answer['info']);

                $state['done']++;

                if (microtime(true) >= $end) {
                    break;
                }
            }
        } while (microtime(true) < $end);

        // Several entries may use the same link: the total is only known at the end
        $state['total'] = max($state['total'], $state['done'] + 1);

        return $state;
    }

    /**
     * Look for the media no entry uses.
     *
     * @param   array{phase: string, cursor: int, done: int, total: int, started: int}  $state  The progress
     *
     * @return  null|array{phase: string, cursor: int, done: int, total: int, started: int}     Null if the check is finished
     */
    private static function stepMedia(array $state, float $end): ?array
    {
        do {
            $sql = new SelectStatement();
            $rs  = self::mediaQuery($sql)
                ->and('media_id > ' . $state['cursor'])
                ->order('media_id ASC')
                ->limit(self::MEDIA_BATCH)
                ->select();

            if (!$rs instanceof MetaRecord || $rs->isEmpty()) {
                // The end
                return null;
            }

            $read = self::syncMedia($rs, $end);

            $state['cursor'] = $read['cursor'];
            $state['done'] += $read['done'];
        } while (microtime(true) < $end);

        return $state;
    }

    /**
     * Update the items of an entry from its content.
     *
     * Files of the media library are looked for at once, links wait for their check.
     *
     * @param   int     $post_id    The entry ID
     * @param   string  $html       The entry excerpt and content
     */
    private static function syncPost(int $post_id, string $html): void
    {
        $found = [];
        foreach (Url::extract($html) as $raw) {
            $url = Url::resolve($raw, App::blog()->url());
            if ($url === null) {
                continue;
            }

            $path                     = Url::mediaPath($url);
            $found[Items::hash($url)] = [
                'type' => $path === null ? Items::TYPE_LINK : Items::TYPE_FILE,
                'url'  => $url,
                'path' => (string) $path,
            ];
        }

        $delete = [];
        $exists = [];
        $missed = [];
        foreach (Items::ofPost($post_id) as $hash => $item) {
            if (!isset($found[$hash]) || $found[$hash]['type'] !== $item['type']) {
                // Not used by the entry anymore
                $delete[] = $item['id'];

                continue;
            }

            if ($item['type'] === Items::TYPE_FILE) {
                if (self::fileExists($found[$hash]['path'])) {
                    $exists[] = $item['id'];
                } elseif ($item['status'] !== Items::STATUS_IGNORED) {
                    $missed[] = $item['id'];
                }
            }

            unset($found[$hash]);
        }

        Items::delete($delete);
        Items::result($exists, Items::STATUS_OK, 0, Items::INFO_FOUND);
        Items::result($missed, Items::STATUS_BROKEN, 0, Items::INFO_MISSING);

        $rows = [];
        foreach ($found as $item) {
            $row = ['type' => $item['type'], 'url' => $item['url'], 'post_id' => $post_id];
            if ($item['type'] === Items::TYPE_FILE) {
                $exists        = self::fileExists($item['path']);
                $row['status'] = $exists ? Items::STATUS_OK : Items::STATUS_BROKEN;
                $row['info']   = $exists ? Items::INFO_FOUND : Items::INFO_MISSING;
            }
            $rows[] = $row;
        }
        Items::add($rows);
    }

    /**
     * Update the items of some media: a media no entry uses has an item, the other ones do not.
     *
     * @param   MetaRecord  $rs     The media
     * @param   float       $end    The time to stop at
     *
     * @return  array{cursor: int, done: int}   The last media read and the number of media read
     */
    private static function syncMedia(MetaRecord $rs, float $end): array
    {
        $used    = self::usedMedia();
        $orphans = Items::orphans();
        $cursor  = 0;
        $done    = 0;
        $rows    = [];
        $delete  = [];
        $unused  = [];

        while ($rs->fetch()) {
            $id   = $rs->intField('media_id');
            $file = is_scalar($file = $rs->field('media_file')) ? (string) $file : '';

            $cursor = $id;
            $done++;

            $is_used = isset($used['ids'][$id]) || isset($used['keys'][Url::mediaKey($file)]) || self::isUsedElsewhere($file);

            if ($is_used) {
                if (isset($orphans[$id])) {
                    $delete[] = $orphans[$id]['id'];
                }
            } elseif (!isset($orphans[$id])) {
                $rows[] = [
                    'type'     => Items::TYPE_ORPHAN,
                    'url'      => $file,
                    'media_id' => $id,
                    'status'   => Items::STATUS_BROKEN,
                    'info'     => Items::INFO_UNUSED,
                ];
            } elseif ($orphans[$id]['status'] !== Items::STATUS_IGNORED) {
                $unused[] = $orphans[$id]['id'];
            }

            if (microtime(true) >= $end) {
                break;
            }
        }

        Items::delete($delete);
        Items::result($unused, Items::STATUS_BROKEN, 0, Items::INFO_UNUSED);
        Items::add($rows);

        return ['cursor' => $cursor, 'done' => $done];
    }

    /**
     * Get the media the entries of the blog are known to use.
     *
     * @return  array{ids: array<int, true>, keys: array<string, true>}     The IDs of the media attached to entries, and the keys of the files used by contents
     */
    private static function usedMedia(): array
    {
        if (self::$used !== null) {
            return self::$used;
        }

        $used = ['ids' => [], 'keys' => []];

        // Media attached to an entry, whatever the kind of link (attachment, featured media…) and the blog
        $sql = new SelectStatement();
        $rs  = $sql
            ->column('media_id')
            ->distinct()
            ->from(App::db()->con()->prefix() . App::postMedia()::POST_MEDIA_TABLE_NAME)
            ->select();
        if ($rs instanceof MetaRecord) {
            while ($rs->fetch()) {
                $used['ids'][$rs->intField('media_id')] = true;
            }
        }

        // Files found in the contents of the entries by this check
        foreach (Items::files() as $url) {
            $path = Url::mediaPath($url);
            if ($path !== null) {
                $used['keys'][Url::mediaKey($path)] = true;
            }
        }

        // Media other plugins say they use: media IDs, paths from the root of the media library, or addresses
        /**
         * @var     ArrayObject<int, mixed>     $others
         */
        $others = new ArrayObject();

        # --BEHAVIOR-- OrphelinsUsedMedia -- ArrayObject
        App::behavior()->callBehavior(self::BEHAVIOR_USED_MEDIA, $others);
        foreach ($others as $media) {
            if (is_int($media)) {
                $used['ids'][$media] = true;
            } elseif (is_string($media) && ($path = Url::declaredPath($media, App::blog()->url())) !== null) {
                $used['keys'][Url::mediaKey($path)] = true;
            }
        }

        return self::$used = $used;
    }

    /**
     * Look for a media where the reading of the entries does not go:
     * the entries of the other blogs sharing the media library, what is not a link or an image in a content,
     * the descriptions of the categories and of the blogs, and the settings (themes, widgets…).
     *
     * @param   string  $file   The path of the media from the root of the media library
     */
    private static function isUsedElsewhere(string $file): bool
    {
        if ($file === '') {
            return true;
        }

        $dir  = dirname($file);
        $base = (string) preg_replace('/\.[^.]+$/', '', basename($file));

        // The media and its thumbnails, as they are written and as they are encoded in an address
        $needles = [$file, ($dir === '.' ? '' : $dir . '/') . '.' . $base . '_'];
        foreach ($needles as $needle) {
            $needles[] = implode('/', array_map(rawurlencode(...), explode('/', $needle)));
        }
        $needles = array_values(array_unique($needles));

        $prefix = App::db()->con()->prefix();
        $places = [
            $prefix . App::blog()::POST_TABLE_NAME              => ['post_content_xhtml', 'post_excerpt_xhtml'],
            $prefix . App::blog()->categories()::CATEGORY_TABLE_NAME => ['cat_desc'],
            $prefix . App::blog()::BLOG_TABLE_NAME              => ['blog_desc'],
            $prefix . App::blogWorkspace()::NS_TABLE_NAME       => ['setting_value'],
        ];

        foreach ($places as $table => $fields) {
            $sql = new SelectStatement();
            $or  = [];
            foreach ($fields as $field) {
                foreach ($needles as $needle) {
                    $or[] = $sql->like($field, '%' . $needle . '%');
                }
            }

            $rs = $sql
                ->column($fields[0])
                ->from($table)
                ->where($sql->orGroup($or))
                ->limit(1)
                ->select();

            if ($rs instanceof MetaRecord && !$rs->isEmpty()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a file exists in the media library.
     *
     * @param   string  $path   The path from the root of the media library
     */
    private static function fileExists(string $path): bool
    {
        return $path !== '' && is_file(App::media()->getRoot() . '/' . $path);
    }

    /**
     * Get the query of the entries of current blog: all types, all statuses.
     *
     * @param   string  $since  Limit to the entries published since this date (YYYY-MM-DD), empty for all
     */
    private static function postsQuery(SelectStatement $sql, string $since = ''): SelectStatement
    {
        $sql
            ->columns(['post_id', 'post_excerpt_xhtml', 'post_content_xhtml'])
            ->from(App::db()->con()->prefix() . App::blog()::POST_TABLE_NAME)
            ->where('blog_id = ' . $sql->quote(App::blog()->id()));

        if ($since !== '') {
            $sql->and('post_dt >= ' . $sql->quote($since . ' 00:00:00'));
        }

        return $sql;
    }

    /**
     * Get the query of the media of the media library of current blog.
     */
    private static function mediaQuery(SelectStatement $sql): SelectStatement
    {
        return $sql
            ->columns(['media_id', 'media_file'])
            ->from(App::db()->con()->prefix() . App::postMedia()::MEDIA_TABLE_NAME)
            ->where('media_path = ' . $sql->quote(App::blog()->settings()->get('system')->getStr('public_path', false)));
    }

    private static function countPosts(): int
    {
        $sql = new SelectStatement();
        $sql
            ->column($sql->count('post_id'))
            ->from(App::db()->con()->prefix() . App::blog()::POST_TABLE_NAME)
            ->where('blog_id = ' . $sql->quote(App::blog()->id()));

        if (self::sinceDate() !== '') {
            $sql->and('post_dt >= ' . $sql->quote(self::sinceDate() . ' 00:00:00'));
        }

        $rs = $sql->select();

        return $rs instanceof MetaRecord ? $rs->cardinal() : 0;
    }

    private static function countMedia(): int
    {
        $sql = new SelectStatement();
        $rs  = $sql
            ->column($sql->count('media_id'))
            ->from(App::db()->con()->prefix() . App::postMedia()::MEDIA_TABLE_NAME)
            ->where('media_path = ' . $sql->quote(App::blog()->settings()->get('system')->getStr('public_path', false)))
            ->select();

        return $rs instanceof MetaRecord ? $rs->cardinal() : 0;
    }

    /**
     * Get the progress of the check going on.
     *
     * @return  null|array{phase: string, cursor: int, done: int, total: int, started: int}     Null if there is no check going on
     */
    private static function state(): ?array
    {
        if (self::$loaded) {
            return self::$state;
        }

        self::$loaded = true;
        self::$state  = self::read();

        return self::$state;
    }

    /**
     * Read the progress of the check going on from the settings of the blog.
     *
     * @return  null|array{phase: string, cursor: int, done: int, total: int, started: int}
     */
    private static function read(): ?array
    {
        return self::decode((string) My::settings()->getStr(self::SETTING_STATE, false));
    }

    /**
     * Read the progress of the check going on from the database, as it is right now.
     *
     * The settings of the blog are read once per page load: they do not show what another page load has done since.
     *
     * @return  null|array{phase: string, cursor: int, done: int, total: int, started: int}
     */
    private static function stored(): ?array
    {
        $sql = new SelectStatement();
        $rs  = $sql
            ->column('setting_value')
            ->from(App::db()->con()->prefix() . App::blogWorkspace()::NS_TABLE_NAME)
            ->where('blog_id = ' . $sql->quote(App::blog()->id()))
            ->and('setting_ns = ' . $sql->quote(My::id()))
            ->and('setting_id = ' . $sql->quote(self::SETTING_STATE))
            ->select();

        return $rs instanceof MetaRecord && $rs->fetch() ? self::decode($rs->strField('setting_value')) : null;
    }

    /**
     * @return  null|array{phase: string, cursor: int, done: int, total: int, started: int}
     */
    private static function decode(string $value): ?array
    {
        $state = json_decode($value, true);
        if (!is_array($state)
            || !isset($state['phase'])
            || !in_array($state['phase'], [self::PHASE_POSTS, self::PHASE_LINKS, self::PHASE_MEDIA], true)
        ) {
            return null;
        }

        return [
            'phase'   => $state['phase'],
            'cursor'  => is_numeric($state['cursor'] ?? null) ? (int) $state['cursor'] : 0,
            'done'    => is_numeric($state['done'] ?? null) ? (int) $state['done'] : 0,
            'total'   => is_numeric($state['total'] ?? null) ? (int) $state['total'] : 0,
            'started' => is_numeric($state['started'] ?? null) ? (int) $state['started'] : 0,
        ];
    }

    /**
     * Save the progress of the check going on.
     *
     * @param   null|array{phase: string, cursor: int, done: int, total: int, started: int}     $state  The progress, null if the check is finished
     */
    private static function save(?array $state): void
    {
        My::settings()->put(self::SETTING_STATE, $state === null ? '' : (string) json_encode($state), App::blogWorkspace()::NS_STRING);

        // A saved setting is not read again by the same page load
        self::$loaded = true;
        self::$state  = $state;
    }
}
