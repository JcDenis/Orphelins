<?php

declare(strict_types=1);

namespace Dotclear\Plugin\Orphelins;

use Dotclear\App;

/**
 * @brief       Orphelins addresses helper.
 * @ingroup     Orphelins
 *
 * Find the addresses used by the HTML content of an entry, and tell which ones are files of the media library.
 *
 * @author      Jean-Christian Paul Denis
 * @copyright   AGPL-3.0
 */
class Url
{
    /**
     * The attributes holding an address, by HTML tag.
     *
     * @var     array<string, array<int, string>>   ATTRIBUTES
     */
    private const ATTRIBUTES = [
        'a'      => ['href'],
        'area'   => ['href'],
        'img'    => ['src', 'srcset'],
        'source' => ['src', 'srcset'],
        'audio'  => ['src'],
        'video'  => ['src', 'poster'],
        'track'  => ['src'],
        'embed'  => ['src'],
        'iframe' => ['src'],
        'object' => ['data'],
    ];

    /**
     * The address of the root of the media library, read once per page load.
     */
    private static ?string $root = null;

    /**
     * The codes of the thumbnails sizes, read once per page load.
     *
     * @var     null|array<int, string>     $sizes
     */
    private static ?array $sizes = null;

    /**
     * Get the addresses used by some HTML content, as they are written.
     *
     * @return  array<int, string>
     */
    public static function extract(string $html): array
    {
        $urls = [];
        if (preg_match_all('/<(' . implode('|', array_keys(self::ATTRIBUTES)) . ')\s([^>]*)>/is', $html, $tags, PREG_SET_ORDER) > 0) {
            foreach ($tags as $tag) {
                $names = self::ATTRIBUTES[strtolower($tag[1])];
                if (preg_match_all('/(?<![\w-])(' . implode('|', $names) . ')\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/is', ' ' . $tag[2], $attributes, PREG_SET_ORDER) > 0) {
                    foreach ($attributes as $attribute) {
                        $value = html_entity_decode(($attribute[2] ?? '') . ($attribute[3] ?? '') . ($attribute[4] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        if (strtolower($attribute[1]) === 'srcset') {
                            // A list of addresses, each one followed by a size
                            foreach (explode(',', $value) as $candidate) {
                                $urls[] = (string) preg_replace('/\s.*$/s', '', trim($candidate));
                            }
                        } else {
                            $urls[] = trim($value);
                        }
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($urls, fn (string $url): bool => $url !== '')));
    }

    /**
     * Get the full address of an address written in an entry.
     *
     * @param   string  $url    The address, as it is written
     * @param   string  $base   The address of the blog
     *
     * @return  null|string     The full address without anchor, null if this is not a web address (mail, phone, anchor…)
     */
    public static function resolve(string $url, string $base): ?string
    {
        $url = trim($url);

        // Anchor in the page
        $url = (string) preg_replace('/#.*$/s', '', $url);
        if ($url === '' || preg_match('/[\x00-\x20]/', $url) === 1) {
            return null;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1) {
            return preg_match('#^https?://[^/]#i', $url) === 1 ? $url : null;
        }

        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host   = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($url, '//')) {
            return $scheme . ':' . $url;
        }

        if (str_starts_with($url, '/')) {
            return $host . $url;
        }

        if (str_starts_with($url, '?')) {
            return $host . ($parts['path'] ?? '/') . $url;
        }

        // Relative to the directory of the blog address
        $dir = (string) preg_replace('#/[^/]*$#', '', $parts['path'] ?? '/');

        return $host . self::clean($dir . '/' . $url);
    }

    /**
     * Get the path of a file in the media library from its address.
     *
     * @param   string  $url    The full address
     *
     * @return  null|string     The path from the root of the media library, null if the address is not in the media library
     */
    public static function mediaPath(string $url): ?string
    {
        $root = self::rootUrl();
        if ($root === '') {
            return null;
        }

        // Whatever the protocol: a blog moved from http to https keeps its files
        $path = rawurldecode((string) preg_replace('/[?#].*$/s', '', self::noScheme($url)));
        if (!str_starts_with($path, $root . '/')) {
            return null;
        }

        $path = substr($path, strlen($root) + 1);
        if ($path === '' || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
            return null;
        }

        return self::clean($path);
    }

    /**
     * Get the key of a file of the media library: the same for a media and for its thumbnails.
     *
     * @param   string  $path   The path from the root of the media library
     */
    public static function mediaKey(string $path): string
    {
        $dir  = dirname($path);
        $name = basename($path);

        // Thumbnail (.name_m.jpg, .name_sq.webp…)
        if (preg_match('/^\.(.+)_(' . implode('|', array_map(fn (string $size): string => preg_quote($size, '/'), self::thumbSizes())) . ')\.(?:jpe?g|png|webp|avif)$/i', $name, $match) === 1) {
            $name = $match[1];
        } else {
            $name = (string) preg_replace('/\.[^.]+$/', '', $name);
        }

        return mb_strtolower(($dir === '.' || $dir === '' ? '' : $dir . '/') . $name);
    }

    /**
     * Get the host of an address, in lower case.
     */
    public static function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : '';
    }

    /**
     * Get the address of the root of the media library, without protocol nor ending slash.
     */
    private static function rootUrl(): string
    {
        return self::$root ??= rtrim(self::noScheme(App::media()->getRootUrl()), '/');
    }

    /**
     * @return  array<int, string>
     */
    private static function thumbSizes(): array
    {
        return self::$sizes ??= array_map(strval(...), array_keys(App::media()->getThumbSizes()));
    }

    private static function noScheme(string $url): string
    {
        return (string) preg_replace('#^https?:#i', '', trim($url));
    }

    /**
     * Remove the "." and ".." parts of a path.
     */
    private static function clean(string $path): string
    {
        $query = '';
        if (($pos = strpos($path, '?')) !== false) {
            $query = substr($path, $pos);
            $path  = substr($path, 0, $pos);
        }

        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.') {
                $parts[] = $part;
            }
        }

        return (string) preg_replace('#/+#', '/', implode('/', $parts)) . $query;
    }
}
