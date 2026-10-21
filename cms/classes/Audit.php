<?php

/**
 * The CMS's audit log (`staff_audit`, 076): every change a staff member
 * makes — who, what, on which record, the values before and after, from
 * where. Written by every CMS action that changes something, read on the
 * Audit page: admins see everything, managers everything but staff
 * management, editors their own actions.
 */
final class Audit
{
    /** @var db */
    private $db;

    public function __construct(db $db)
    {
        $this->db = $db;
    }

    public function record(array $staff, string $action, string $targetType, string $targetId, ?array $before, ?array $after, string $ip): void
    {
        $this->db->db_Execute(
            'INSERT INTO `staff_audit` (`staff_id`, `staff_email`, `action`, `target_type`, `target_id`, `before`, `after`, `ip`, `created_at`)
             VALUES (' . (int) $staff['id'] . ', ' . $this->quote((string) $staff['email']) . ', ' . $this->quote($action) . ', '
            . $this->quote($targetType) . ', ' . $this->quote($targetId) . ', '
            . $this->quote($before !== null ? (string) json_encode($before, JSON_UNESCAPED_UNICODE) : null) . ', '
            . $this->quote($after !== null ? (string) json_encode($after, JSON_UNESCAPED_UNICODE) : null) . ', '
            . $this->quote($ip ?: null) . ', UTC_TIMESTAMP())'
        );
    }

    /**
     * Newest first, 50 at a time (`beforeId` pages back). `$scope`: `all`,
     * `operations` (everything but `staff.*`), or `own` (`$staffId`'s).
     *
     * @return array{entries: array, hasMore: bool}
     */
    /**
     * `$target` narrows to one thing changed — `user:156`, `complaint:3`,
     * `route:discover` — and, for a user, also the link verdicts and
     * complaints that named them.
     */
    public function page(string $scope, int $staffId, ?int $beforeId, ?string $action, ?string $target = null): array
    {
        $where = [];
        if ($target !== null && $target !== '') {
            [$type, $id] = array_pad(explode(':', $target, 2), 2, '');
            if (!preg_match('/^[a-z_]+$/', $type) || $id === '') {
                throw new RuntimeException('A target is type:id, like user:156.');
            }
            $match = '(`target_type` = ' . $this->quote($type) . ' AND `target_id` = ' . $this->quote($id) . ')';
            if ($type === 'user' && ctype_digit($id)) {
                // A link verdict names both users in `after.users`.
                $match = '(' . $match . ' OR (`target_type` = "link" AND JSON_CONTAINS(`after`, ' . $this->quote($id) . ', "$.users")))';
            }
            $where[] = $match;
        }
        if ($scope === 'operations') {
            $where[] = '`action` NOT LIKE "staff.%"';
        } elseif ($scope === 'own') {
            $where[] = '`staff_id` = ' . $staffId;
        }
        if ($beforeId) {
            $where[] = '`id` < ' . $beforeId;
        }
        if ($action !== null && $action !== '') {
            $where[] = '`action` LIKE ' . $this->quote(str_replace(['%', '_'], ['\\%', '\\_'], $action) . '%');
        }
        $rows = (array) $this->db->db_GetArray(
            'SELECT * FROM `staff_audit`' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY `id` DESC LIMIT 51'
        );
        $hasMore = count($rows) > 50;

        return [
            'entries' => array_map(static fn(array $row): array => [
                'id' => (int) $row['id'],
                'staffId' => $row['staff_id'] !== null ? (int) $row['staff_id'] : null,
                'staffEmail' => (string) $row['staff_email'],
                'action' => (string) $row['action'],
                'targetType' => (string) $row['target_type'],
                'targetId' => (string) $row['target_id'],
                'before' => $row['before'] !== null ? json_decode((string) $row['before'], true) : null,
                'after' => $row['after'] !== null ? json_decode((string) $row['after'], true) : null,
                'ip' => $row['ip'],
                'createdAt' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row['created_at'] . ' UTC')),
            ], array_slice($rows, 0, 50)),
            'hasMore' => $hasMore,
        ];
    }

    private function quote(?string $value): string
    {
        return $value === null ? 'NULL' : '"' . $this->db->db_Escape($value) . '"';
    }
}
