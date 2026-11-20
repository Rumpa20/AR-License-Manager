<?php
/**
 * AR License Manager - View helpers: escaping, language strings, flash
 * messages, pagination and whitelisted sorting for the admin app.
 * Copyright (c) ARNIT CREATION.
 */
declare(strict_types=1);

namespace ARLM;

final class View
{
    /** @var array<string, string> */
    public static array $lang = [];

    public static function bootLanguage(): void
    {
        $lang = preg_replace('/[^a-z_]/', '', strtolower(Settings::get('language', 'english'))) ?: 'english';
        $file = ARLM_ROOT . '/lang/' . $lang . '.php';
        if (!is_file($file)) {
            $file = ARLM_ROOT . '/lang/english.php';
        }
        /** @var array<string, string> $strings */
        $strings = require $file;
        self::$lang = is_array($strings) ? $strings : [];
    }

    public static function t(string $key): string
    {
        return self::$lang[$key] ?? $key;
    }

    public static function h(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function query(string $key, string $default = ''): string
    {
        $v = $_GET[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    public static function post(string $key, string $default = ''): string
    {
        $v = $_POST[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    public static function setFlash(string $type, string $message): void
    {
        $_SESSION['arlm_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return array<int, array{type: string, message: string}> */
    public static function takeFlashes(): array
    {
        $flashes = $_SESSION['arlm_flash'] ?? [];
        unset($_SESSION['arlm_flash']);
        return is_array($flashes) ? $flashes : [];
    }

    public static function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }

    /** Whitelisted sort columns; returns [column, ASC|DESC]. */
    public static function sort(array $allowed, string $defaultCol, string $defaultDir = 'DESC'): array
    {
        $col = in_array(self::query('sort'), $allowed, true) ? self::query('sort') : $defaultCol;
        $dir = strtoupper(self::query('dir')) === 'ASC' ? 'ASC' : $defaultDir;
        return [$col, $dir];
    }

    public static function page(int $perPage = 20): int
    {
        return max(1, (int) self::query('page', '1'));
    }

    public static function offset(int $page, int $perPage): int
    {
        return ($page - 1) * $perPage;
    }

    public static function paginate(int $total, int $page, int $perPage, string $baseQuery): string
    {
        $pages = max(1, (int) ceil($total / $perPage));
        if ($pages <= 1) {
            return '<p class="muted">' . (int) $total . ' ' . self::t('results') . '</p>';
        }
        $links = [];
        $from = max(1, $page - 2);
        $to = min($pages, $page + 2);
        $mk = static function (int $p, string $label, bool $current = false) use ($baseQuery): string {
            $sep = str_contains($baseQuery, '?') ? '&' : '?';
            $cls = $current ? ' class="cur"' : '';
            return '<a' . $cls . ' href="' . View::h($baseQuery . $sep . 'page=' . $p) . '">' . View::h($label) . '</a>';
        };
        if ($page > 1) {
            $links[] = $mk($page - 1, '&laquo; ' . self::t('prev'));
        }
        for ($p = $from; $p <= $to; $p++) {
            $links[] = $mk($p, (string) $p, $p === $page);
        }
        if ($page < $pages) {
            $links[] = $mk($page + 1, self::t('next') . ' &raquo;');
        }
        return '<div class="pager">' . implode(' ', $links) . '<span class="muted"> ' . (int) $total . ' ' . self::t('results') . '</span></div>';
    }

    public static function csv(array $headers, array $rows, string $filename): never
    {
        if (!Auth::loggedIn()) {
            http_response_code(403);
            exit;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '', $filename) . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, array_map(static fn ($v) => (string) $v, $row));
        }
        fclose($out);
        exit;
    }

    public static function alert(array $flashes): string
    {
        $html = '';
        foreach ($flashes as $f) {
            $class = $f['type'] === 'error' ? 'error' : ($f['type'] === 'warn' ? 'warn' : 'ok');
            $html .= '<div class="alert ' . $class . '">' . self::h($f['message']) . '</div>';
        }
        return $html;
    }

    public static function statusBadge(string $status): string
    {
        return '<span class="badge st-' . self::h($status) . '">' . self::h(self::t($status) !== $status ? self::t($status) : $status) . '</span>';
    }
}
