<?php
require 'vendor/autoload.php'; // Make sure PhpSpreadsheet is installed via Composer

use PhpOffice\PhpSpreadsheet\IOFactory;

$targetDir = "daybook-generated-files";
$jvNumbers = [];
$nonJvNumbers = [];

for ($i = 1; $i <= 8; $i++) {
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

// Output JV Numbers
echo "<h2>CO6 NUMBER values for SECTIONS matching '*-JV'</h2>";
if (count($jvNumbers)) {
    echo "<ul>";
    foreach ($jvNumbers as $num) {
        echo "<li>" . htmlspecialchars($num) . "</li>";
    }
    echo "</ul>";
    echo "<button id='copyJVBtn' style='margin-bottom:16px;'>Copy All JV</button>";
    echo "<script>
        const JV_NUMBERS = " . json_encode($jvNumbers) . ";
        window.onload = function() {
            var btnJV = document.getElementById('copyJVBtn');
            if (btnJV) {
                btnJV.onclick = function() {
                    const arrStr = JSON.stringify(JV_NUMBERS, null, 2);
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(arrStr).then(function() {
                            alert('JV_NUMBERS array copied to clipboard!');
                        }, function() {
                            fallbackCopy(arrStr);
                        });
                    } else {
                        fallbackCopy(arrStr);
                    }
                };
            }
            function fallbackCopy(text) {
                var textarea = document.createElement('textarea');
                textarea.value = text;
                document.body.appendChild(textarea);
                textarea.select();
                try {
                    document.execCommand('copy');
                    alert('Array copied to clipboard!');
                } catch (err) {
                    alert('Copy failed. Please copy manually.');
                }
                document.body.removeChild(textarea);
            }
        };
    </script>";
} else {
    echo "No JV Numbers found for SECTIONS matching '*-JV'.";
}

// Output Non-JV Numbers
echo "<h2>CO6 NUMBER values for NON-JV SECTIONS</h2>";
if (count($nonJvNumbers)) {
    echo "<ul>";
    foreach ($nonJvNumbers as $num) {
        echo "<li>" . htmlspecialchars($num) . "</li>";
    }
    echo "</ul>";
    // Export to Excel button - redirect to downloadAllocationSheets.php
    echo "<form method='post' action='downloadAllocationSheets.php'>
            <input type='hidden' name='exported_co6numbers' value='" . htmlspecialchars(json_encode($nonJvNumbers), ENT_QUOTES, 'UTF-8') . "'>
            <button type='submit' class='btn btn-success' style='margin-bottom:16px;'>Export to Excel</button>
          </form>";
} else {
    echo "No Non-JV Numbers found.";
}
