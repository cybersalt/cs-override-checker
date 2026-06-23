<?php

/**
 * @package     Cstemplateintegrity
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Tiny HTTP wrapper around Anthropic's Messages API.
 *
 * Just enough surface area to let ScanRunnerHelper send a single
 * non-streaming request and get the assistant's reply back as a
 * markdown string. No tool-use loop, no streaming, no caching —
 * those land in later iterations once the synchronous path is
 * stable.
 *
 * Auth header is `x-api-key`, NOT `Authorization: Bearer …` (Anthropic
 * is the rare API that doesn't use Bearer-style; mismatched headers
 * silently 401).
 *
 * Reference: https://docs.anthropic.com/en/api/messages
 */

declare(strict_types=1);

namespace Cybersalt\Component\Cstemplateintegrity\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Http\HttpFactory;

final class AnthropicClient
{
    public const API_VERSION = '2023-06-01';
    public const ENDPOINT    = 'https://api.anthropic.com/v1/messages';

    /** @var non-empty-string */
    private string $apiKey;

    private string $model;

    private int $keyRawLength = 0;

    public function __construct(string $apiKey, string $model = 'claude-opus-4-7')
    {
        // Track the raw length (after the caller's trim, before our
        // own whitespace strip) so the fingerprint can show both —
        // tells us whether truncation happened at save time vs in
        // copy-paste with embedded whitespace.
        $this->keyRawLength = strlen($apiKey);

        // Strip ALL whitespace, not just leading/trailing — copy-paste
        // from a website console occasionally drops a soft hyphen or
        // a stray newline mid-key, and Anthropic 401s on any whitespace.
        $apiKey = (string) preg_replace('/\s+/', '', $apiKey);
        if ($apiKey === '') {
            throw new \InvalidArgumentException('Anthropic API key is empty.');
        }
        $this->apiKey = $apiKey;
        $this->model  = $model;
    }

    /**
     * Non-secret fingerprint of the key for diagnostics. Returns the
     * length plus a 12-char SHA-256 hash prefix — enough to verify
     * "yes I'm looking at the same key as before" without leaking any
     * actual chars of the key.
     *
     * Earlier versions returned `starts="<first 8>", ends="<last 4>"`,
     * which narrowed brute-force search space if combined with any
     * other channel that leaked middle bytes. SHA-256 reveals nothing
     * about the input, so the fingerprint is safe to display to any
     * tier that can reach the diagnostics modal.
     *
     * Anthropic API keys are typically 108 characters (sk-ant-api03-
     * prefix plus 96 alphanumerics plus a short trailer). A length
     * meaningfully different from 108 strongly suggests truncation
     * during save.
     */
    public function keyFingerprint(): string
    {
        $len = strlen($this->apiKey);
        if ($len <= 12) {
            return sprintf('len=%d (too short to fingerprint)', $len);
        }

        $rawSuffix = $this->keyRawLength !== $len
            ? sprintf(' (raw=%d before whitespace strip)', $this->keyRawLength)
            : '';

        $expectationSuffix = '';
        if ($len < 100) {
            $expectationSuffix = ' — Anthropic keys are usually around 108 chars; this one looks truncated';
        } elseif ($len > 130) {
            $expectationSuffix = ' — Anthropic keys are usually around 108 chars; this one looks too long';
        }

        return sprintf(
            'len=%d%s, fingerprint=sha256:%s%s',
            $len,
            $rawSuffix,
            substr(hash('sha256', $this->apiKey), 0, 12),
            $expectationSuffix
        );
    }

    /**
     * Send a single Messages-API request and return the concatenated
     * text from the assistant's reply.
     *
     * @param  string                                          $system        System prompt.
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @param  int                                             $maxTokens     Hard cap on response length.
     * @param  int                                             $timeoutSecs   HTTP timeout.
     */
    public function complete(string $system, array $messages, int $maxTokens = 8192, int $timeoutSecs = 120): string
    {
        $payload = [
            'model'      => $this->model,
            'max_tokens' => $maxTokens,
            'system'     => $system,
            'messages'   => $messages,
        ];

        $http = HttpFactory::getHttp();
        $body = $headers = $status = null;

        // Retry-once-with-backoff on 429 (rate limit). Anthropic's
        // lower account tiers cap input tokens per minute; if a recent
        // call ate the budget, this single sleep+retry usually bridges
        // back into the next minute window. Cap at 60s to keep the
        // page responsive.
        for ($attempt = 0; $attempt <= 1; $attempt++) {
            $response = $http->post(
                self::ENDPOINT,
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                [
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'content-type'      => 'application/json',
                ],
                $timeoutSecs
            );

            $status  = (int) $response->code;
            $body    = (string) $response->body;
            $headers = is_array($response->headers ?? null) ? $response->headers : [];

            if ($status !== 429 || $attempt > 0) {
                break;
            }

            // Anthropic returns retry-after in seconds. Cap at 60s
            // so we don't sit on a single page for minutes.
            $retryAfter = isset($headers['retry-after'])
                ? (int) (is_array($headers['retry-after']) ? $headers['retry-after'][0] : $headers['retry-after'])
                : 30;
            $retryAfter = max(5, min(60, $retryAfter));
            @set_time_limit($timeoutSecs + $retryAfter + 30);
            sleep($retryAfter);
        }

        if ($status < 200 || $status >= 300) {
            // Try to surface Anthropic's structured error message; fall back to the raw body.
            $detail = $body;
            $decoded = json_decode($body, true);
            if (is_array($decoded) && isset($decoded['error']['message'])) {
                $detail = $decoded['error']['message'];
            }
            // For auth-shaped failures, append a non-secret key
            // fingerprint so the user can verify the saved key matches
            // what's in their Anthropic console without us echoing the
            // secret. Common causes: copy-paste truncation, key for a
            // different account, key revoked, key for a non-Messages product.
            $hint = '';
            if ($status === 401 || $status === 403) {
                $hint = sprintf(
                    ' — Diagnostics: %s. Compare against your Anthropic console; if they do not match, re-paste the key in Options.',
                    $this->keyFingerprint()
                );
            } elseif ($status === 429) {
                // Auto-retry already happened once; if we are still
                // here the per-minute budget is fully consumed.
                $hint = ' — The extension waited and retried once already, so the per-minute budget is fully consumed. Wait 1–2 minutes before trying again. If this happens often, lower Overrides per scan in Options, or upgrade your Anthropic tier at https://console.anthropic.com/settings/limits';
            }
            throw new \RuntimeException(
                sprintf('Anthropic API returned HTTP %d: %s%s', $status, mb_substr($detail, 0, 800), $hint)
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Anthropic API returned a non-JSON body.');
        }

        // Messages API returns { content: [{type: "text", text: "..."}, ...] }.
        // Concatenate every text block; ignore other types (tool_use etc.) for now.
        $text = '';
        if (isset($decoded['content']) && is_array($decoded['content'])) {
            foreach ($decoded['content'] as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text') {
                    $text .= (string) ($block['text'] ?? '');
                }
            }
        }

        if ($text === '') {
            throw new \RuntimeException('Anthropic API returned an empty text response.');
        }

        return $text;
    }
}
