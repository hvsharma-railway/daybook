<?php
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Separate CO6 numbers of JV sections from the rest, across every numbered
 * Suspense Head file (1.xlsx, 2.xlsx, ...) in $targetDir.
 *
 * Used by seperateJVAndAllocationSheet.php and by the monthly workflow.
 *
 * @return array [jvNumbers, nonJvNumbers]
 */
function separateJVAndNonJVNumbers($targetDir)
{
    $jvNumbers = [];
    $nonJvNumbers = [];

    // Every numbered file, in order (previously only 1..8, which skipped the 9th allocation)
    $fileNumbers = [];
    foreach (glob($targetDir . "/*.xlsx") as $path) {
        $name = pathinfo($path, PATHINFO_FILENAME);
        if (ctype_digit($name)) {
            $fileNumbers[] = (int)$name;
        }
    }
    sort($fileNumbers);

    foreach ($fileNumbers as $i) {
        $filePath = $targetDir . "/$i.xlsx";
        if (!file_exists($filePath)) continue;

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($sheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            $cells = [];
            foreach ($cellIterator as $cell) {
                $cells[] = trim((string)$cell->getValue());
            }
            // Check header row
            if (strtoupper($cells[0]) == "SECTION" && strtoupper($cells[1]) == "CO6 NUMBER") {
                continue;
            }
            // JV and non-JV separation by pattern
            if (preg_match('/^\d{2}-JV$/', $cells[0]) && !empty($cells[1])) {
                $jvNumbers[] = $cells[1];
            } elseif (!empty($cells[1])) {
                $nonJvNumbers[] = $cells[1];
            }
        }
    }

    // Clean JV numbers: keep only digits
    $jvNumbers = array_map(function($num) {
        return preg_replace('/\D/', '', $num);
    }, $jvNumbers);
    $jvNumbers = array_filter($jvNumbers);
    $jvNumbers = array_unique($jvNumbers);
    $jvNumbers = array_values($jvNumbers);

    // Clean non-JV numbers: remove entries containing P1-, P2-, etc.
    $nonJvNumbers = array_filter($nonJvNumbers, function($num) {
        return !preg_match('/P\d+-/', $num);
    });
    // Clean non-JV numbers: keep only digits
    $nonJvNumbers = array_map(function($num) {
        return preg_replace('/\D/', '', $num);
    }, $nonJvNumbers);
    $nonJvNumbers = array_filter($nonJvNumbers);
    $nonJvNumbers = array_unique($nonJvNumbers);
    $nonJvNumbers = array_values($nonJvNumbers);

    return [$jvNumbers, $nonJvNumbers];
}
