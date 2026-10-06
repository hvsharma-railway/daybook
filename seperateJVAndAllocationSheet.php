<?php
require 'vendor/autoload.php'; // Make sure PhpSpreadsheet is installed via Composer
require_once __DIR__ . '/jvSeparation.php';

$targetDir = "daybook-generated-files";
list($jvNumbers, $nonJvNumbers) = separateJVAndNonJVNumbers($targetDir);

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
