<?php

declare(strict_types=1);

namespace Dotclear\Plugin\Orphelins;

use Dotclear\App;
use Dotclear\Helper\Process\TraitProcess;
use Exception;

/**
 * @brief       Orphelins module install.
 * @ingroup     Orphelins
 *
 * @author      Jean-Christian Paul Denis
 * @copyright   AGPL-3.0
 */
class Install
{
    use TraitProcess;

    public static function init(): bool
    {
        return self::status(My::checkContext(My::INSTALL));
    }

    public static function process(): bool
    {
        if (!self::status()) {
            return false;
        }

        try {
            $struct = App::db()->structure();

            $table = $struct->table(Items::TABLE_NAME);
            $table
                ->field('item_id', 'bigint', 0, false)
                ->field('blog_id', 'varchar', 32, false)
                ->field('post_id', 'bigint', 0, true)
                ->field('media_id', 'bigint', 0, true)
                ->field('item_type', 'smallint', 0, false, Items::TYPE_LINK)
                ->field('item_url', 'text', 0, false)
                ->field('item_hash', 'varchar', 40, false)
                ->field('item_status', 'smallint', 0, false, Items::STATUS_PENDING)
                ->field('item_code', 'integer', 0, false, 0)
                ->field('item_info', 'varchar', Items::INFO_LENGTH, false, "''")
                ->field('item_dt', 'timestamp', 0, true)

                ->primary('pk_orphelins', 'item_id')
                ->index('idx_orphelins_blog_status', 'btree', 'blog_id', 'item_status')
                ->index('idx_orphelins_blog_hash', 'btree', 'blog_id', 'item_hash')
                ->index('idx_orphelins_post', 'btree', 'post_id')
                ->index('idx_orphelins_media', 'btree', 'media_id')
            ;

            // Items are deleted with their blog, their entry or their media
            $table->reference('fk_orphelins_blog', 'blog_id', 'blog', 'blog_id', 'cascade', 'cascade');
            $table->reference('fk_orphelins_post', 'post_id', 'post', 'post_id', 'cascade', 'cascade');
            $table->reference('fk_orphelins_media', 'media_id', 'media', 'media_id', 'cascade', 'cascade');

            App::db()->structure()->synchronize($struct);

            return true;
        } catch (Exception $exception) {
            App::error()->add($exception->getMessage());

            return false;
        }
    }
}
