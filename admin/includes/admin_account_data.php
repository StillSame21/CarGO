<?php

// Data-loading helpers for admin/add_admin.php. Declaration-only (PSR-1 §2.3).

/**
 * Rebuild the list URL keeping search, filters and page intact.
 * Same contract as carPageUrl() in manage_cars.php.
 */
function adminPageUrl(array $state, array $overrides = []): string
{
    $params = array_merge($state, $overrides);
    $params = array_filter(
        $params,
        static fn($value) => $value !== null && $value !== '' && $value !== 'all'
    );

    return $params ? 'add_admin.php?' . http_build_query($params) : 'add_admin.php';
}

/**
 * Filtered, paginated admin list plus the matching total.
 *
 * @return array{rows: array<int, array>, total: int}
 */
function loadAdmins(mysqli $conn, string $search, string $roleFilter, string $statusFilter, int $perPage, int $offset): array
{
    $where = ['1=1'];
    $params = [];
    $types = '';

    if ($search !== '') {
        $where[] = '(name LIKE ? OR email LIKE ? OR phone LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'sss';
    }

    if ($roleFilter !== '' && $roleFilter !== 'all') {
        $where[] = 'role = ?';
        $params[] = $roleFilter;
        $types .= 's';
    }

    if ($statusFilter !== '' && $statusFilter !== 'all') {
        $where[] = 'status = ?';
        $params[] = $statusFilter;
        $types .= 's';
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $countStmt = $conn->prepare("SELECT COUNT(*) AS total FROM admins $whereSql");
    if ($types !== '') {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $total = (int) (($countStmt->get_result()->fetch_assoc())['total'] ?? 0);

    $listStmt = $conn->prepare(
        "SELECT id, name, email, phone, role, status, created_at, updated_at
         FROM admins
         $whereSql
         ORDER BY created_at DESC, id DESC
         LIMIT ? OFFSET ?"
    );
    $listParams = array_merge($params, [$perPage, $offset]);
    $listStmt->bind_param($types . 'ii', ...$listParams);
    $listStmt->execute();

    return [
        'rows' => $listStmt->get_result()->fetch_all(MYSQLI_ASSOC),
        'total' => $total,
    ];
}

// Admin row for the edit form, or null when the id doesn't resolve.
function loadAdminAccount(mysqli $conn, int $adminId): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, name, email, phone, role, status, created_at, updated_at
         FROM admins
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();

    return $admin ?: null;
}

// True if another admin already uses this email.
function adminEmailExists(mysqli $conn, string $email, int $adminId = 0): bool
{
    $stmt = $conn->prepare('SELECT id FROM admins WHERE email = ? AND id <> ? LIMIT 1');
    $stmt->bind_param('si', $email, $adminId);
    $stmt->execute();

    return (bool) $stmt->get_result()->fetch_assoc();
}

// How many active super_admin accounts currently exist.
function activeSuperAdminCount(mysqli $conn): int
{
    $role = 'super_admin';
    $status = 'active';
    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM admins WHERE role = ? AND status = ?');
    $stmt->bind_param('ss', $role, $status);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    return (int) ($row['total'] ?? 0);
}

// True if this edit would demote/deactivate the last active super_admin, locking everyone out.
function wouldRemoveLastActiveSuperAdmin(mysqli $conn, array $admin, string $newRole, string $newStatus): bool
{
    if (($admin['role'] ?? '') !== 'super_admin' || ($admin['status'] ?? '') !== 'active') {
        return false;
    }

    if ($newRole === 'super_admin' && $newStatus === 'active') {
        return false;
    }

    return activeSuperAdminCount($conn) <= 1;
}
