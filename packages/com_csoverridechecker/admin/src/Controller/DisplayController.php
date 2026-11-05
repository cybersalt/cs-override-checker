<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Administrator\Controller;

defined('_JEXEC') or die;

use Cybersalt\Component\Csoverridechecker\Administrator\Helper\ActionLogHelper;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\AiClientFactory;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\AnthropicClient;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\DisclaimerHelper;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\PermissionHelper;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\RescanHelper;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\ScanRunnerHelper;
use Cybersalt\Component\Csoverridechecker\Administrator\Helper\SessionsHelper;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;
use RuntimeException;
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

    /**
     * Joomla API token constants, mirrored from core:
     *   plugins/user/token/src/Extension/Token.php
     *   plugins/api-authentication/token/src/Extension/Token.php
     * See fetchApiToken() for the full derivation notes.
     */
    /** Core: User\Token::$profileKeyPrefix */
    private const TOKEN_PROFILE_PREFIX = 'joomlatoken';

    /** Core: User\Token::$tokenLength */
    private const TOKEN_SEED_BYTES = 32;

    /** Core: ApiAuthentication\Token::$allowedAlgos */
    private const TOKEN_ALLOWED_ALGOS = ['sha256', 'sha512'];

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
                    Text::_('COM_CSOVERRIDECHECKER_RESCAN_NO_VALID_TEMPLATES'),
                    'warning'
                );
                $this->setRedirect(Route::_('index.php?option=com_csoverridechecker&view=dashboard', false));
                return;
            }
        }

        try {
            $stats = RescanHelper::rebuildOverrideTracker($filter);
            $app->enqueueMessage(
                Text::sprintf(
                    'COM_CSOVERRIDECHECKER_RESCAN_SUCCESS',
                    $stats['inserted'],
                    $stats['scanned'],
                    $stats['templates']
                ),
                'success'
            );
        } catch (Throwable $e) {
            $app->enqueueMessage(
                Text::sprintf('COM_CSOVERRIDECHECKER_RESCAN_ERROR', $e->getMessage()),
                'error'
            );
        }

        $this->setRedirect(Route::_('index.php?option=com_csoverridechecker&view=dashboard', false));
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
            'claude-sonnet-5',
            'claude-opus-4-8',
            // Legacy ids, still accepted so a setting saved by an
            // earlier version keeps working instead of silently
            // falling back to the default.
            'claude-sonnet-4-6',
            'claude-opus-4-7',
        ];
        return in_array($candidate, $allowed, true) ? $candidate : 'claude-opus-4-8';
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
        $back  = Route::_('index.php?option=com_csoverridechecker&view=dashboard', false);

        $params = ComponentHelper::getParams('com_csoverridechecker');
        // Gate on the key of the provider selected in Options, not on
        // the Anthropic key: up to 2.6.0 this read anthropic_api_key, so
        // an OpenAI- or Gemini-only site was refused here even though the
        // Dashboard offered Method 2.
        $provider = AiClientFactory::currentProvider();
        $apiKey   = AiClientFactory::apiKeyFor($provider);

        if ($apiKey === '') {
            $app->enqueueMessage(
                Text::sprintf('COM_CSOVERRIDECHECKER_RUN_SCAN_NO_KEY', AiClientFactory::labelFor($provider)),
                'warning'
            );
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
                    'COM_CSOVERRIDECHECKER_RUN_SCAN_RATE_LIMITED',
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

        // resolveModel() allow-lists Claude ids, so only Anthropic gets an
        // explicit override. For OpenAI / Gemini pass '' and let the
        // factory read that provider's own scan model option; passing the
        // Claude id there would ask OpenAI or Gemini for claude-opus-4-8.
        $scanModel = $provider === AiClientFactory::PROVIDER_ANTHROPIC
            ? self::resolveModel($params->get('scan_model', 'claude-opus-4-8'))
            : '';
        $maxOverrides = (int) $params->get('scan_max_overrides', ScanRunnerHelper::DEFAULT_MAX_OVERRIDES);

        // Log the attempt BEFORE executing so the cap counts in-flight
        // scans too — otherwise a user could fire two requests in
        // quick succession and both would pass the cap.
        ActionLogHelper::log(
            ActionLogHelper::ACTION_AUTO_SCAN_RUN,
            ['provider' => $provider, 'model' => $scanModel !== '' ? $scanModel : 'provider default', 'cap' => $maxOverrides]
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
                'COM_CSOVERRIDECHECKER_RUN_SCAN_SUCCESS',
                $result['count'],
                $id
            );
            $app->enqueueMessage($msg, 'success');

            if ($result['truncated']) {
                $app->enqueueMessage(
                    Text::sprintf(
                        'COM_CSOVERRIDECHECKER_RUN_SCAN_TRUNCATED',
                        $result['cap']
                    ),
                    'warning'
                );
            }

            $this->setRedirect(Route::_('index.php?option=com_csoverridechecker&view=session&id=' . (int) $id . '&from=dashboard', false));
        } catch (Throwable $e) {
            $app->enqueueMessage(
                Text::sprintf('COM_CSOVERRIDECHECKER_RUN_SCAN_ERROR', $e->getMessage()),
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
        // Same provider-aware key check as runScan(); this used to read
        // anthropic_api_key even though the test itself uses the factory.
        $apiKey = AiClientFactory::apiKeyFor();

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
            // Cheapest model the selected provider offers - this call
            // only proves the key authenticates and the network path
            // works, so it should cost as close to nothing as possible.
            $client = AiClientFactory::make(null, AiClientFactory::cheapestModelFor());
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

    // ------------------------------------------------------------------
    // Joomla Web Services API token retrieval
    //
    // Joomla never stores the usable API token. #__user_profiles holds a
    // per-user SEED under profile_key 'joomlatoken.token' (base64 of 32
    // random bytes) and the token the user pastes into a client is
    // derived on the fly:
    //
    //     base64( "<algo>:<userId>:" . hash_hmac(<algo>, base64_decode(seed), $siteSecret) )
    //
    // That derivation, the seed length, the profile-key names, the
    // ordering values and the allowed-user-group rule below are all
    // mirrored from Joomla core:
    //   plugins/user/token/src/Extension/Token.php
    //   plugins/api-authentication/token/src/Extension/Token.php
    // ------------------------------------------------------------------

    /**
     * Return the CURRENT logged-in user's Joomla Web Services API token,
     * minting the underlying seed on first use.
     *
     * Deliberately takes NO user id from the request. A Joomla API token
     * authenticates as its owner across the whole Web Services API, so
     * "fetch a token" must never be able to become "fetch somebody
     * else's token" — not even for a Super User. The only identity this
     * method will ever act on is $app->getIdentity().
     *
     * CSRF: 'get' form, matching testApiConnection() and
     * acknowledgeDisclaimer() — the UI posts via fetch() with the
     * session token on the query string.
     *
     * ACL: write tier. This hands back a credential, so requireView()
     * would be far too loose.
     */
    public function fetchApiToken(): void
    {
        $this->checkToken('get');

        if (!$this->requireWriteJson()) {
            return;
        }

        /** @var CMSApplication $app */
        $app  = $this->app;
        $user = $app->getIdentity();

        if (!$user instanceof User || $user->guest || (int) $user->id <= 0) {
            $this->sendJson([
                'success' => false,
                'error'   => Text::_('COM_CSOVERRIDECHECKER_FETCH_TOKEN_NO_USER'),
            ], 403);
            return;
        }

        $userId = (int) $user->id;

        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $state = self::loadTokenPluginState($db);

            // plg_user_token disabled → refuse and hand the UI a flag so
            // it can offer the one-click enable. Never auto-enable here:
            // turning on a plugin is a site-wide change and needs an
            // explicit second click from the admin.
            if (!$state['user_token_enabled']) {
                $this->sendJson([
                    'success'         => false,
                    'plugin_disabled' => true,
                    'plugin'          => 'plg_user_token',
                    'error'           => Text::_('COM_CSOVERRIDECHECKER_FETCH_TOKEN_PLUGIN_DISABLED'),
                ]);
                return;
            }

            // plg_user_token only hands tokens to members of its
            // allowedUserGroups list (core default: Super Users). If we
            // wrote seed rows for anyone else we'd be handing back a
            // token that plg_api-authentication_token will refuse.
            if (!self::isInAllowedTokenGroup($user, $state['user_token_params'])) {
                $this->sendJson([
                    'success'           => false,
                    'group_not_allowed' => true,
                    'error'             => Text::_('COM_CSOVERRIDECHECKER_FETCH_TOKEN_GROUP_NOT_ALLOWED'),
                ]);
                return;
            }

            $siteSecret = (string) $app->get('secret', '');

            if ($siteSecret === '') {
                $this->sendJson([
                    'success' => false,
                    'error'   => Text::_('COM_CSOVERRIDECHECKER_FETCH_TOKEN_NO_SECRET'),
                ]);
                return;
            }

            $seed    = self::loadTokenSeed($db, $userId);
            $created = false;

            if ($seed === null || $seed === '') {
                $seed    = self::createTokenSeedRows($db, $userId);
                $created = true;
            } else {
                // A seed with the per-user switch off produces a token
                // that silently fails to authenticate. The user just
                // asked for a working token for their own account, so
                // flip their own switch on — same thing core does when
                // they save their own user profile.
                self::enableTokenForUser($db, $userId);
            }

            $algo  = self::tokenAlgorithm();
            $token = base64_encode(
                $algo . ':' . $userId . ':' . hash_hmac($algo, base64_decode($seed), $siteSecret)
            );

            // Audit trail records THAT a token was fetched and by whom.
            // The token itself is never logged — the JSON body below is
            // the only place it ever appears.
            ActionLogHelper::log('api_token_fetched', ['created' => $created]);

            $payload = [
                'success' => true,
                'token'   => $token,
                'created' => $created,
            ];

            // The seed lives in plg_user_token but the actual request
            // authentication is done by plg_api-authentication_token.
            // With that one off the token is well-formed but inert, so
            // say so rather than let the user chase a silent 401.
            if (!$state['api_auth_enabled']) {
                $payload['api_auth_disabled'] = true;
                $payload['warning']           = Text::_('COM_CSOVERRIDECHECKER_FETCH_TOKEN_API_AUTH_DISABLED');
            }

            $this->sendJson($payload);
        } catch (Throwable $e) {
            $this->sendJson([
                'success' => false,
                'error'   => Text::sprintf('COM_CSOVERRIDECHECKER_FETCH_TOKEN_ERROR', $e->getMessage()),
            ]);
        }
    }

    /**
     * Enable plg_user_token, and nothing else.
     *
     * Deliberately hard-coded to type='plugin' / folder='user' /
     * element='token'. No extension id, folder or element is ever read
     * from the request — a "flip a switch" endpoint that took its target
     * from user input would be a remote plugin-enable primitive.
     *
     * Same CSRF ('get') + write-tier ACL as fetchApiToken().
     */
    public function enableTokenPlugin(): void
    {
        $this->checkToken('get');

        if (!$this->requireWriteJson()) {
            return;
        }

        if (!$this->requirePluginAdminJson()) {
            return;
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $state = self::loadTokenPluginState($db);

            if ($state['user_token_id'] === 0) {
                $this->sendJson([
                    'success' => false,
                    'error'   => Text::_('COM_CSOVERRIDECHECKER_ENABLE_TOKEN_PLUGIN_NOT_FOUND'),
                ]);
                return;
            }

            if ($state['user_token_enabled']) {
                $this->sendJson([
                    'success' => true,
                    'enabled' => true,
                    'already' => true,
                    'message' => Text::_('COM_CSOVERRIDECHECKER_ENABLE_TOKEN_PLUGIN_ALREADY'),
                ]);
                return;
            }

            $pluginId = $state['user_token_id'];

            // Single-row update pinned to the extension id we just read
            // for folder='user' AND element='token'. The extra type /
            // folder / element predicates are belt-and-braces: even if
            // the id lookup were somehow poisoned, this statement still
            // cannot touch any other extension.
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('extension_id') . ' = :pluginId')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('user'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('token'))
                ->bind(':pluginId', $pluginId, ParameterType::INTEGER);

            $db->setQuery($query)->execute();

            // Joomla caches the published-plugin list in the _system
            // group; without a clean the plugin can stay invisible for
            // the rest of the cache lifetime. Best effort only.
            try {
                Factory::getContainer()
                    ->get(CacheControllerFactoryInterface::class)
                    ->createCacheController('callback', ['defaultgroup' => '_system'])
                    ->clean();
            } catch (Throwable $e) {
                // Non-fatal — the plugin row is already flipped.
            }

            ActionLogHelper::log('token_plugin_enabled', ['plugin' => 'plg_user_token']);

            $this->sendJson([
                'success' => true,
                'enabled' => true,
                'already' => false,
                'message' => Text::_('COM_CSOVERRIDECHECKER_ENABLE_TOKEN_PLUGIN_SUCCESS'),
            ]);
        } catch (Throwable $e) {
            $this->sendJson([
                'success' => false,
                'error'   => Text::sprintf('COM_CSOVERRIDECHECKER_ENABLE_TOKEN_PLUGIN_ERROR', $e->getMessage()),
            ]);
        }
    }

    /**
     * Enable plg_api-authentication_token, and nothing else.
     *
     * The sibling task above turns on the plugin that MINTS a token.
     * This one turns on the plugin that ACCEPTS it: without
     * plg_api-authentication_token published, a perfectly well-formed
     * token is inert and every inbound API request 401s with no
     * explanation. fetchApiToken() already reports that condition as
     * api_auth_disabled; this is the one-click fix for it.
     *
     * Deliberately hard-coded to type='plugin' /
     * folder='api-authentication' / element='token'. No extension id,
     * folder or element is ever read from the request — a "flip a
     * switch" endpoint that took its target from user input would be a
     * remote plugin-enable primitive.
     *
     * Same CSRF ('get') + write-tier ACL as enableTokenPlugin().
     */
    public function enableApiAuthPlugin(): void
    {
        $this->checkToken('get');

        if (!$this->requireWriteJson()) {
            return;
        }

        if (!$this->requirePluginAdminJson()) {
            return;
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $state = self::loadTokenPluginState($db);

            if ($state['api_auth_id'] === 0) {
                $this->sendJson([
                    'success' => false,
                    'error'   => Text::_('COM_CSOVERRIDECHECKER_ENABLE_API_AUTH_PLUGIN_NOT_FOUND'),
                ]);
                return;
            }

            if ($state['api_auth_enabled']) {
                $this->sendJson([
                    'success' => true,
                    'enabled' => true,
                    'already' => true,
                    'message' => Text::_('COM_CSOVERRIDECHECKER_ENABLE_API_AUTH_PLUGIN_ALREADY'),
                ]);
                return;
            }

            $pluginId = $state['api_auth_id'];

            // Single-row update pinned to the extension id we just read
            // for folder='api-authentication' AND element='token'. The
            // extra type / folder / element predicates are belt-and-
            // braces: even if the id lookup were somehow poisoned, this
            // statement still cannot touch any other extension.
            $query = $db->getQuery(true)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('extension_id') . ' = :pluginId')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('api-authentication'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('token'))
                ->bind(':pluginId', $pluginId, ParameterType::INTEGER);

            $db->setQuery($query)->execute();

            // Joomla caches the published-plugin list in the _system
            // group; without a clean the plugin can stay invisible for
            // the rest of the cache lifetime. Best effort only.
            try {
                Factory::getContainer()
                    ->get(CacheControllerFactoryInterface::class)
                    ->createCacheController('callback', ['defaultgroup' => '_system'])
                    ->clean();
            } catch (Throwable $e) {
                // Non-fatal — the plugin row is already flipped.
            }

            ActionLogHelper::log(
                'api_auth_plugin_enabled',
                ['plugin' => 'plg_api-authentication_token']
            );

            $this->sendJson([
                'success' => true,
                'enabled' => true,
                'already' => false,
                'message' => Text::_('COM_CSOVERRIDECHECKER_ENABLE_API_AUTH_PLUGIN_SUCCESS'),
            ]);
        } catch (Throwable $e) {
            $this->sendJson([
                'success' => false,
                'error'   => Text::sprintf('COM_CSOVERRIDECHECKER_ENABLE_API_AUTH_PLUGIN_ERROR', $e->getMessage()),
            ]);
        }
    }

    /**
     * Write-tier gate for the JSON endpoints above.
     *
     * PermissionHelper::requireWrite() throws, which on a fetch() call
     * would produce Joomla's HTML error page and leave the caller
     * parsing garbage. Convert the refusal into a JSON 401/403 instead.
     * The authorisation decision itself is still PermissionHelper's —
     * this only changes how "no" is rendered.
     *
     * Returns false (after emitting and closing) when refused.
     */

    /**
     * Gate for tasks that change site-wide extension state.
     *
     * The component's own write tier is deliberately NOT sufficient here.
     * access.xml lets an admin delegate csoverridechecker.write to a narrow
     * "override reviewer" group; enabling a plugin is a global action that
     * belongs to Joomla's own com_plugins permission, so we require that too.
     * Returns false and emits JSON when refused.
     */
    private function requirePluginAdminJson(): bool
    {
        $user = $this->app->getIdentity();

        if ($user === null
            || (!$user->authorise('core.admin')
                && !$user->authorise('core.manage', 'com_plugins'))) {
            $this->sendJson([
                'success' => false,
                'error'   => Text::_('COM_CSOVERRIDECHECKER_ENABLE_PLUGIN_NEEDS_ADMIN'),
            ], 403);

            return false;
        }

        return true;
    }

    private function requireWriteJson(): bool
    {
        try {
            PermissionHelper::requireWrite();
            return true;
        } catch (RuntimeException $e) {
            $code = $e->getCode() === 401 ? 401 : 403;
            $this->sendJson([
                'success' => false,
                'error'   => Text::_('COM_CSOVERRIDECHECKER_FETCH_TOKEN_FORBIDDEN'),
            ], $code);
            return false;
        }
    }

    /**
     * Emit a JSON payload and terminate. Same shape as the reply()
     * closure in testApiConnection(), lifted to a method because two
     * tasks now need it.
     *
     * @param  array<string,mixed>  $payload
     */
    private function sendJson(array $payload, int $status = 200): void
    {
        /** @var CMSApplication $app */
        $app = $this->app;

        $app->setHeader('status', (string) $status, true);
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        // The success payload carries a credential. Belt-and-braces
        // against sniffing / intermediary caching even though the admin
        // application already sends no-store.
        $app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $app->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true);
        $app->sendHeaders();

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $app->close();
    }

    /**
     * Published state + params for the two plugins that make an API
     * token work: plg_user_token (mints and stores the seed) and
     * plg_api-authentication_token (verifies it on inbound requests).
     *
     * @return array{user_token_id:int,user_token_enabled:bool,user_token_params:Registry,api_auth_id:int,api_auth_enabled:bool}
     */
    private static function loadTokenPluginState(DatabaseInterface $db): array
    {
        $out = [
            'user_token_id'      => 0,
            'user_token_enabled' => false,
            'user_token_params'  => new Registry(),
            'api_auth_id'        => 0,
            'api_auth_enabled'   => false,
        ];

        $query = $db->getQuery(true)
            ->select($db->quoteName(['extension_id', 'folder', 'enabled', 'params']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('token'))
            ->whereIn($db->quoteName('folder'), ['user', 'api-authentication'], ParameterType::STRING);

        $rows = $db->setQuery($query)->loadObjectList() ?: [];

        foreach ($rows as $row) {
            if ($row->folder === 'user') {
                $rawParams = trim((string) ($row->params ?? ''));

                $out['user_token_id']      = (int) $row->extension_id;
                $out['user_token_enabled'] = (int) $row->enabled === 1;
                $out['user_token_params']  = new Registry($rawParams === '' ? '{}' : $rawParams);
            } elseif ($row->folder === 'api-authentication') {
                $out['api_auth_id']      = (int) $row->extension_id;
                $out['api_auth_enabled'] = (int) $row->enabled === 1;
            }
        }

        return $out;
    }

    /**
     * Mirror of core's Token::isInAllowedUserGroup(). An empty
     * allowedUserGroups param means "everybody"; the shipped default is
     * [8] (Super Users).
     */
    private static function isInAllowedTokenGroup(User $user, Registry $params): bool
    {
        $allowed = $params->get('allowedUserGroups', [8]);

        if (empty($allowed)) {
            return true;
        }

        if (!is_array($allowed)) {
            $allowed = [$allowed];
        }

        $allowed = array_map('intval', $allowed);
        $groups  = array_map('intval', (array) $user->getAuthorisedGroups());

        return !empty(array_intersect($groups, $allowed));
    }

    /**
     * The HMAC algorithm plg_user_token is configured to display tokens
     * with. Core reads it out of the plugin's form XML with a regex
     * rather than loading the form (see Token::getAlgorithmFromFormFile);
     * we do the same, then hold it to the algorithms
     * plg_api-authentication_token will actually accept.
     */
    private static function tokenAlgorithm(): string
    {
        $algo     = 'sha256';
        $file     = JPATH_PLUGINS . '/user/token/forms/token.xml';
        $contents = is_readable($file) ? @file_get_contents($file) : false;

        if ($contents !== false && preg_match('/\s*algo=\s*"\s*([a-z0-9]+)\s*"/i', $contents, $m) === 1) {
            $candidate = strtolower($m[1]);

            if (in_array($candidate, self::TOKEN_ALLOWED_ALGOS, true)) {
                $algo = $candidate;
            }
        }

        return $algo;
    }

    /**
     * The current user's raw token seed, or null when they have none.
     */
    private static function loadTokenSeed(DatabaseInterface $db, int $userId): ?string
    {
        $profileKey = self::TOKEN_PROFILE_PREFIX . '.token';

        $query = $db->getQuery(true)
            ->select($db->quoteName('profile_value'))
            ->from($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('user_id') . ' = :userId')
            ->where($db->quoteName('profile_key') . ' = :profileKey')
            ->bind(':userId', $userId, ParameterType::INTEGER)
            ->bind(':profileKey', $profileKey, ParameterType::STRING);

        $value = $db->setQuery($query)->loadResult();

        return $value === null ? null : (string) $value;
    }

    /**
     * Create the seed rows exactly the way core's plg_user_token does on
     * a first save: wipe any stray 'joomlatoken.%' rows for this user,
     * then insert 'joomlatoken.token' (base64 of 32 random bytes,
     * ordering 1) followed by 'joomlatoken.enabled' = '1' (ordering 2).
     *
     * profile_value is the raw base64 string — NOT json_encode()d.
     * plg_user_profile JSON-encodes its values; plg_user_token does not,
     * and both the user plugin and the api-authentication plugin read
     * this column back verbatim.
     *
     * @return string the new seed
     */
    private static function createTokenSeedRows(DatabaseInterface $db, int $userId): string
    {
        $seed       = base64_encode(random_bytes(self::TOKEN_SEED_BYTES));
        $likeKey    = self::TOKEN_PROFILE_PREFIX . '.%';
        $tokenKey   = self::TOKEN_PROFILE_PREFIX . '.token';
        $enabledKey = self::TOKEN_PROFILE_PREFIX . '.enabled';

        $delete = $db->getQuery(true)
            ->delete($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('user_id') . ' = :userId')
            ->where($db->quoteName('profile_key') . ' LIKE :profileKey')
            ->bind(':userId', $userId, ParameterType::INTEGER)
            ->bind(':profileKey', $likeKey, ParameterType::STRING);

        $db->setQuery($delete)->execute();

        $insert = $db->getQuery(true)
            ->insert($db->quoteName('#__user_profiles'))
            ->columns($db->quoteName(['user_id', 'profile_key', 'profile_value', 'ordering']))
            ->values($userId . ', ' . $db->quote($tokenKey) . ', ' . $db->quote($seed) . ', 1')
            ->values($userId . ', ' . $db->quote($enabledKey) . ', ' . $db->quote('1') . ', 2');

        $db->setQuery($insert)->execute();

        return $seed;
    }

    /**
     * Force the current user's 'joomlatoken.enabled' row to '1'.
     * plg_api-authentication_token treats a missing or non-1 value as
     * "token switched off" and refuses the login.
     */
    private static function enableTokenForUser(DatabaseInterface $db, int $userId): void
    {
        $enabledKey = self::TOKEN_PROFILE_PREFIX . '.enabled';

        $exists = $db->getQuery(true)
            ->select($db->quoteName('profile_value'))
            ->from($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('user_id') . ' = :userId')
            ->where($db->quoteName('profile_key') . ' = :profileKey')
            ->bind(':userId', $userId, ParameterType::INTEGER)
            ->bind(':profileKey', $enabledKey, ParameterType::STRING);

        $current = $db->setQuery($exists)->loadResult();

        if ($current === null) {
            $insert = $db->getQuery(true)
                ->insert($db->quoteName('#__user_profiles'))
                ->columns($db->quoteName(['user_id', 'profile_key', 'profile_value', 'ordering']))
                ->values($userId . ', ' . $db->quote($enabledKey) . ', ' . $db->quote('1') . ', 2');

            $db->setQuery($insert)->execute();
            return;
        }

        if ((string) $current === '1') {
            return;
        }

        $update = $db->getQuery(true)
            ->update($db->quoteName('#__user_profiles'))
            ->set($db->quoteName('profile_value') . ' = ' . $db->quote('1'))
            ->where($db->quoteName('user_id') . ' = :userId')
            ->where($db->quoteName('profile_key') . ' = :profileKey')
            ->bind(':userId', $userId, ParameterType::INTEGER)
            ->bind(':profileKey', $enabledKey, ParameterType::STRING);

        $db->setQuery($update)->execute();
    }
}
