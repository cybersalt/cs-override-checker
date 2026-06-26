<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Hard guards around any code path that writes to disk under
 * JPATH_ROOT. Encapsulates the path-traversal check and the
 * "where is it safe to write a .php file" policy in one place so
 * we cannot accidentally drift apart in restore vs. apply-fix.
 *
 * The traversal check is separator-anchored (str_starts_with with a
 * trailing DIRECTORY_SEPARATOR) — strpos-based prefix checks are
 * bypassable when the site root has a sibling directory whose name
 * begins with the same prefix, e.g. /var/www/joomla and /var/www/joomla-bak.
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Administrator\Helper;

defined('_JEXEC') or die;

final class PathSafetyHelper
{
    /**
     * PHP-executable extensions. Used by invalidateOpcacheIfPhp() to
     * decide whether an opcache invalidation is meaningful. The write
     * gate itself no longer hinges on the extension — see
     * assertOverrideWriteAllowed() for the current write policy.
     */
    private const PHP_EXECUTABLE_EXTENSIONS = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'pht'];

    /**
     * Hard upper cap on bytes written by apply_fix or restore. 4 MB
     * comfortably accommodates every legitimate Joomla template
     * override file ever observed in production while preventing the
     * "write a multi-gigabyte body and watch the disk fill" abuse
     * primitive a write-tier attacker could otherwise reach.
     */
    public const MAX_WRITE_SIZE = 4194304;

    /**
     * Resolve and assert that $absolute lives under JPATH_ROOT.
     *
     * Resolves dirname($absolute) through realpath() so symlinks and
     * .. segments collapse, then compares with a separator-anchored
     * str_starts_with — preventing the /var/www/joomla vs
     * /var/www/joomla-bak prefix-collision bypass.
     *
     * Returns the canonical $rootReal so callers can reuse it.
     *
     * @return array{rootReal: string, parentReal: string}
     *
     * @throws \RuntimeException if the parent dir is missing or escapes the root.
     */
    public static function assertWithinRoot(string $absolute): array
    {
        $parentReal = realpath(\dirname($absolute));
        $rootReal   = realpath(JPATH_ROOT);

        if ($parentReal === false || $rootReal === false) {
            throw new \RuntimeException('Refusing to write: target path could not be resolved.');
        }

        $rootSep   = rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $parentSep = $parentReal . DIRECTORY_SEPARATOR;

        if (!str_starts_with($parentSep, $rootSep)) {
            throw new \RuntimeException('Refusing to write: target path is outside the Joomla site root.');
        }

        return ['rootReal' => $rootReal, 'parentReal' => $parentReal];
    }

    /**
     * Positive allow-list. The ONLY paths this extension may write to
     * are Joomla template-override files, which by Joomla's contract
     * always live under:
     *
     *   <root>/templates/<tpl>/html/<...>             (site)
     *   <root>/administrator/templates/<tpl>/html/<...>  (admin)
     *
     * Anywhere else — components, modules, plugins, libraries, /tmp,
     * /cache, .htaccess in the document root, .user.ini, etc. — is
     * refused regardless of file extension. v0.9.0 closed the live
     * RCE primitive (free-form `file_path` on the backup POST), but
     * the extension-only check that survived would still permit a
     * write-tier user with seeded archival backup rows to overwrite
     * .htaccess, .user.ini, or template .css/.js files anywhere on
     * the site. This positive allow-list closes that residual surface
     * by rejecting the path itself, not just the extension.
     *
     * Callers must also pass assertWithinRoot() — this method does
     * NOT perform the realpath containment check; the prefix match
     * is purely textual on the resolved absolute path. The two are
     * complementary: containment proves "no escape via .. or
     * symlinks", this proves "lands inside an override root".
     *
     * @throws \RuntimeException if the path is not an override target.
     */
    public static function assertOverrideWriteAllowed(string $absolute): void
    {
        $normalized = str_replace('\\', '/', $absolute);
        $rootReal   = realpath(JPATH_ROOT);
        if ($rootReal === false) {
            throw new \RuntimeException('Refusing to write: site root could not be resolved.');
        }
        $rootSep = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';

        if (!str_starts_with($normalized, $rootSep)) {
            throw new \RuntimeException('Refusing to write: target path is outside the Joomla site root.');
        }

        $relative = substr($normalized, strlen($rootSep));

        // Allowed: templates/<tpl>/html/<...>
        // Allowed: administrator/templates/<tpl>/html/<...>
        $isSite  = (bool) preg_match('#^templates/[^/]+/html/.+#', $relative);
        $isAdmin = (bool) preg_match('#^administrator/templates/[^/]+/html/.+#', $relative);

        if (!$isSite && !$isAdmin) {
            throw new \RuntimeException(
                'Refusing to write outside a template override path. '
                . 'Allowed roots: <root>/templates/<tpl>/html/ and '
                . '<root>/administrator/templates/<tpl>/html/. '
                . 'Target: ' . $normalized
            );
        }
    }

    /**
     * Reject writes whose payload exceeds MAX_WRITE_SIZE. Defends
     * against a write-tier user (or a CSRF-coerced admin) using
     * apply_fix or restore as a disk-fill primitive — neither code
     * path previously capped the size of the *new* contents being
     * written, only the size of the pre-write backup snapshot.
     *
     * @throws \RuntimeException if $contents is larger than the cap.
     */
    public static function assertSizeAllowed(string $contents): void
    {
        $size = strlen($contents);
        if ($size > self::MAX_WRITE_SIZE) {
            throw new \RuntimeException(sprintf(
                'Refusing to write %d bytes; cap is %d (4 MB). Override files larger than this are not supported.',
                $size,
                self::MAX_WRITE_SIZE
            ));
        }
    }

    /**
     * Drop the PHP opcache entry for $absolute after a write so the
     * next request reads the new bytes. No-op when opcache isn't
     * loaded or has API access disabled.
     */
    public static function invalidateOpcacheIfPhp(string $absolute): void
    {
        $ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
        if (!in_array($ext, self::PHP_EXECUTABLE_EXTENSIONS, true)) {
            return;
        }

        if (\function_exists('opcache_invalidate')) {
            @opcache_invalidate($absolute, true);
        }
    }
}
