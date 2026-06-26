<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Resolves the override and core file paths from a row in
 * #__template_overrides. The schema's `hash_id` column is a
 * base64-encoded relative path beginning with /html/, e.g.
 *   /html/com_content/featured/default_links.php
 *
 * The override file always lives under the template's html/ folder.
 * The core file lives at a path derived by stripping /html/ and
 * mapping the first segment to its core source location:
 *   layouts/…              → JPATH_SITE/layouts/…
 *   com_<comp>/<v>/<f>     → JPATH_SITE/components/com_<comp>/tmpl/<v>/<f>
 *   mod_<mod>/<f>          → JPATH_SITE/modules/mod_<mod>/tmpl/<f>
 *   plg_<group>_<el>/<f>   → JPATH_PLUGINS/<group>/<el>/tmpl/<f>
 *
 * For client_id=1 the JPATH_SITE roots above shift to
 * JPATH_ADMINISTRATOR (except plg_*, which always lives at
 * JPATH_PLUGINS).
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Administrator\Helper;

defined('_JEXEC') or die;

final class PathResolver
{
    public static function decodeHashId(string $hashId): ?string
    {
        $decoded = base64_decode($hashId, true);
        if ($decoded === false) {
            return null;
        }
        return $decoded;
    }

    public static function overridePath(string $template, string $hashId, int $clientId): ?string
    {
        $relative = self::decodeHashId($hashId);
        if ($relative === null) {
            return null;
        }

        $root = $clientId === 1 ? JPATH_ADMINISTRATOR : JPATH_SITE;

        return $root . '/templates/' . $template . $relative;
    }

    public static function corePath(string $hashId, int $clientId): ?string
    {
        $relative = self::decodeHashId($hashId);
        if ($relative === null) {
            return null;
        }

        $prefix = '/html/';
        if (!str_starts_with($relative, $prefix)) {
            return null;
        }

        $viewPath = substr($relative, strlen($prefix));
        $segments = explode('/', $viewPath, 2);
        if (count($segments) !== 2) {
            return null;
        }

        [$first, $rest] = $segments;
        $clientRoot = $clientId === 1 ? JPATH_ADMINISTRATOR : JPATH_SITE;

        if ($first === 'layouts') {
            return $clientRoot . '/layouts/' . $rest;
        }

        if (str_starts_with($first, 'com_')) {
            return $clientRoot . '/components/' . $first . '/tmpl/' . $rest;
        }

        if (str_starts_with($first, 'mod_')) {
            return $clientRoot . '/modules/' . $first . '/tmpl/' . $rest;
        }

        if (str_starts_with($first, 'plg_')) {
            $remainder = substr($first, 4);
            $parts = explode('_', $remainder, 2);
            if (count($parts) !== 2) {
                return null;
            }
            [$group, $element] = $parts;
            return JPATH_PLUGINS . '/' . $group . '/' . $element . '/tmpl/' . $rest;
        }

        return null;
    }
}
