<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Decides whether new override contents add code a template layout has no
 * business adding. The apply-fix writers are driven by an AI that reads
 * untrusted site content, so a prompt injection could ask for a backdoor;
 * the prompt rules can't stop that and the auto-backup only undoes it later.
 *
 * It compares counts, old file against new, so an override that already
 * contains one of these can still be fixed; only adding another is refused.
 * PHP is tokenized rather than searched, so comments and text don't count.
 *
 * This raises the bar and no more. Enough indirection ('sys' . 'tem' handed to
 * array_map, say) gets past any list, and JavaScript in the HTML isn't looked at.
 * Joomla's own request input ($app->getInput()) isn't listed either: layouts use
 * it all the time, and the sinks it would feed are.
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

final class OverrideContentGuard
{
    private const FUNCTIONS = [
        // Run code or commands
        'assert', 'create_function', 'dl', 'exec', 'passthru', 'pcntl_exec', 'popen', 'proc_open', 'shell_exec', 'system',
        'call_user_func', 'call_user_func_array', 'unserialize',
        // Hide code
        'base64_decode', 'convert_uudecode', 'gzdecode', 'gzinflate', 'gzuncompress', 'hex2bin', 'str_rot13', 'zlib_decode',
        // Write or remove files
        'copy', 'file_put_contents', 'fopen', 'fputs', 'fwrite', 'move_uploaded_file', 'rename', 'unlink',
        // Read files
        'file', 'file_get_contents', 'fpassthru', 'highlight_file', 'readfile', 'show_source',
        // Reach out
        'curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client',
        // Take raw request input
        'filter_input', 'filter_input_array', 'getallheaders',
    ];

    /** Too ordinary as a plain string ('type' => 'file') to count as a callback name. */
    private const NOT_AS_STRING = ['file'];

    /** The same powers through a class. Joomla's are here too: File::write(), HttpFactory::getHttp(). */
    private const CLASSES = ['file', 'folder', 'http', 'httpfactory', 'reflectionfunction', 'reflectionmethod', 'splfileobject', 'spltempfileobject'];

    private const SUPERGLOBALS = ['$GLOBALS', '$_COOKIE', '$_ENV', '$_FILES', '$_GET', '$_POST', '$_REQUEST', '$_SERVER', '$_SESSION'];

    /**
     * What $new adds compared with $old, as code-like labels that read the same
     * in every language.
     *
     * @return string[]
     */
    public static function introduced(string $old, string $new): array
    {
        $before = self::count($old);
        $added  = [];

        foreach (self::count($new) as $key => $n) {
            if ($n > ($before[$key] ?? 0)) {
                // An include is keyed by its whole expression; the label is what comes before the NUL.
                $added[explode("\0", $key)[0]] = true;
            }
        }

        return array_keys($added);
    }

    /** The translated refusal, from any application: the API doesn't load the admin language. */
    public static function message(string $languageKey, array $added): string
    {
        $language = Factory::getApplication()->getLanguage();
        $language->load('com_csoverridechecker', JPATH_ADMINISTRATOR)
            || $language->load('com_csoverridechecker', JPATH_ADMINISTRATOR . '/components/com_csoverridechecker');

        return Text::sprintf($languageKey, implode(', ', $added));
    }

