<?php

namespace App\Services;

class SimplePdfGenerator
{
    public static function build(string $title, array $lines): string
    {
        $fontDirectory = rtrim(storage_path('framework/cache/tcpdf-fonts'), '/\\').DIRECTORY_SEPARATOR;
        if (! is_dir($fontDirectory) && ! mkdir($fontDirectory, 0755, true) && ! is_dir($fontDirectory)) {
            throw new \RuntimeException('Unable to create the PDF font cache directory.');
        }

        $fontName = \TCPDF_FONTS::addTTFfont(
            resource_path('fonts/Padauk-Pdf.ttf'),
            'TrueTypeUnicode',
            '',
            32,
            $fontDirectory
        );

        if (! is_string($fontName) || $fontName === '') {
            throw new \RuntimeException('Unable to prepare the bundled Myanmar PDF font.');
        }

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(16, 15, 16);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->setFontSubsetting(true);
        $pdf->AddFont($fontName, '', $fontDirectory.DIRECTORY_SEPARATOR.$fontName.'.php', true);
        $pdf->AddPage();
        $pdf->SetFont($fontName, '', 17);
        $pdf->SetTextColor(154, 52, 18);
        $pdf->MultiCell(0, 10, $title, 0, 'L', false, 1);
        $pdf->SetDrawColor(254, 215, 170);
        $pdf->Line(16, $pdf->GetY(), 194, $pdf->GetY());
        $pdf->Ln(4);
        $pdf->SetFont($fontName, '', 10.5);
        $pdf->SetTextColor(31, 41, 55);

        foreach ($lines as $line) {
            $pdf->MultiCell(0, 6.5, (string) $line, 0, 'L', false, 1);
        }

        return $pdf->Output('', 'S');
    }
}
