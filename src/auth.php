<?php

declare(strict_types=1);

function auth_user(): ?array {
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) return null;
    $u = db_one('SELECT id,email,created_at FROM users WHERE id=?', [$uid]);
    return $u;
}

function require_login(): array {
    $u = auth_user();
    if (!$u) {
        redirect('/?a=login');
    }
    return $u;
}

function auth_login(int $userId): void {
    session_regenerate_id(true);
    $_SESSION['uid'] = $userId;
}

function auth_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function auth_can_access_project(int $userId, int $projectId): bool {
    $row = db_one('SELECT 1 FROM projects WHERE id=? AND owner_user_id=?', [$projectId, $userId]);
    if ($row) return true;
    $row2 = db_one('SELECT 1 FROM project_members WHERE project_id=? AND user_id=?', [$projectId, $userId]);
    return (bool)$row2;
}
