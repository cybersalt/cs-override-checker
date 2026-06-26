<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Web Services routes for file backups:
 *
 *   GET  /api/index.php/v1/csoverridechecker/backups
 *   POST /api/index.php/v1/csoverridechecker/backups
 *   POST /api/index.php/v1/csoverridechecker/backups/:id/restore
 *
 * The POST endpoint takes a snapshot of an EXISTING tracked override
 * file. It does NOT accept an arbitrary file_path from the body — the
 * path is derived server-side from the override row referenced by
 * `override_id`. This prevents the create-backup-then-restore path
 * being abused as an arbitrary write primitive under JPATH_ROOT.
 *
 * Body for POST:
 *   { "override_id": 123,        // required — must reference #__template_overrides
 *     "session_id":  42 }        // optional — links the backup to a session
 *
 * The `contents` field is no longer accepted from the client; the
 * helper reads the live file at the resolved path and snapshots that.
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Api\Controller;

defined('_JEXEC') or die;

use Cybersalt\Component\Csoverridechecker\Administrator\Helper\BackupsHelper;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\PathResolver;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\PathSafetyHelper;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\PermissionHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\ApiController;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Throwable;

final class BackupsController extends ApiController
{
    protected $contentType = 'backups';

    protected $default_view = 'backups';

    public function displayList()
    {
        if (!$this->authoriseOrFail(PermissionHelper::class . '::requireView')) {
            return null;
        }
        return parent::displayList();
    }

    public function displayItem($id = null)
    {
        if (!$this->authoriseOrFail(PermissionHelper::class . '::requireView')) {
            return null;
        }
        return parent::displayItem($id);
    }

    public function add(): void
    {
        if (!$this->authoriseOrFail(PermissionHelper::class . '::requireWrite')) {
            return;
        }
        $this->createBackup();
    }

    public function edit(): void
    {
        if (!$this->authoriseOrFail(PermissionHelper::class . '::requireWrite')) {
            return;
        }
        $this->createBackup();
    }

    public function restore($id = null): void
    {
        if (!$this->authoriseOrFail(PermissionHelper::class . '::requireWrite')) {
            return;
        }

        try {
            // Resolve :id across method-arg, input, and URL-path regex.
            // Joomla's API dispatcher populates input for GET captures
            // but not POST captures; the regex fallback covers POST.
            if ($id === null || (int) $id <= 0) {
                $id = $this->input->getInt('id', 0);
            }
            if ((int) $id <= 0) {
                $path = (string) $this->input->server->get('REQUEST_URI', '', 'string');
                if (preg_match('#/backups/(\d+)/#', $path, $m)) {
                    $id = (int) $m[1];
                }
            }
            $id = (int) $id;
            if ($id <= 0) {
                $this->sendJsonApi(
                    ['errors' => [['status' => '400', 'code' => 'INVALID_ID', 'title' => 'A numeric backup id is required.']]],
                    400
                );
                return;
            }

            $stats = BackupsHelper::restore($id);

            $this->sendJsonApi(
                [
                    'data' => [
                        'type'       => 'csoverridechecker-restore',
                        'id'         => (string) $id,
                        'attributes' => $stats,
                    ],
                ],
                200
            );
        } catch (Throwable $e) {
            $this->sendJsonApi(
                ['errors' => [['status' => '500', 'code' => 'RESTORE_FAILED', 'title' => $e->getMessage()]]],
                500
            );
        }
    }