    /** @return array<string, int> */
    private static function count(string $source): array
    {
        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t) => !\is_array($t) || !\in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));

        $hits = [];
        $hit  = static function (string $key) use (&$hits): void {
            $hits[$key] = ($hits[$key] ?? 0) + 1;
        };

        foreach ($tokens as $i => $t) {
            $id     = \is_array($t) ? $t[0] : $t;
            $text   = \is_array($t) ? $t[1] : $t;
            $prev   = $tokens[$i - 1] ?? null;
            $next   = $tokens[$i + 1] ?? null;
            $prevId = \is_array($prev) ? $prev[0] : $prev;
            $nextId = \is_array($next) ? $next[0] : $next;

            switch ($id) {
                case T_EVAL:
                    $hit('eval');
                    break;

                case T_INCLUDE:
                case T_INCLUDE_ONCE:
                case T_REQUIRE:
                case T_REQUIRE_ONCE:
                    // By expression, so rewriting an existing require to load
                    // something else counts as a new one.
                    $hit('include/require' . "\0" . self::expression($tokens, $i + 1));
                    break;

                case '`':
                    $hit('`backticks`');
                    break;

                case '$':
                case T_DOLLAR_OPEN_CURLY_BRACES:
                    $hit('$$variable');
                    break;

                case T_VARIABLE:
                    if (\in_array($text, self::SUPERGLOBALS, true)) {
                        $hit($text);
                    }
                    if ($nextId === '(') {
                        $hit('$function()');
                    }
                    break;

                case ')':
                case ']':
                case '"':
                case T_END_HEREDOC:
                    // ('sys' . 'tem')('id'), $list[0]('id'), "{$a}stem"('id').
                    if ($nextId === '(') {
                        $hit('$function()');
                    }
                    break;

                case T_CONSTANT_ENCAPSED_STRING:
                case T_ENCAPSED_AND_WHITESPACE:
                    // 'system'('id') is a call as well as a name.
                    if ($nextId === '(') {
                        $hit('$function()');
                    }

                    // A callback named as a string: array_map('system', $list). Counted
                    // apart from calls, so swapping a stock 'system' string for a call
                    // to system() doesn't balance out.
                    $name = self::stringValue($text);
                    if (\in_array($name, self::FUNCTIONS, true) && !\in_array($name, self::NOT_AS_STRING, true)) {
                        $hit("'" . $name . "'");
                    }
                    if (str_contains($name, 'php://')) {
                        $hit('php://');
                    }
                    break;

                case T_STRING:
                case T_NAME_FULLY_QUALIFIED:
                case T_NAME_QUALIFIED:
                case T_NAME_RELATIVE:
                    if ($prevId === T_OBJECT_OPERATOR || $prevId === T_NULLSAFE_OBJECT_OPERATOR) {
                        break;
                    }

                    $name = strtolower($text);
                    $last = substr((string) strrchr('\\' . $name, '\\'), 1);

                    if (\in_array($last, self::CLASSES, true) && ($prevId === T_NEW || $nextId === T_DOUBLE_COLON)) {
                        $hit($last);
                        break;
                    }

                    // Not followed by "(" on purpose: "use function system as run;" names it too.
                    // A qualified name other than namespace\system is someone else's function.
                    $name = preg_replace('/^(?:namespace)?\\\\/', '', $name);
                    if (\in_array($name, self::FUNCTIONS, true)
                        && $prevId !== T_DOUBLE_COLON
                        && $prevId !== T_NEW
                        && $nextId !== T_DOUBLE_COLON
                    ) {
                        $hit($name);
                    }
                    break;
            }
        }

        return $hits;
    }

    /** The tokens of an include's expression, up to the end of the statement. */
    private static function expression(array $tokens, int $from): string
    {
        $parts = [];

        for ($i = $from; isset($tokens[$i]); $i++) {
            $t    = $tokens[$i];
            $id   = \is_array($t) ? $t[0] : $t;
            if ($id === ';' || $id === T_CLOSE_TAG) {
                break;
            }
            $parts[] = \is_array($t) ? $t[1] : $t;
        }

        return implode(' ', $parts);
    }

    /** A string token's value as PHP would see it: b'x', "\x73ystem" and "\u{73}ystem" are all names. */
    private static function stringValue(string $text): string
    {
        $text  = trim($text);
        $text  = ($text[0] ?? '') === 'b' || ($text[0] ?? '') === 'B' ? substr($text, 1) : $text;
        $quote = $text[0] ?? '';
        $value = trim($text, '\'"');

        if ($quote !== "'") {
            $value = (string) preg_replace_callback(
                '/\\\\u\{([0-9a-fA-F]{1,6})\}/',
                static fn ($m) => (string) mb_chr((int) hexdec($m[1]), 'UTF-8'),
                $value
            );
            $value = stripcslashes($value);
        }

        return strtolower(ltrim(trim($value), '\\'));
    }
}
