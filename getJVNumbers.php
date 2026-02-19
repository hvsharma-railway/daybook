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
            var btnNonJV = document.getElementById('copyNonJVBtn');
            if (btnNonJV) {
                btnNonJV.onclick = function() {
                    const arrStr = JSON.stringify(NON_JV_NUMBERS, null, 2);
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(arrStr).then(function() {
                            alert('NON_JV_NUMBERS array copied to clipboard!');
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
        const NON_JV_NUMBERS = " . json_encode($nonJvNumbers) . ";
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
    // Export to Excel button
    echo "<button id='exportNonJVBtn' style='margin-bottom:16px;'>Export to Excel</button>";
    echo "<script>
        const NON_JV_NUMBERS = " . json_encode($nonJvNumbers) . ";
        document.addEventListener('DOMContentLoaded', function() {
            var btnExport = document.getElementById('exportNonJVBtn');
            if (btnExport) {
                btnExport.onclick = function() {
                    // Create worksheet data
                    var ws_data = [['CO6NUMBER']];
                    NON_JV_NUMBERS.forEach(function(num) {
                        ws_data.push([num]);
                    });
                    // XLSX generation using SheetJS
                    var wb = XLSX.utils.book_new();
                    var ws = XLSX.utils.aoa_to_sheet(ws_data);
                    XLSX.utils.book_append_sheet(wb, ws, 'NonJVNumbers');
                    var wbout = XLSX.write(wb, {bookType:'xlsx', type:'array'});
                    var blob = new Blob([wbout], {type:'application/octet-stream'});
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = 'NonJVNumbers.xlsx';
                    document.body.appendChild(a);
                    a.click();
                    setTimeout(function() {
                        document.body.removeChild(a);
                        window.URL.revokeObjectURL(url);
                    }, 0);
                };
            }
        });
    </script>
    <script src='https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js'></script>";
} else {
    echo "No Non-JV Numbers found.";
}

