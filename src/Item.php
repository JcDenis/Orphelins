<?php

declare(strict_types=1);

namespace Dotclear\Plugin\Orphelins;

/**
 * @brief       Orphelins item descriptor.
 * @ingroup     Orphelins
 *
 * An item is something checked: a link or a file used by an entry, or a media no entry uses.
 *
 * @author      Jean-Christian Paul Denis
 * @copyright   AGPL-3.0
 */
class Item
{
    /**
     * @param   int     $id             The item ID
     * @param   int     $type           The item type, see Items::TYPE_xxx
     * @param   string  $url            The address as written in the entry, or the path of the media in the media library
     * @param   int     $status         The item status, see Items::STATUS_xxx
     * @param   int     $code           The HTTP code of the last check, 0 if none
     * @param   string  $info           The result of the last check, see Items::INFO_xxx
     * @param   string  $checked        The date of the last check (server time), empty if never checked
     * @param   int     $post_id        The entry ID, 0 for an unused media
     * @param   string  $post_title     The entry title
     * @param   string  $post_type      The entry type
     * @param   int     $media_id       The media ID, 0 if not an unused media
     */
    public function __construct(
        public readonly int $id,
        public readonly int $type,
        public readonly string $url,
        public readonly int $status,
        public readonly int $code,
        public readonly string $info,
        public readonly string $checked,
        public readonly int $post_id,
        public readonly string $post_title,
        public readonly string $post_type,
        public readonly int $media_id,
    ) {
    }

    /**
     * Get the result of the last check as a key: what the lists show, filter and export.
     *
     * @return  string  "waiting", "http-" followed by the HTTP code, "redirect-dead", or one of Items::INFO_xxx
     */
    public function result(): string
    {
        if ($this->status === Items::STATUS_PENDING) {
            return Items::RESULT_WAITING;
        }

        $info = explode(' ', $this->info, 2)[0];

        return match ($info) {
            Items::INFO_HTTP     => Items::RESULT_HTTP . $this->code,
            Items::INFO_REDIRECT => $this->status === Items::STATUS_OK ? Items::INFO_REDIRECT : Items::RESULT_REDIRECT_DEAD,
            default              => $info,
        };
    }

    /**
     * Something is wrong or may be wrong with this item.
     */
    public function isProblem(): bool
    {
        return in_array($this->status, Items::PROBLEMS, true);
    }
}
