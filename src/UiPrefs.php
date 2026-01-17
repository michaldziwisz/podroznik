<?php
declare(strict_types=1);

namespace TyfloPodroznik;

final class UiPrefs
{
    public function __construct(
        public readonly bool $contrast,
        public readonly string $font, // md|lg|xl
    ) {
    }

    public static function fromCookies(): self
    {
        $contrast = ($_COOKIE['ui_contrast'] ?? '') === '1';
        $font = (string) ($_COOKIE['ui_font'] ?? 'md');
        if (!in_array($font, ['md', 'lg', 'xl'], true)) {
            $font = 'md';
        }
        return new self($contrast, $font);
    }

    public function htmlClass(): string
    {
        $classes = [];
        if ($this->contrast) {
            $classes[] = 'ui-contrast';
        }
        if ($this->font === 'lg') {
            $classes[] = 'ui-font-lg';
        } elseif ($this->font === 'xl') {
            $classes[] = 'ui-font-xl';
        }
        return implode(' ', $classes);
    }

    public static function handlePost(string $action, string $redirectTo): never
    {
        $prefs = self::fromCookies();

        $contrast = $prefs->contrast;
        $font = $prefs->font;

        switch ($action) {
            case 'toggle_contrast':
                $contrast = !$contrast;
                break;
            case 'font_inc':
                $font = $font === 'md' ? 'lg' : ($font === 'lg' ? 'xl' : 'xl');
                break;
            case 'font_dec':
                $font = $font === 'xl' ? 'lg' : ($font === 'lg' ? 'md' : 'md');
                break;
            default:
                break;
        }

        setcookie('ui_contrast', $contrast ? '1' : '0', [
            'expires' => time() + 365 * 24 * 60 * 60,
            'path' => '/',
            'httponly' => false,
            'samesite' => 'Lax',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
        setcookie('ui_font', $font, [
            'expires' => time() + 365 * 24 * 60 * 60,
            'path' => '/',
            'httponly' => false,
            'samesite' => 'Lax',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);

        Html::redirect($redirectTo);
    }
}