    /**
     * Snapshot the live override file referenced by `override_id`.
     *
     * The previous version of this endpoint accepted a free-form
     * `file_path` plus `contents` body. That made it possible for a
     * caller with a write API token to seed a backup row pointing at
     * any path under JPATH_ROOT and then call restore to write
     * arbitrary bytes there — an authenticated RCE primitive.
     *
     * The new contract:
     *   - Resolve the path server-side from #__template_overrides.
     *   - Read live contents from disk; do not trust client-supplied bytes.
     *   - Refuse any path that doesn't pass the same write-safety
     *     guard the restore step uses (parent dir under JPATH_ROOT,
     *     and PHP-extension files only inside templates/.../html/).
     */
    private function createBackup(): void
    {
        try {
            $body = $this->parseJsonBody();

            $overrideId = isset($body['override_id']) ? (int) $body['override_id'] : 0;
            $sessionId  = isset($body['session_id']) ? (int) $body['session_id'] : null;

            if ($overrideId <= 0) {
                $this->sendJsonApi(
                    [
                        'errors' => [[
                            'status' => '400',
                            'code'   => 'MISSING_OVERRIDE_ID',
                            'title'  => 'A numeric override_id referencing an existing #__template_overrides row is required.',
                        ]],
                    ],
                    400
                );
                return;
            }

            $row = $this->loadOverrideRow($overrideId);
            if ($row === null) {
                $this->sendJsonApi(
                    ['errors' => [['status' => '404', 'code' => 'OVERRIDE_NOT_FOUND', 'title' => 'No override row matches that override_id.']]],
                    404
                );
                return;
            }

            $absolute = PathResolver::overridePath((string) $row->template, (string) $row->hash_id, (int) $row->client_id);
            if ($absolute === null) {
                $this->sendJsonApi(
                    ['errors' => [['status' => '422', 'code' => 'PATH_UNRESOLVED', 'title' => 'Could not resolve a file path from the override row.']]],
                    422
                );
                return;
            }

            // Both guards must pass before we even read the file: the
            // restore() that may later be invoked against this backup
            // applies the same checks, and we want the create step to
            // refuse paths a restore could not write to.
            PathSafetyHelper::assertWithinRoot($absolute);
            PathSafetyHelper::assertOverrideWriteAllowed($absolute);

            if (!is_file($absolute)) {
                $this->sendJsonApi(
                    ['errors' => [['status' => '404', 'code' => 'FILE_MISSING', 'title' => 'The override file does not exist on disk; cannot back up empty file.']]],
                    404
                );
                return;
            }

            $contents = (string) @file_get_contents($absolute);

            // The relative path stored on the row must match what
            // OverridesHelper::applyFix uses, so a future restore lands
            // at the same location.
            $rootReal     = realpath(JPATH_ROOT) ?: JPATH_ROOT;
            $relativePath = ltrim(substr($absolute, strlen($rootReal)), '/\\');

            $id = BackupsHelper::createFromContents($relativePath, $contents, $sessionId);

            $this->sendJsonApi(
                [
                    'data' => [
                        'type'       => 'csoverridechecker-backups',
                        'id'         => (string) $id,
                        'attributes' => [
                            'id'          => $id,
                            'override_id' => $overrideId,
                            'file_path'   => $relativePath,
                            'file_size'   => strlen($contents),
                            'session_id'  => $sessionId,
                        ],
                        'links' => [
                            'self' => rtrim(Uri::root(), '/') . '/api/index.php/v1/csoverridechecker/backups/' . $id,
                        ],
                    ],
                ],
                201
            );
        } catch (Throwable $e) {
            $this->sendJsonApi(
                [
                    'errors' => [['status' => '500', 'code' => 'CREATE_FAILED', 'title' => $e->getMessage()]],
                ],
                500
            );
        }
    }

    private function loadOverrideRow(int $id): ?object
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'template', 'hash_id', 'client_id']))
            ->from($db->quoteName('#__template_overrides'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $row = $db->setQuery($query)->loadObject();
        return $row ?: null;
    }

    /**
     * @return array<string,mixed>
     */
    private function parseJsonBody(): array
    {
        $raw = (string) file_get_contents('php://input');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function sendJsonApi(array $payload, int $status = 200): void
    {
        $app = $this->app ?? Factory::getApplication();
        $app->setHeader('status', (string) $status, true);
        $app->setHeader('Content-Type', 'application/vnd.api+json; charset=utf-8', true);
        $app->sendHeaders();
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $app->close();
    }

    private function authoriseOrFail(string $check): bool
    {
        try {
            $check();
            return true;
        } catch (\RuntimeException $e) {
            $code  = $e->getCode() ?: 403;
            $name  = $e->getMessage() === 'AUTH_REQUIRED' ? 'AUTH_REQUIRED' : 'FORBIDDEN';
            $title = $name === 'AUTH_REQUIRED'
                ? 'Authentication is required for this endpoint.'
                : 'Your account is not authorised to access this csoverridechecker endpoint.';
            $this->sendJsonApi(
                ['errors' => [['status' => (string) ($code === 401 ? 401 : 403), 'code' => $name, 'title' => $title]]],
                $code === 401 ? 401 : 403
            );
            return false;
        }
    }
}
