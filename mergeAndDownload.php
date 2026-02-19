<?php
require 'vendor/autoload.php';

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfReader;

// Directory containing downloaded PDFs
$pdfDir = 'D:/laragon/www/daybook/downloads/';
$pdfFiles = glob($pdfDir . '*.pdf');
if (count($pdfFiles) === 0) {
    exit('No PDF files found to merge.');
}

$pdf = new Fpdi();
$pdf->SetAutoPageBreak(false);

$pages = [];
foreach ($pdfFiles as $file) {
    $pageCount = (new Fpdi())->setSourceFile($file);
    for ($p = 1; $p <= $pageCount; $p++) {
        $pages[] = ['file' => $file, 'page' => $p];
    }
}

for ($i = 0; $i < count($pages); $i += 2) {
    $pdf->AddPage('L'); // Landscape

    // Left page
    $pdf->setSourceFile($pages[$i]['file']);
    $tplIdx1 = $pdf->importPage($pages[$i]['page']);
    $pdf->useTemplate($tplIdx1, 10, 10, 135);

    // Right page (if exists)
    if (isset($pages[$i+1])) {
        $pdf->setSourceFile($pages[$i+1]['file']);
        $tplIdx2 = $pdf->importPage($pages[$i+1]['page']);
        $pdf->useTemplate($tplIdx2, 150, 10, 135);
    }
}

$mergedFile = $pdfDir . 'merged_allocation.pdf';
$pdf->Output('F', $mergedFile);

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="merged_allocation.pdf"');
readfile($mergedFile);
?>
