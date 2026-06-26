<?php

/**
 * @package     Csoverridechecker
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 *
 * Read/write helper for #__csoverridechecker_sessions. Used by both the
 * admin controllers and the Web Services API controller — keeps the
 * insert/list logic in one place so paste-in and POST-from-Claude
 * produce identical rows.
 */

declare(strict_types=1);

namespace Cybersalt\Component\Csoverridechecker\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

final class SessionsHelper
{
    public const SOURCE_PASTE       = 'paste';
    public const SOURCE_API         = 'api';
    public const SOURCE_CLAUDE_CODE = 'claude_code';
    public const SOURCE_AUTO        = 'auto';

    /**
     * @param  array<int, array{role: string, content: mixed}>|null  $messages
     *
     * @return int  Newly inserted session id
     */
    public static function create(
        ?string $name,
        ?string $summary,
        ?string $reportMarkdown,
        string $source = self::SOURCE_PASTE,
        ?int $createdBy = null,
        ?array $messages = null
    ): int {
        $db  = Factory::getContainer()->get(DatabaseInterface::class);
        $now = Factory::getDate()->toSql();

        $row = (object) [
            'name'            => $name !== null && $name !== '' ? mb_substr($name, 0, 64) : self::autoName(),
            'summary'         => $summary !== null ? mb_substr($summary, 0, 500) : '',
            'source'          => in_array($source, [self::SOURCE_PASTE, self::SOURCE_API, self::SOURCE_CLAUDE_CODE, self::SOURCE_AUTO], true)
                ? $source
                : self::SOURCE_PASTE,
            'report_markdown' => $reportMarkdown,
            'messages'        => $messages !== null
                ? json_encode($messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : null,
            'state'           => 1,
            'created_by'      => $createdBy ?? self::currentUserId(),
            'created_at'      => $now,
            'modified_at'     => $now,
        ];

        $db->insertObject('#__csoverridechecker_sessions', $row, 'id');

        $insertedId = (int) $row->id;

        ActionLogHelper::log(
            ActionLogHelper::ACTION_SESSION_CREATED,
            ['source' => $row->source, 'name' => $row->name],
            $insertedId
        );

        return $insertedId;
    }

    /**
     * @return list<\stdClass>
     */
    public static function listRecent(int $limit = 50, string $order = 'created_at', string $dir = 'DESC'): array
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $limit = max(1, min(200, $limit));

        // Whitelist sortable columns so the user-supplied order param
        // can't be smuggled through quoteName as something unexpected.
        // (quoteName escapes properly anyway, but defense in depth.)
        $allowedOrder = ['id', 'name', 'source', 'created_at'];
        if (!in_array($order, $allowedOrder, true)) {
            $order = 'created_at';
        }
        $dir = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';

        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'name', 'summary', 'source', 'state', 'created_by', 'created_at']))
            ->from($db->quoteName('#__csoverridechecker_sessions'))
            ->order($db->quoteName($order) . ' ' . $dir);

        $db->setQuery($query, 0, $limit);

        return $db->loadObjectList() ?: [];
    }

    public static function find(int $id): ?\stdClass
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__csoverridechecker_sessions'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $row = $db->setQuery($query)->loadObject();
        return $row ?: null;
    }

    /**
     * @param  list<int>  $ids
     */
    public static function delete(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($i) => $i > 0)));
        if (empty($ids)) {
            return 0;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__csoverridechecker_sessions'))
            ->whereIn($db->quoteName('id'), $ids);

        $db->setQuery($query)->execute();

        foreach ($ids as $id) {
            ActionLogHelper::log(ActionLogHelper::ACTION_SESSION_DELETED, ['id' => $id]);
        }

        return count($ids);
    }

    /**
     * Decode the JSON-encoded messages column into an array. Returns
     * an empty array if the session has no conversation history (older
     * rows from before the chat feature shipped).
     *
     * @return array<int, array{role: string, content: mixed}>
     */
    public static function getMessages(\stdClass $session): array
    {
        $raw = isset($session->messages) ? (string) $session->messages : '';
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Persist an updated messages array back to the session row.
     *
     * @param array<int, array{role: string, content: mixed}> $messages
     */
    public static function saveMessages(int $sessionId, array $messages): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $json = json_encode($messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $now  = Factory::getDate()->toSql();

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__csoverridechecker_sessions'))
            ->set($db->quoteName('messages')    . ' = :msgs')
            ->set($db->quoteName('modified_at') . ' = :now')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':msgs', $json)
            ->bind(':now',  $now)
            ->bind(':id',   $sessionId, ParameterType::INTEGER);

        $db->setQuery($query)->execute();
    }

    public static function autoName(?\DateTimeInterface $when = null): string
    {
        $dt = $when ?? new \DateTimeImmutable('now');

        // Include seconds in the name. Two sessions created in the same
        // minute (e.g. from concurrent API POSTs) would collide on the
        // older Y-m-d-Hi format and produce identical download filenames.
        return $dt->format('Y-m-d-His');
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
}
