<?php
declare(strict_types=1);
namespace Chengyu\Core;

/** URL paths always use '/', regardless of the server operating system. */
final class WebPath
{
    public static function directory(string $path, int $levels = 1): string
    {
        if ($levels < 1 || $levels > 8) { throw new \InvalidArgumentException('Invalid path depth.'); }
        $path = str_replace('\\', '/', $path);
        if ($path === '' || $path[0] !== '/' || strpos($path, '//') === 0 || preg_match('/[\x00-\x20\x7f?#]/', $path)) {
            throw new Problem('Invalid application URL path.');
        }
        for ($i = 0; $i < $levels; $i++) {
            $cut = strrpos($path, '/');
            $path = $cut === false ? '' : substr($path, 0, $cut);
        }
        return rtrim($path, '/');
    }
}
