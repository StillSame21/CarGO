<?php

// Shared display-formatting helpers for admin pages. Declaration-only (PSR-1 §2.3).

// ' selected' attribute string when the two values match, else ''.
function selectedIf($currentValue, $optionValue): string
{
    return $currentValue === $optionValue ? ' selected' : '';
}

// CSS status-pill class for a status string (booking, car, customer, or admin status).
function statusPillClass(string $status): string
{
    return 'status-' . preg_replace('/[^a-z0-9-]/', '-', strtolower($status));
}

/** "super_admin" reads as "Super Admin" for a person; the value stays raw. */
function adminRoleLabel(string $role): string
{
    return ucwords(str_replace('_', ' ', $role));
}

// CSS role-badge class for an admin role.
function adminRoleClass(string $role): string
{
    return 'role-' . preg_replace('/[^a-z0-9_-]/', '-', strtolower($role));
}

// Human-readable date+time, or "Not set" when empty.
function formatCustomerDate(?string $date): string
{
    if ($date === null || $date === '') {
        return 'Not set';
    }

    return date('d M Y, h:i A', strtotime($date));
}
