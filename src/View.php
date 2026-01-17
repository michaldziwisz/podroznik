<?php
declare(strict_types=1);

namespace TyfloPodroznik;

final class View
{
    public function __construct(
        private readonly string $templatesDir = __DIR__ . '/templates',
    ) {
    }

    public function render(string $template, array $data = []): string
    {
        $path = $this->templatesDir . '/' . $template . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException("Missing template: {$template}");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $path;
        return (string) ob_get_clean();
    }
}

