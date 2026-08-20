<?php

namespace App\Services;

class PdfLabels
{
    private array $labels;

    public function __construct(?string $locale)
    {
        $translations = require resource_path('i18n/pdf-translations.php');
        $this->labels = $translations[$locale] ?? $translations['en'];
    }

    public function get(string $key): string
    {
        return $this->labels[$key] ?? $key;
    }

    public function event(string $eventType): string
    {
        return $this->get('event_'.$eventType);
    }
}
