<?php

function hasPermission($conn, $user_id, $permission_name)
{
    $sql = "
        SELECT p.permission_name
        FROM users u
        JOIN roles r ON u.role_id = r.id
        JOIN role_permissions rp ON r.id = rp.role_id
        JOIN permissions p ON rp.permission_id = p.id
        WHERE u.id = ?
        AND p.permission_name = ?
    ";

    $stmt = $conn->prepare($sql);

    $stmt->bind_param("is", $user_id, $permission_name);

    $stmt->execute();

    $result = $stmt->get_result();

    return $result->num_rows > 0;
}