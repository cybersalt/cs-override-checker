<?php

/**
 * @package     Cstemplateintegrity
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cstemplateintegrity\Administrator\Controller;

defined('_JEXEC') or die;

use Cybersalt\Component\Cstemplateintegrity\Administrator\Helper\ConversationRunner;
use Cybersalt\Component\Cstemplateintegrity\Administrator\Helper\PermissionHelper;
use Cybersalt\Component\Cstemplateintegrity\Administrator\Helper\SessionsHelper;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Throwable;

final class SessionController extends BaseController
{
    protected $default_view = 'session';

    public function cancel(): void
    {
        $this->setRedirect(Route::_('index.php?option=com_cstemplateintegrity&view=sessions', false));
    }

    public function download(): void
    {
        // GET-form CSRF guard. The download link in the session view
        // includes a session token query param; without it the request
        // is rejected so a cross-site attacker cannot trick a logged-in
        // admin into exfiltrating a session report by sending a crafted URL.
        $this->checkToken('get');
        PermissionHelper::requireView();

        /** @var CMSApplication $app */
        $app = $this->app;
        $id  = (int) $app->getInput()->getInt('id', 0);

        if ($id <= 0) {
            $app->enqueueMessage(Text::_('COM_CSTEMPLATEINTEGRITY_SESSION_DOWNLOAD_BAD_ID'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_cstemplateintegrity&view=sessions', false));
            return;
        }

        $session = SessionsHelper::find($id);
        if ($session === null) {
            $app->enqueueMessage(Text::_('COM_CSTEMPLATEINTEGRITY_SESSION_DOWNLOAD_NOT_FOUND'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_cstemplateintegrity&view=sessions', false));
            return;
        }

        $contents = (string) ($session->report_markdown ?? '');
        // Strip any chars outside [A-Za-z0-9._-], then trim leading /
        // trailing dots so a session named "." or ".." (or after the
        // regex strips everything but a leading dot) doesn't produce
        // "cstemplateintegrity-..md" or worse on disk. Empty after
        // sanitization falls back to the session id, matching the
        // SessionsController::downloadSelected fallback.
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $session->name);
        $safeName = trim((string) $safeName, '.');
        $filename = 'cstemplateintegrity-' . ($safeName !== '' ? $safeName : 'session-' . $id) . '.md';

        $app->setHeader('Content-Type', 'text/markdown; charset=utf-8', true);
        $app->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"', true);
        $app->setHeader('Content-Length', (string) strlen($contents), true);
        $app->sendHeaders();
        echo $contents;
        $app->close();
    }

    /**
     * Append the user's follow-up message to a session's conversation
     * and run the Anthropic tool-use loop until Claude finishes.
     * Saves the updated conversation back to the session row, then
     * redirects back to the session view (so the new turns render).
     */
    public function continueChat(): void
    {
        $this->checkToken();
        PermissionHelper::requireWrite();

        /** @var CMSApplication $app */
        $app  = $this->app;
        $id   = (int) $app->getInput()->getInt('id', 0);
        $msg  = trim((string) $app->getInput()->post->get('message', '', 'string'));
        $back = Route::_('index.php?option=com_cstemplateintegrity&view=session&id=' . $id, false);

        if ($id <= 0) {
            $app->enqueueMessage(Text::_('COM_CSTEMPLATEINTEGRITY_SESSION_CHAT_BAD_ID'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_cstemplateintegrity&view=sessions', false));
            return;
        }

        if ($msg === '') {
            $app->enqueueMessage(Text::_('COM_CSTEMPLATEINTEGRITY_SESSION_CHAT_EMPTY'), 'warning');
            $this->setRedirect($back);
            return;
        }

        $session = SessionsHelper::find($id);
        if ($session === null) {
            $app->enqueueMessage(Text::_('COM_CSTEMPLATEINTEGRITY_SESSION_CHAT_NOT_FOUND'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_cstemplateintegrity&view=sessions', false));
            return;
        }

        $params = ComponentHelper::getParams('com_cstemplateintegrity');
        $apiKey = (string) $params->get('anthropic_api_key', '');
        if (trim($apiKey) === '') {
            $app->enqueueMessage(Text::_('COM_CSTEMPLATEINTEGRITY_RUN_SCAN_NO_KEY'), 'warning');
            $this->setRedirect($back);
            return;
        }

        $messages = SessionsHelper::getMessages($session);
        if (empty($messages)) {
            // Older sessions don't carry a stored conversation. Seed one
            // from the existing report_markdown so the chat has something
            // to anchor the system prompt against.
            $messages = [
                ['role' => 'user',      'content' => 'Initial scan report from this session.'],
                ['role' => 'assistant', 'content' => (string) ($session->report_markdown ?? '')],
            ];
        } elseif (
            isset($messages[0]['role'], $messages[0]['content'])
            && $messages[0]['role'] === 'user'
            && is_string($messages[0]['content'])
            && strlen($messages[0]['content']) > 5000
        ) {
            // Sessions seeded by an older build of ScanRunnerHelper
            // persisted the entire override-file dump (~60K input
            // tokens) as the first user message. Resending that on
            // every chat turn instantly trips Anthropic's
            // 10K-tokens-per-minute rate limit. Replace with a short
            // summary; Claude can re-fetch specific files via the
            // get_override_file tool if a follow-up needs them.
            $messages[0]['content'] = 'I asked you to review the flagged template overrides on my Joomla site. Your initial scan report is the next message. I may now ask you to apply fixes or dismiss findings — call tools (list_remaining_overrides, get_override_file, get_core_file, apply_fix, dismiss_override, dismiss_all) as needed.';
        }

        @set_time_limit(180);

        $chatModel = self::resolveModel($params->get('chat_model', 'claude-opus-4-7'));

        try {
            $result = ConversationRunner::continueConversation($apiKey, $messages, $msg, $id, $chatModel);
            SessionsHelper::saveMessages($id, $result['messages']);

            $app->enqueueMessage(Text::_('COM_CSTEMPLATEINTEGRITY_SESSION_CHAT_SUCCESS'), 'success');
            $this->setRedirect($back);
        } catch (Throwable $e) {
            $app->enqueueMessage(
                Text::sprintf('COM_CSTEMPLATEINTEGRITY_SESSION_CHAT_ERROR', $e->getMessage()),
                'error'
            );
            $this->setRedirect($back);
        }
    }

    /**
     * Whitelist a model id from component params against the same set
     * config.xml exposes. Defends against a config-form bypass — if
     * anything other than our three known model ids comes back from
     * params, fall through to Opus (the configured default).
     */
    private static function resolveModel(string $candidate): string
    {
        $allowed = [
            'claude-haiku-4-5-20251001',
            'claude-sonnet-4-6',
            'claude-opus-4-7',
        ];
        return in_array($candidate, $allowed, true) ? $candidate : 'claude-opus-4-7';
    }
}
