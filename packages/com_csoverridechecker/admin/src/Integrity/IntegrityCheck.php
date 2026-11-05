<?php
/**
 * Shipped-file integrity check.
 *
 * Copied in from Joomla-Brain tools/integrity by Install-IntegrityCheck.ps1.
 * Change it in Joomla-Brain and re-run that script; don't edit this copy.
 *
 * @copyright   Copyright (C) 2026 Cybersalt Consulting Ltd.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Administrator\Integrity;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

/**
 * Compares the files an extension shipped (the integrity.json list written by
 * New-IntegrityList.ps1 at build time) with what is on disk now.
 *
 * Joomla does not roll back a failed update: files copied before the failure
 * stay new, the one that failed can be left at 0 bytes, and the rest stay
 * old. Security software (Imunify360 Proactive Defense), a full disk or a
 * timed-out upload all cause it. This check is how the extension finds out.
 */
final class IntegrityCheck
{
	public const LIST_NAME = 'integrity.json';

	/** How many file names each section of the alert lists before "and N more". */
	private const SHOW_MAX = 50;

	/**
	 * @param   string[]  $lists  integrity.json paths, relative to the Joomla root
	 *                            (e.g. "plugins/system/foo/integrity.json") or absolute.
	 *
	 * @return  array{status: string, checked: int, lists: int, missing: string[], empty: string[], changed: string[], unreadable: string[]}
	 *          status is 'ok', 'changed' (only edited files), 'problems' (missing,
	 *          empty or unreadable) or 'nolist' (no list installed, e.g. a dev build).
	 */
	public static function run(array $lists): array
	{
		$result = [
			'status'     => 'nolist',
			'checked'    => 0,
			'lists'      => 0,
			'missing'    => [],
			'empty'      => [],
			'changed'    => [],
			'unreadable' => [],
		];

		$root = rtrim(JPATH_ROOT, '/\\');

		clearstatcache();

		foreach ($lists as $list) {
			$listPath = self::absolute((string) $list, $root);

			// No list: an older or development build. Nothing to compare against.
			if ($listPath === null || !is_file($listPath)) {
				continue;
			}

			$data = json_decode((string) @file_get_contents($listPath), true);

			if (!\is_array($data) || !\is_array($data['files'] ?? null)) {
				// The list itself was damaged by the same failed copy.
				$result['unreadable'][] = self::relative($listPath, $root);
				continue;
			}

			$result['lists']++;

			foreach ($data['files'] as $path => $expected) {
				$path = (string) $path;
				$file = self::absolute($path, $root);

				if ($file === null || !\is_array($expected)) {
					continue;
				}

				$result['checked']++;
				$size = (int) ($expected['size'] ?? -1);

				if (!is_file($file)) {
					$result['missing'][] = $path;
				} elseif (($actual = (int) @filesize($file)) === 0 && $size > 0) {
					$result['empty'][] = $path;
				} elseif ($actual !== $size || !hash_equals((string) ($expected['sha256'] ?? ''), (string) @hash_file('sha256', $file))) {
					$result['changed'][] = $path;
				}
			}
		}

		if ($result['missing'] || $result['empty'] || $result['unreadable']) {
			$result['status'] = 'problems';
		} elseif ($result['changed']) {
			$result['status'] = 'changed';
		} elseif ($result['lists'] > 0) {
			$result['status'] = 'ok';
		}

		return $result;
	}

