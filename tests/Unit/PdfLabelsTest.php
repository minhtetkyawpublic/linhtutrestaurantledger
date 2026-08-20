<?php

namespace Tests\Unit;

use App\Services\PdfLabels;
use Tests\TestCase;

class PdfLabelsTest extends TestCase
{
    public function test_pdf_languages_have_exact_key_parity_and_real_myanmar_values(): void
    {
        $translations = require resource_path('i18n/pdf-translations.php');

        $this->assertSame(array_keys($translations['en']), array_keys($translations['my']));
        foreach ($translations['en'] as $key => $english) {
            $this->assertNotSame($english, $translations['my'][$key], "Myanmar PDF label {$key} still matches English.");
        }
    }

    public function test_pdf_labels_fall_back_to_english_for_an_unknown_locale(): void
    {
        $labels = new PdfLabels('unknown');

        $this->assertSame('Sale Receipt', $labels->get('receipt_title'));
        $this->assertSame('Customer credit', $labels->get('customer_credit'));
        $this->assertSame('Customer payment', $labels->event('customer_paid'));
    }
}
