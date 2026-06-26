<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Turn a Joomla template-override file path into a one-line, plain-
 * English description so non-technical viewers of the backup list can
 * tell at a glance what each row actually is — without learning what
 * `com_content/featured/default_links.php` means.
 *
 * Resolution order:
 *   1. Whitelist of well-known core layouts (high-confidence, hand-
 *      curated copy).
 *   2. Pattern-based derivation from the path's first segment after
 *      `html/` — covers any com_*, mod_*, plg_*, layouts/* override
 *      we don't have an explicit row for.
 *   3. Fallback: just label it as a generic template override.
 *
 * Keep this file path-driven. Don't reach into the database; the same
 * helper is used by the backups list, the backup detail view, and the
 * sessions view, all of which already have the path on hand.
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Administrator\Helper;

defined('_JEXEC') or die;

final class BackupDescriber
{
    /**
     * Hand-curated descriptions for the most common Joomla overrides
     * we expect to encounter on a typical site. Keys are the path
     * suffix BELOW `html/`, lowercase. Add freely as we encounter new
     * patterns in the wild — the worse-case fallback below is still
     * informative on its own.
     *
     * @var array<string, string>
     */
    private const KNOWN = [
        // Articles (com_content)
        'com_content/article/default.php'              => 'Single article view',
        'com_content/category/default.php'             => 'Category landing page',
        'com_content/category/blog.php'                => 'Category blog layout',
        'com_content/category/blog_item.php'           => 'Category blog &mdash; one article row',
        'com_content/category/blog_links.php'          => 'Category blog &mdash; "more articles" links list',
        'com_content/featured/default.php'             => 'Featured articles &mdash; main layout',
        'com_content/featured/default_links.php'       => 'Featured articles &mdash; "more articles" links list',
        'com_content/archive/default.php'              => 'Article archive view',
        'com_content/form/edit.php'                    => 'Front-end article submission form',

        // Contact (com_contact)
        'com_contact/contact/default.php'              => 'Contact form / contact page',
        'com_contact/category/default.php'             => 'Contact list (by category)',

        // Tags (com_tags)
        'com_tags/tag/default.php'                     => 'Tag landing page',

        // Menus (mod_menu / mod_menu)
        'mod_menu/default.php'                         => 'Site menu (top-level wrapper)',
        'mod_menu/default_component.php'               => 'Site menu &mdash; component link',
        'mod_menu/default_separator.php'               => 'Site menu &mdash; separator',
        'mod_menu/default_url.php'                     => 'Site menu &mdash; URL link',
        'mod_menu/default_heading.php'                 => 'Site menu &mdash; heading',

        // Login / users
        'mod_login/default.php'                        => 'Login form module',
        'com_users/login/default.php'                  => 'Login page',
        'com_users/profile/default.php'                => 'User profile page',
        'com_users/registration/default.php'           => 'New user registration form',

        // Search
        'mod_finder/default.php'                       => 'Search box module (Smart Search)',
        'com_finder/search/default.php'                => 'Search results page (Smart Search)',

        // Breadcrumbs / pathway
        'mod_breadcrumbs/default.php'                  => 'Breadcrumb trail module',

        // Pagination / page-navigation plugin
        'plg_content_pagenavigation/default.php'       => 'Article previous / next navigation links',

        // System layouts (the layouts/ path)
        'layouts/joomla/system/message.php'            => 'System notification messages (success / warning / error banners)',
        'layouts/joomla/form/renderfield.php'          => 'Generic form-field renderer',

        // Pagination
        'layouts/joomla/pagination/list.php'           => 'Pagination control (page numbers)',
        'layouts/joomla/pagination/link.php'           => 'Pagination &mdash; one page link',
    ];

    /**
     * Top-level segment fallback descriptions, used when KNOWN doesn't
     * have an exact match.
     *
     * @var array<string, string>
     */
    private const SEGMENT_LABELS = [
        'com_content'  => 'Articles &amp; categories',
        'com_contact'  => 'Contacts',
        'com_users'    => 'Users / login / profile',
        'com_tags'     => 'Tags',
        'com_finder'   => 'Search (Smart Search)',
        'com_search'   => 'Search (legacy)',
        'com_banners'  => 'Banners',
        'com_newsfeeds'=> 'News feeds',
        'com_weblinks' => 'Web links',
        'com_modules'  => 'Modules administration',
        'com_menus'    => 'Menus administration',
        'mod_menu'     => 'Site menu module',
        'mod_login'    => 'Login module',
        'mod_finder'   => 'Search module',
        'mod_breadcrumbs' => 'Breadcrumbs module',
        'mod_articles_latest' => 'Latest articles module',
        'mod_articles_popular' => 'Most read articles module',
        'mod_articles_archive' => 'Articles archive module',
        'mod_articles_categories' => 'Article categories module',
        'mod_articles_category' => 'Articles in a category module',
        'mod_articles_news'    => 'Newsflash / articles-news module',
        'mod_random_image'     => 'Random image module',
        'mod_related_items'    => 'Related articles module',
        'mod_syndicate'        => 'RSS feed module',
        'mod_tags_popular'     => 'Popular tags module',
        'mod_tags_similar'     => 'Similar tags module',
        'mod_users_latest'     => 'Latest registered users module',
        'mod_whosonline'       => 'Who\'s online module',
        'mod_wrapper'          => 'Wrapper / iframe module',
        'mod_languages'        => 'Language switcher module',
        'mod_footer'           => 'Footer module',
    ];

    /**
     * Return a one-line description of what the override file does.
     */
    public static function describe(string $filePath): string
    {
        $relativeBelowHtml = self::extractBelowHtml($filePath);

        if ($relativeBelowHtml === null) {
            // Path doesn't include /html/ at all — probably a non-
            // override backup created by some other tool. Just say so.
            return 'Template / layout file (not a tracked override)';
        }

        // Exact-match whitelist hit?
        $key = strtolower($relativeBelowHtml);
        if (isset(self::KNOWN[$key])) {
            return self::KNOWN[$key];
        }

        // Pattern-based fallback by first segment.
        $segments = explode('/', $relativeBelowHtml, 2);
        $first    = $segments[0] ?? '';
        $rest     = $segments[1] ?? '';

        if (str_starts_with($first, 'com_')) {
            $label = self::SEGMENT_LABELS[$first] ?? self::titleCase(substr($first, 4)) . ' component';
            return $label . ' &mdash; ' . self::tidy($rest);
        }

        if (str_starts_with($first, 'mod_')) {
            $label = self::SEGMENT_LABELS[$first] ?? self::titleCase(substr($first, 4)) . ' module';
            return $label . ($rest !== '' ? ' &mdash; ' . self::tidy($rest) : '');
        }

        if (str_starts_with($first, 'plg_')) {
            // plg_<group>_<element>
            $remainder = substr($first, 4);
            $parts     = explode('_', $remainder, 2);
            if (count($parts) === 2) {
                [$group, $element] = $parts;
                return self::titleCase($element) . ' ' . self::titleCase($group) . ' plugin'
                    . ($rest !== '' ? ' &mdash; ' . self::tidy($rest) : '');
            }
            return 'Plugin override &mdash; ' . self::tidy($relativeBelowHtml);
        }

        if ($first === 'layouts') {
            return 'Shared layout &mdash; ' . self::tidy($rest);
        }

        return 'Template override &mdash; ' . self::tidy($relativeBelowHtml);
    }

    /**
     * Extract the part of the path below `html/`. Returns null if the
     * path doesn't pass through an `html/` segment at all.
     */
    private static function extractBelowHtml(string $filePath): ?string
    {
        $normalized = str_replace('\\', '/', ltrim($filePath, '/\\'));
        $needle     = '/html/';
        $pos        = strpos('/' . $normalized, $needle);
        if ($pos === false) {
            return null;
        }
        // Strip everything up to and including the FIRST /html/.
        $tail = substr('/' . $normalized, $pos + strlen($needle));
        return $tail !== false ? $tail : null;
    }

    /**
     * "default_links.php" → "default links"
     * "blog_links.php"    → "blog links"
     *
     * The output is HTML-escaped before return because describe()
     * renders unescaped into admin templates (it intentionally splices
     * in literal `&mdash;` entities for layout). Today the only caller
     * passes path components derived from realpath() of on-disk files,
     * which can't contain `<`, but a future caller (or a hand-edited
     * #__csoverridechecker_backups row) could change that — this is
     * defense in depth, flagged as M-1 in the v2.0.0 security review.
     */
    private static function tidy(string $segment): string
    {
        $segment = (string) preg_replace('/\.php$/i', '', $segment);
        $segment = str_replace('_', ' ', $segment);
        return htmlspecialchars($segment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * "pagenavigation" → "Pagenavigation"
     * "content"        → "Content"
     *
     * Escaped on the way out — see tidy().
     */
    private static function titleCase(string $word): string
    {
        return htmlspecialchars(ucfirst(strtolower($word)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
