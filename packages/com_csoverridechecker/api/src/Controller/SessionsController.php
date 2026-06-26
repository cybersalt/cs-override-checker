<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Web Services routes for session-log entries:
 *
 *   GET  /api/index.php/v1/csoverridechecker/sessions
 *   GET  /api/index.php/v1/csoverridechecker/sessions/{id}
 *   POST /api/index.php/v1/csoverridechecker/sessions
 *
 * Read endpoints require csoverridechecker.view (or core.manage). The POST
 * endpoint requires csoverridechecker.write. Reports may contain sensitive
 * configuration details, so unauthenticated access is refused.
 *
 * The POST endpoint lets Claude (typically Claude Code) submit its
 * findings directly to the site. Body is a flat JSON object:
 *   { "name": "optional", "summary": "optional", "report_markdown": "..." }
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Api\Controller;

defined('_JEXEC') or die;

use Cybersalt\Component\Csoverridechecker\Administrator\Helper\PermissionHelper;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\SessionsHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Controller\ApiController;
use Joomla\CMS\Uri\Uri;
use Throwable;

final class SessionsController extends ApiController
{
    protected $contentType = 'sessions';

    protected $default_view = 'sessions';

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
        $this->createSession();
    }

    public function edit(): void
    {
        if (!$this->authoriseOrFail(PermissionHelper::class . '::requireWrite')) {
            return;
        }
        $this->createSession();
    }

    private function createSession(): void
    {
        try {
            $body = $this->parseJsonBody();

            $name           = isset($body['name']) ? (string) $body['name'] : null;
            $summary        = isset($body['summary']) ? (string) $body['summary'] : null;
            $reportMarkdown = isset($body['report_markdown']) ? (string) $body['report_markdown'] : null;
            $source         = isset($body['source']) && is_string($body['source'])
                ? $body['source']
                : SessionsHelper::SOURCE_API;

            $id = SessionsHelper::create($name, $summary, $reportMarkdown, $source);

            $this->sendJsonApi(
                [
                    'data' => [
                        'type'       => 'csoverridechecker-sessions',
                        'id'         => (string) $id,
                        'attributes' => [
                            'id'      => $id,
                            'name'    => $name ?? SessionsHelper::autoName(),
                            'source'  => $source,
                            'summary' => $summary ?? '',
                        ],
                        'links' => [
                            'self' => rtrim(Uri::root(), '/') . '/api/index.php/v1/csoverridechecker/sessions/' . $id,
                        ],
                    ],
                ],
                201
            );
        } catch (Throwable $e) {
            $this->sendJsonApi(
                [
                    'errors' => [
                        ['status' => '500', 'code' => 'CREATE_FAILED', 'title' => $e->getMessage()],
                    ],
                ],
                500
            );
        }
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
