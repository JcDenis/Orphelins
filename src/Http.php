<?php

declare(strict_types=1);

namespace Dotclear\Plugin\Orphelins;

use Dotclear\App;
use Dotclear\Helper\Network\HttpClient;
use Throwable;

/**
 * @brief       Orphelins links checker.
 * @ingroup     Orphelins
 *
 * Ask a web server if an address still exists, with the HTTP client of Dotclear.
 *
 * The addresses come from the entries, written by any author of the blog, and the request is sent by the server
 * of the blog: local and private addresses are not requested, so the check can not be used to look into
 * the network of the server. Redirections are followed here, one by one, for the same reason.
 *
 * @author      Jean-Christian Paul Denis
 * @copyright   AGPL-3.0
 */
class Http
{
    /**
     * The time given to a server to answer, in seconds.
     *
     * @var     int     TIMEOUT
     */
    public const TIMEOUT = 5;

    /**
     * The maximum number of redirections followed.
     *
     * @var     int     REDIRECTS
     */
    public const REDIRECTS = 5;

    /**
     * The answers which say nothing about the link: the server refuses robots, asks to log in or is overloaded.
     *
     * @var     array<int, int>     UNSURE
     */
    private const UNSURE = [401, 402, 403, 405, 406, 407, 408, 409, 418, 421, 423, 425, 426, 428, 429, 451, 999];

    /**
     * The hosts already looked for: true if a host can be requested, false if not, null if it does not exist.
     *
     * @var     array<string, null|bool>    $hosts
     */
    private static array $hosts = [];

    /**
     * Check a link.
     *
     * @param   string              $url        The full address
     * @param   array<int, string>  $ignored    The domains not to check
     *
     * @return  array{status: int, code: int, info: string}
     */
    public static function check(string $url, array $ignored = []): array
    {
        $first = 0;

        for ($hop = 0; $hop <= self::REDIRECTS; $hop++) {
            $host = Url::host($url);
            if ($host === '' || preg_match('#^https?://#i', $url) !== 1) {
                return self::answer(Items::STATUS_BROKEN, $first, Items::INFO_HOST);
            }

            if (self::isIgnored($host, $ignored)) {
                return self::answer(Items::STATUS_SKIPPED, $first, Items::INFO_DOMAIN . ' ' . $host);
            }

            $allowed = self::isAllowed($host);
            if (is_null($allowed)) {
                return self::answer(Items::STATUS_BROKEN, $first, Items::INFO_HOST . ' ' . $host);
            }
            if (!$allowed) {
                return self::answer(Items::STATUS_SKIPPED, $first, Items::INFO_LOCAL . ' ' . $host);
            }

            try {
                $path   = '';
                $client = HttpClient::initClient($url, $path);
                if ($client === false) {
                    return self::answer(Items::STATUS_BROKEN, $first, Items::INFO_HOST);
                }

                $client->setTimeout(self::TIMEOUT);
                $client->setStreamTimeout(self::TIMEOUT);
                $client->setUserAgent('Mozilla/5.0 (compatible; Dotclear ' . My::id() . '; +' . App::blog()->url() . ')');
                $client->setHandleRedirects(false);
                $client->setPersistCookies(false);
                $client->setPersistReferers(false);
                // Only the answer of the server is needed, not the page
                $client->setHeadersOnly(true);
                $client->get($path === '' ? '/' : $path);

                $code     = $client->getStatus();
                $location = $client->getHeader('location');
            } catch (Throwable $exception) {
                $message = $exception->getMessage();

                return stripos($message, 'timeout') !== false || stripos($message, 'timed out') !== false ?
                    self::answer(Items::STATUS_UNSURE, $first, Items::INFO_TIMEOUT) :
                    self::answer(Items::STATUS_BROKEN, $first, Items::INFO_CONNECT . ' ' . $message);
            }

            if ($first === 0) {
                $first = $code;
            }

            if ($code >= 300 && $code < 400 && $code !== 304) {
                if (is_array($location)) {
                    $location = $location[0] ?? '';
                }
                $next = is_string($location) && $location !== '' ? Url::resolve($location, $url) : null;
                if (is_null($next)) {
                    // A redirection to nowhere
                    return self::answer(Items::STATUS_UNSURE, $code, Items::INFO_HTTP);
                }
                $url = $next;

                continue;
            }

            if ($code >= 200 && $code < 400) {
                return $hop > 0 ?
                    self::answer(Items::STATUS_OK, $first, Items::INFO_REDIRECT . ' ' . $url) :
                    self::answer(Items::STATUS_OK, $code, Items::INFO_HTTP);
            }

            $status = $code >= 500 || in_array($code, self::UNSURE, true) ? Items::STATUS_UNSURE : Items::STATUS_BROKEN;

            return self::answer($status, $code, $hop > 0 ? Items::INFO_REDIRECT . ' ' . $url : Items::INFO_HTTP);
        }

        return self::answer(Items::STATUS_UNSURE, $first, Items::INFO_REDIRECTS);
    }

    /**
     * Clean a list of domains typed by a user: one per line, or separated by spaces or commas.
     *
     * @return  array<int, string>
     */
    public static function domains(string $text): array
    {
        $domains = [];
        foreach (preg_split('/[\s,;]+/', strtolower($text)) ?: [] as $domain) {
            // An address is accepted: only its host is kept
            $domain = (string) preg_replace('#^[a-z]+://#', '', $domain);
            $domain = trim((string) preg_replace('#[/:?].*$#', '', $domain), '.*');
            if ($domain !== '' && preg_match('/^[a-z0-9._-]+$/', $domain) === 1) {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains));
    }

    /**
     * Check if a host is one of the ignored domains, or in one of them.
     *
     * @param   array<int, string>  $ignored    The domains not to check
     */
    private static function isIgnored(string $host, array $ignored): bool
    {
        foreach ($ignored as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a host can be requested.
     *
     * @return  null|bool   Null if the host does not exist, false if this is a local or private address
     */
    private static function isAllowed(string $host): ?bool
    {
        if (!array_key_exists($host, self::$hosts)) {
            self::$hosts[$host] = self::resolveAllowed($host);
        }

        return self::$hosts[$host];
    }

    private static function resolveAllowed(string $host): ?bool
    {
        // The blog itself is always checked, even on a local network
        if ($host === Url::host(App::blog()->url())) {
            return true;
        }

        $name = trim($host, '[]');
        if (filter_var($name, FILTER_VALIDATE_IP) !== false) {
            $ips = [$name];
        } else {
            $ips = gethostbynamel($name);
            if ($ips === false || $ips === []) {
                // Maybe a host with IPv6 addresses only
                $ips     = [];
                $records = function_exists('dns_get_record') ? @dns_get_record($name, DNS_AAAA) : false;
                foreach (is_array($records) ? $records : [] as $record) {
                    if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                        $ips[] = $record['ipv6'];
                    }
                }
                if ($ips === []) {
                    return null;
                }
            }
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return  array{status: int, code: int, info: string}
     */
    private static function answer(int $status, int $code, string $info): array
    {
        return ['status' => $status, 'code' => $code, 'info' => $info];
    }
}
