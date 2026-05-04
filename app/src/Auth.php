<?php

final class Auth
{
    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        $stmt = Database::conn()->prepare('SELECT * FROM users WHERE id = ? AND active = 1');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function attempt(string $email, string $password): bool
    {
        $stmt = Database::conn()->prepare('SELECT * FROM users WHERE email = ? AND active = 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }
        $_SESSION['user_id'] = (int)$user['id'];
        return true;
    }

    public static function logout(): void
    {
        session_destroy();
    }

    public static function requireLogin(): array
    {
        $user = self::user();
        if (!$user) {
            redirect('?page=login');
        }
        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            exit('Acesso restrito a administradores.');
        }
        return $user;
    }
}

