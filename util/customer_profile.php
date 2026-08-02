<?php

// Customer row for the profile form, or null when the id doesn't resolve.
function loadCustomerProfile(mysqli $conn, int $customerId): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, name, email, phone, address, password
         FROM customers
         WHERE id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $customerId);
    $stmt->execute();

    $customer = $stmt->get_result()->fetch_assoc();

    return $customer ?: null;
}

// True if another customer already uses this email.
function customerProfileEmailExists(mysqli $conn, string $email, int $customerId): bool
{
    $stmt = $conn->prepare('SELECT id FROM customers WHERE email = ? AND id <> ? LIMIT 1');
    $stmt->bind_param('si', $email, $customerId);
    $stmt->execute();

    return $stmt->get_result()->num_rows > 0;
}