	/**
	 * Bootstrap alert for a run() result. Empty string when everything is fine,
	 * unless $showOk (settings-page fields want the "all good" line too).
	 *
	 * @param   string  $product  Plain-text extension name shown in the message.
	 */
	public static function renderAlert(array $result, string $product, bool $showOk = false): string
	{
		$e       = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		$checked = (int) ($result['checked'] ?? 0);
		$status  = (string) ($result['status'] ?? 'nolist');

		if ($status === 'ok' || $status === 'nolist') {
			if (!$showOk) {
				return '';
			}

			return $status === 'ok'
				? '<div class="alert alert-success mb-3">' . $e(self::text('CS_INTEGRITY_OK', 'All %1$d files match the released version.', $checked)) . '</div>'
				: '<div class="alert alert-info mb-3">' . $e(self::text('CS_INTEGRITY_NOLIST', 'This build has no file list, so the file check is unavailable.')) . '</div>';
		}

		$broken = \count($result['missing']) + \count($result['empty']) + \count($result['unreadable']);

		if ($status === 'problems') {
			$class = 'alert-danger';
			$title = self::text('CS_INTEGRITY_PROBLEMS_TITLE', 'Installation incomplete: %1$d of %2$d files are missing or damaged.', $broken, $checked);
			$body  = self::text(
				'CS_INTEGRITY_PROBLEMS_BODY',
				'%s did not install completely, so parts of it may not work. Install the same package again to repair it. If the install fails again, security software on the server (for example Imunify360 Proactive Defense) may be blocking Joomla\'s installer: check its incident log.',
				$product
			);
		} else {
			$class = 'alert-warning';
			$title = self::text('CS_INTEGRITY_CHANGED_TITLE', '%1$d of %2$d files differ from the released version.', \count($result['changed']), $checked);
			$body  = self::text(
				'CS_INTEGRITY_CHANGED_BODY',
				'This happens after a failed update, or when someone edits the files of %s directly. Install the same package again to restore them.',
				$product
			);
		}

		$sections = [
			'unreadable' => self::text('CS_INTEGRITY_UNREADABLE', 'File list unreadable'),
			'missing'    => self::text('CS_INTEGRITY_MISSING', 'Missing'),
			'empty'      => self::text('CS_INTEGRITY_EMPTY', 'Empty (0 bytes)'),
			'changed'    => self::text('CS_INTEGRITY_CHANGED', 'Changed'),
		];

		$details = '';

		foreach ($sections as $key => $label) {
			$paths = $result[$key] ?? [];

			if (!$paths) {
				continue;
			}

			$details .= '<p class="mb-1 mt-2"><strong>' . $e($label) . ' (' . \count($paths) . ')</strong></p><ul class="mb-0 small">';

			foreach (\array_slice($paths, 0, self::SHOW_MAX) as $path) {
				$details .= '<li><code class="text-reset">' . $e($path) . '</code></li>';
			}

			if (\count($paths) > self::SHOW_MAX) {
				$details .= '<li>' . $e(self::text('CS_INTEGRITY_MORE', 'and %d more', \count($paths) - self::SHOW_MAX)) . '</li>';
			}

			$details .= '</ul>';
		}

		return '<div class="alert ' . $class . ' mb-3" role="alert">'
			. '<p class="mb-1"><strong>' . $e($title) . '</strong></p>'
			. '<p class="mb-1">' . $e($body) . '</p>'
			. '<details><summary>' . $e(self::text('CS_INTEGRITY_SHOW_FILES', 'Show files')) . '</summary>' . $details . '</details>'
			. '</div>';
	}

	/** Translated string when the extension's language file has the key, else the English fallback. */
	private static function text(string $key, string $fallback, ...$args): string
	{
		try {
			if (Factory::getApplication()->getLanguage()->hasKey($key)) {
				return $args ? Text::sprintf($key, ...$args) : Text::_($key);
			}
		} catch (\Throwable $e) {
			// No application (CLI): fall through to English.
		}

		return $args ? vsprintf($fallback, $args) : $fallback;
	}

	/** Absolute path inside the Joomla root, or null for anything that tries to leave it. */
	private static function absolute(string $path, string $root): ?string
	{
		$path = str_replace('\\', '/', $path);

		if ($path === '' || str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path)) {
			return null;
		}

		$rootSlash = str_replace('\\', '/', $root) . '/';

		if (str_starts_with($path, $rootSlash)) {
			return $path;
		}

		if ($path[0] === '/' || preg_match('#^[A-Za-z]:/#', $path)) {
			return null;
		}

		return $rootSlash . $path;
	}

	private static function relative(string $path, string $root): string
	{
		$rootSlash = str_replace('\\', '/', $root) . '/';
		$path      = str_replace('\\', '/', $path);

		return str_starts_with($path, $rootSlash) ? substr($path, \strlen($rootSlash)) : $path;
	}
}
