<?php

namespace Tests\Unit;

use App\Services\SimplePdfGenerator;
use Tests\TestCase;

class SimplePdfGeneratorTest extends TestCase
{
    public function test_it_generates_a_valid_paginated_pdf_for_long_statements(): void
    {
        $lines = array_map(
            fn (int $number) => sprintf('2026-08-20 | Payment %03d | 1000 | 2500', $number),
            range(1, 120)
        );

        $pdf = SimplePdfGenerator::build('Customer Statement', $lines);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
        $this->assertGreaterThan(10_000, strlen($pdf));
        $this->assertGreaterThanOrEqual(2, substr_count($pdf, '/Type /Page'));
    }

    public function test_it_accepts_myanmar_customer_text_without_corrupting_the_pdf(): void
    {
        $pdf = SimplePdfGenerator::build('ဖောက်သည် ငွေစာရင်း', [
            'ဖောက်သည်: မောင်မောင်',
            'လက်ရှိ လက်ကျန်ငွေ: ၁၀၀၀',
        ]);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('%%EOF', $pdf);
        $this->assertGreaterThan(5_000, strlen($pdf));
        $this->assertStringContainsString('Padauk', $pdf);
    }
}
