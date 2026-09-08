<?php
declare(strict_types=1);
namespace Chengyu\Core;
/** Validate a server-generated navigation URL. Never accepts a query-string return URL. */
final class ExternalNavigation
{
    public static function target(string $target): array
    {
        $parts = parse_url($target);
        if (strlen($target) > 16384 || preg_match('/[\x00-\x20\\\\]/', $target)
            || !filter_var($target, FILTER_VALIDATE_URL) || !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new Problem('Invalid external continuation.', 400);
        }
        return ['target' => $target, 'host' => $parts['host']];
    }
}
