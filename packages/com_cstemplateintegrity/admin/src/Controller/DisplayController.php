<?php

/**
 * @package     Cstemplateintegrity
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cstemplateintegrity\Administrator\Controller;

defined('_JEXEC') or die;

use Cybersalt\Component\Cstemplateintegrity\Administrator\Helper\ActionLogHelper;
use Cybersalt\Component\Cstemplateintegrity\Administrator\Helper\AnthropicClient;
use Cybersalt\Component\Cstemplateintegrity\Administrator\Helper\DisclaimerHelper;
use Cybersalt\Component\Cstemplateintegrity\Administrator\Helper\PermissionHelper;
use Cybersalt\Component\Cstemplateintegrity\Administrator\Helper\RescanHelper;
use Cybersalt\Component\Cstemplateintegrity\Administrator\Helper\ScanRunnerHelper;
use Cybersalt\Component\Cstemplateintegrity\Administrator\Helper\SessionsHelper;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Throwable;

final class DisplayController extends BaseController
{
    protected $default_view = 'dashboard';

    /**
     * Per-user cap on automated scans started within a rolling 60-min
     * window. 12 = one every five minutes — comfortably above legitimate
     * iterative use, low enough to keep accidental click-spam (or a
     * compromised admin's runaway loop) from burning the Anthropic key's
     * spend quota. Cap counts attempts, not successes.
     */
    private const SCAN_HOURLY_CAP = 12;

    public function rescan(): void
    {
        $this->checkToken();
        PermissionHelper::requireWrite();

        /** @var CMSApplication $app */
        $app = $this->app;

        // Optional template-id filter from the picker modal. Posted as
        // templates[] = "12" / "47" etc. Empty / absent → null (rescan
        // every enabled template, the original "reset all" behavior).
        // Non-empty → filter to those extension_ids only, after we've
        // validated each one exists on the picker list (defends against
        // a hand-crafted POST with arbitrary template ids).
        $rawIds = (array) $app->getInput()->post->get('templates', [], 'array');
        $filter = null;
        if (!empty($rawIds)) {
            $allowed = array_map(
                static fn ($t) => (int) $t['extension_id'],
                RescanHelper::listTemplatesWithOverrideDirs()
            );
            $picked  = array_values(array_unique(array_map('intval', $rawIds)));
            $filter  = array_values(array_intersect($picked, $allowed));

            if (empty($filter)) {
                $app->enqueueMessage(
                    Text::_('COM_CSTEMPLATEINTEGRITY_RESCAN_NO_VALID_TEMPLATES'),
                    'warning'
                );
                $this->setRedirect(Route::_('index.php?option=com_cstemplateintegrity&view=dashboard', false));
                return;
            }
        }

        try {
            $stats = RescanHelper::rebuildOverrideTracker($filter);
            $app->enqueueMessage(
                Text::sprintf(
                    'COM_CSTEMPLATEINTEGRITY_RESCAN_SUCCESS',
                    $stats['inserted'],
                    $stats['scanned'],
                    $stats['templates']
                ),
                'success'
            );
        } catch (Throwable $e) {
            $app->enqueueMessage(
                Text::sprintf('COM_CSTEMPLATEINTEGRITY_RESCAN_ERROR', $e->getMessage()),
                'error'
            );
        }

        $this->setRedirect(Route::_('index.php?option=com_cstemplateintegrity&view=dashboard', false));
    }

    /**
     * Validate a model id from component params against the small
     * whitelist we expose in config.xml. Defends against a future
     * config form bypass — if anything other than our three known
     * model ids comes back from params, fall through to Opus (the
     * configured default).
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

    /**
     * Run an automated scan against the saved Anthropic API key.
     * Walks the override tracker, builds one consolidated prompt,
     * sends it to Claude, saves the resulting markdown report as a
     * new session, then redirects the user to it.
     *
     * Synchronous and blocking: an Anthropic call with 50+ overrides
     * inline can run 30-90 seconds. We bump set_time_limit so PHP
     * doesn't kill the request mid-call. A background-job version is
     * a future iteration.
     */
    public function runScan(): void
    {
        $this->checkToken();
        PermissionHelper::requireWrite();

        /** @var CMSApplication $app */
        $app   = $this->app;
        $back  = Route::_('index.php?option=com_cstemplateintegrity&view=dashboard', false);

        $params = ComponentHelper::getParams('com_cstemplateintegrity');
        // Pass the raw stored value through to AnthropicClient — the
        // client tracks the pre-strip length for diagnostics so we can
        // tell "saved with embedded whitespace" apart from "saved
        // truncated" when the key fails.
        $apiKey = (string) $params->get('anthropic_api_key', '');

        if (trim($apiKey) === '') {
            $app->enqueueMessage(Text::_('COM_CSTEMPLATEINTEGRITY_RUN_SCAN_NO_KEY'), 'warning');
            $this->setRedirect($back);
            return;
        }

        // Per-user soft cap: refuse if this user has already started
        // SCAN_HOURLY_CAP automated scans in the past hour. Defends
        // against accidental click-spam and against a write-tier user
        // (or a CSRF-coerced admin who somehow passed checkToken)
        // burning the saved Anthropic key's spend quota in a tight
        // loop. The check counts ATTEMPTS, not successes — log entry
        // happens before the call below, so even failed scans count.
        $recentScans = ActionLogHelper::countActionsByCurrentUserSince(
            ActionLogHelper::ACTION_AUTO_SCAN_RUN,
            3600
        );
        if ($recentScans >= self::SCAN_HOURLY_CAP) {
            $app->enqueueMessage(
                Text::sprintf(
                    'COM_CSTEMPLATEINTEGRITY_RUN_SCAN_RATE_LIMITED',
                    self::SCAN_HOURLY_CAP
                ),
                'warning'
            );
            $this->setRedirect($back);
            return;
        }

        // Anthropic Messages calls take real time on a meaningful
        // override list. Without this PHP would 504 mid-call.
        @set_time_limit(180);

        $scanModel    = self::resolveModel($params->get('scan_model', 'claude-opus-4-7'));
        $maxOverrides = (int) $params->get('scan_max_overrides', ScanRunnerHelper::DEFAULT_MAX_OVERRIDES);

        // Log the attempt BEFORE executing so the cap counts in-flight
        // scans too — otherwise a user could fire two requests in
        // quick succession and both would pass the cap.
        ActionLogHelper::log(
            ActionLogHelper::ACTION_AUTO_SCAN_RUN,
            ['model' => $scanModel, 'cap' => $maxOverrides]
        );

        try {
            $result   = ScanRunnerHelper::run($apiKey, $scanModel, $maxOverrides);
            $markdown = $result['markdown'];

            $summary = sprintf(
                'Automated scan: %d override(s) reviewed%s.',
                $result['count'],
                $result['truncated'] ? sprintf(' (first %d only)', $result['cap']) : ''
            );

            $name = gmdate('Y-m-d-His');
            $id   = SessionsHelper::create(
                $name,
                $summary,
                $markdown,
                SessionsHelper::SOURCE_AUTO,
                null,
                $result['messages']
            );

            $msg = Text::sprintf(
                'COM_CSTEMPLATEINTEGRITY_RUN_SCAN_SUCCESS',
                $result['count'],
                $id
            );
            $app->enqueueMessage($msg, 'success');

            if ($result['truncated']) {
                $app->enqueueMessage(
                    Text::sprintf(
                        'COM_CSTEMPLATEINTEGRITY_RUN_SCAN_TRUNCATED',
                        $result['cap']
                    ),
                    'warning'
                );
            }

            $this->setRedirect(Route::_('index.php?option=com_cstemplateintegrity&view=session&id=' . (int) $id . '&from=dashboard', false));
        } catch (Throwable $e) {
            $app->enqueueMessage(
                Text::sprintf('COM_CSTEMPLATEINTEGRITY_RUN_SCAN_ERROR', $e->getMessage()),
                'error'
            );
            $this->setRedirect($back);
        }
    }

    /**
     * Make a tiny Anthropic call to verify the saved key is valid.
     * Posted via fetch() from the diagnostics modal; returns a JSON
     * payload the modal renders inline. No state change.
     */
    public function testApiConnection(): void
    {
        // GET-form CSRF: the button submits via fetch with the token
        // on the URL query string, not in a form body. Same pattern as
        // acknowledgeDisclaimer.
        $this->checkToken('get');
        PermissionHelper::requireWrite();

        /** @var CMSApplication $app */
        $app    = $this->app;
        $params = ComponentHelper::getParams('com_cstemplateintegrity');
        $apiKey = (string) $params->get('anthropic_api_key', '');

        $reply = function (array $payload, int $status = 200) use ($app): void {
            $app->setHeader('status', (string) $status, true);
            $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
            $app->sendHeaders();
            echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $app->close();
        };

        if (trim($apiKey) === '') {
            $reply(['ok' => false, 'error' => 'No API key saved. Add one in Options first.'], 200);
            return;
        }

        try {
            // Always use Haiku for the test — cheapest possible
            // round-trip just to verify the key authenticates and
            // the network path works. No need to burn Sonnet/Opus
            // tokens on a verify-only call.
            $client = new AnthropicClient($apiKey, 'claude-haiku-4-5-20251001');
            $start  = microtime(true);

            // Smallest possible test prompt — explicit max_tokens cap
            // so the test costs ~no tokens regardless of model.
            $reply_text = $client->complete(
                'Reply only with the literal word PONG.',
                [['role' => 'user', 'content' => 'ping']],
                16,
                30
            );

            $latency = (int) round((microtime(true) - $start) * 1000);

            $reply([
                'ok'           => true,
                'status'       => 200,
                'latency_ms'   => $latency,
                'sample_reply' => mb_substr(trim($reply_text), 0, 80),
                'fingerprint'  => $client->keyFingerprint(),
            ]);
        } catch (Throwable $e) {
            $reply([
                'ok'    => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Persist a "don't show again" click on the first-run disclaimer
     * for the current logged-in user. Posted via fetch() from the
     * modal's inline JS — no redirect, returns a tiny JSON payload.
     */
    public function acknowledgeDisclaimer(): void
    {
        // The disclaimer modal's inline JS sends the session token only
        // on the URL query string, not in a form body, so the default
        // checkToken() (which looks at $_POST) silently fails and the
        // ACK row never lands. Use the GET-form check instead — same
        // pattern the download endpoints use.
        $this->checkToken('get');
        // Gate at view for consistency with every other controller
        // method. The modal only renders for users who can see the
        // component anyway, so this isn't a new restriction — it
        // closes the L-1 finding from the v2.0 security review by
        // making the rule explicit instead of implicit.
        PermissionHelper::requireView();

        /** @var CMSApplication $app */
        $app  = $this->app;
        $user = $app->getIdentity();
        $uid  = $user ? (int) $user->id : 0;

        if ($uid > 0) {
            DisclaimerHelper::acknowledge($uid);
        }

        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->sendHeaders();
        echo json_encode(['acknowledged' => $uid > 0]);
        $app->close();
    }
}
