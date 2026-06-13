<?php

/**
 * @package     Cstemplateintegrity
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * First-run "make sure you have a backup; AI can make mistakes; ask
 * for help if you're not sure" disclaimer. Per-user — each admin
 * acknowledges separately, so handing a site to a client still
 * surfaces the warning the first time they open the component.
 *
 * State is persisted as a row in #__cstemplateintegrity_actions with
 * action = ACTION_DISCLAIMER_ACKNOWLEDGED. Reusing the action log
 * avoids a schema migration AND gives us a free audit trail of who
 * acknowledged when. To reset for a single user (e.g. for testing),
 * delete that row.
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cstemplateintegrity\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

final class DisclaimerHelper
{
    /**
     * Has the current user already clicked "don't show again"?
     */
    public static function hasAcknowledged(int $userId): bool
    {
        if ($userId <= 0) {
            return true; // anonymous shouldn't see admin pages anyway; act as acknowledged
        }

        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName('#__cstemplateintegrity_actions'))
                ->where($db->quoteName('action') . ' = ' . $db->quote(ActionLogHelper::ACTION_DISCLAIMER_ACKNOWLEDGED))
                ->where($db->quoteName('user_id') . ' = :uid')
                ->bind(':uid', $userId, ParameterType::INTEGER);

            $db->setQuery($query, 0, 1);
            return (bool) $db->loadResult();
        } catch (\Throwable $e) {
            // Database hiccup shouldn't bury the user under a modal
            // every page load. Treat as acknowledged on error.
            return true;
        }
    }

    /**
     * Persist a "don't show again" click for the current user.
     */
    public static function acknowledge(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        // ActionLogHelper::log already pulls the current identity, but
        // we pass the id explicitly here in case it's ever called from
        // a context where the application identity hasn't been resolved.
        ActionLogHelper::log(
            ActionLogHelper::ACTION_DISCLAIMER_ACKNOWLEDGED,
            ['acknowledged_user_id' => $userId]
        );
    }

    /**
     * Render the modal HTML + inline JS for the current request, OR
     * return an empty string if the current user has already
     * dismissed it permanently. Templates call this once near the
     * top of every default.php; identical output across views so
     * navigating between dashboard/sessions/etc. is consistent.
     */
    public static function renderModalIfNeeded(): string
    {
        $userId = self::currentUserId();
        if ($userId <= 0 || self::hasAcknowledged($userId)) {
            return '';
        }

        $token = Session::getFormToken();
        $url   = Route::_(
            'index.php?option=com_cstemplateintegrity&task=display.acknowledgeDisclaimer&' . $token . '=1',
            false
        );
        $urlEsc = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $title    = self::esc(Text::_('COM_CSTEMPLATEINTEGRITY_DISCLAIMER_TITLE'));
        $body     = Text::_('COM_CSTEMPLATEINTEGRITY_DISCLAIMER_BODY');           // contains intentional HTML
        $checkbox = self::esc(Text::_('COM_CSTEMPLATEINTEGRITY_DISCLAIMER_DONT_SHOW_AGAIN'));
        $confirm  = self::esc(Text::_('COM_CSTEMPLATEINTEGRITY_DISCLAIMER_CONFIRM_BUTTON'));

        // Inline rendering rather than Bootstrap modal asset so we
        // don't have to wire `bootstrap.modal` into every view's
        // HtmlView::display(). Vanilla overlay + card; Bootstrap CSS
        // tokens for color so it tracks Atum light/dark.
        return <<<HTML
<div id="csti-disclaimer-overlay" class="csti-disclaimer-overlay" role="dialog" aria-modal="true" aria-labelledby="csti-disclaimer-title" data-acknowledge-url="{$urlEsc}">
    <div class="csti-disclaimer-card">
        <h3 id="csti-disclaimer-title" class="csti-disclaimer-title">{$title}</h3>
        <div class="csti-disclaimer-body">{$body}</div>
        <div class="csti-disclaimer-checkrow">
            <label>
                <input type="checkbox" id="csti-disclaimer-dismiss-check"> {$checkbox}
            </label>
        </div>
        <div class="csti-disclaimer-actions">
            <button type="button" class="btn btn-primary" id="csti-disclaimer-ok-btn">{$confirm}</button>
        </div>
    </div>
</div>

<style>
.csti-disclaimer-overlay {
    position: fixed; inset: 0; z-index: 1080;
    background: rgba(0, 0, 0, 0.62);
    display: flex; align-items: center; justify-content: center;
    padding: 1rem;
}
.csti-disclaimer-card {
    background: var(--bs-body-bg, #ffffff);
    color: var(--bs-body-color, #212529);
    /* Scope link color into the card so the host admin template's
       --bs-link-color can't leak in. Without this, an Atum light-mode
       --bs-link-color (dark navy) renders unreadably on the dark
       version of the card whenever the OS prefers dark mode. */
    --bs-link-color: #0d6efd;
    --bs-link-hover-color: #0a58ca;
    border: 1px solid var(--bs-border-color, rgba(0, 0, 0, 0.15));
    border-radius: 0.5rem;
    box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.4);
    max-width: 640px; width: 100%;
    padding: 1.75rem 2rem;
    line-height: 1.5;
}
.csti-disclaimer-title {
    margin: 0 0 1rem 0;
    font-size: 1.4rem;
    color: inherit;
}
/* Force every text node inside the card to inherit color from the
   card. Some admin templates style `label` and `p` with a brand /
   link color directly, which would otherwise produce dark-navy
   text on the dark card. !important wins against host rules. */
.csti-disclaimer-card p,
.csti-disclaimer-card label,
.csti-disclaimer-card li { color: inherit !important; }
.csti-disclaimer-body p     { margin-bottom: 0.85rem; }
.csti-disclaimer-body strong { color: inherit; }
.csti-disclaimer-body a {
    color: var(--bs-link-color) !important;
    text-decoration: underline;
}
.csti-disclaimer-body a:hover { color: var(--bs-link-hover-color) !important; }
.csti-disclaimer-checkrow {
    margin: 1.25rem 0 1rem 0;
    padding: 0.75rem 1rem;
    /* Slightly more shading + a more visible border so the row reads
       as an obviously-clickable control in light mode. Earlier values
       (4% bg, 8% border) were so subtle the row blended into the card
       and users missed that it was a checkbox. */
    background: rgba(0, 0, 0, 0.06);
    border: 1px solid rgba(0, 0, 0, 0.20);
    border-radius: 0.375rem;
    color: inherit;
}
.csti-disclaimer-checkrow label { cursor: pointer; user-select: none; }
.csti-disclaimer-checkrow input { margin-right: 0.5rem; }
.csti-disclaimer-actions { text-align: right; }

/* Dark-mode hooks. Atum sets EITHER data-bs-theme="dark" (modern
   Bootstrap 5.3 mechanism) OR data-color-scheme="dark" (older Joomla
   admin convention) on a parent — same dual-selector pattern the
   bundled highlight-theme.css uses for the same reason. Hard-coded
   fallback colors here in case --bs-* variables aren't propagating
   into this scope on the host site. */
[data-bs-theme="dark"]      .csti-disclaimer-card,
[data-color-scheme="dark"]  .csti-disclaimer-card {
    background: var(--bs-body-bg, #1a1d20) !important;
    color: var(--bs-body-color, #dee2e6) !important;
    border-color: var(--bs-border-color, rgba(255, 255, 255, 0.15));
    box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.6);
    /* Hardcoded high-contrast link blue for the dark variant.
       Don't fall back to the inherited --bs-link-color — the host's
       light-mode value bleeds through and is unreadable here. */
    --bs-link-color: #6ea8fe;
    --bs-link-hover-color: #9ec5fe;
}
[data-bs-theme="dark"]      .csti-disclaimer-checkrow,
[data-color-scheme="dark"]  .csti-disclaimer-checkrow {
    background: rgba(255, 255, 255, 0.08) !important;
    border-color: rgba(255, 255, 255, 0.25) !important;
}

/* We intentionally do NOT honour `prefers-color-scheme: dark` here.
   The popup should match the Joomla admin's theme, not the OS. A
   light-mode site rendered against a dark-mode OS used to flip the
   popup to dark while the page behind stayed light, leaving the
   "Don't show this again" label rendering in the host's light-mode
   link color against a dark popup background — unreadable. Following
   only the explicit `data-bs-theme="dark"` / `data-color-scheme="dark"`
   attribute set by the admin template keeps the popup visually in
   sync with whatever the user sees in the rest of the admin. */
</style>

<script>
(function () {
    var overlay = document.getElementById('csti-disclaimer-overlay');
    if (!overlay) return;

    var url     = overlay.dataset.acknowledgeUrl;
    var checkbox = document.getElementById('csti-disclaimer-dismiss-check');
    var okBtn    = document.getElementById('csti-disclaimer-ok-btn');

    // Prevent the rest of the page from scrolling under the modal.
    var prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    function close() {
        document.body.style.overflow = prevOverflow;
        overlay.parentNode && overlay.parentNode.removeChild(overlay);
    }

    okBtn.addEventListener('click', function () {
        if (checkbox.checked) {
            // Persist the ACK before closing so future page loads
            // don't re-show the modal. We log non-OK responses to the
            // console so a misconfigured CSRF / permission check
            // surfaces obviously rather than silently letting the
            // modal reappear.
            try {
                fetch(url, { method: 'POST', credentials: 'same-origin' })
                    .then(function (r) {
                        if (!r.ok) {
                            console.warn('csti disclaimer ACK failed:', r.status, r.statusText);
                        }
                    })
                    .catch(function (e) {
                        console.warn('csti disclaimer ACK error:', e);
                    });
            } catch (e) {
                console.warn('csti disclaimer ACK threw:', e);
            }
        }
        close();
    });
})();
</script>
HTML;
    }

    private static function currentUserId(): int
    {
        try {
            $app  = Factory::getApplication();
            $user = $app->getIdentity();
            return $user ? (int) $user->id : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
