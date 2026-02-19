<?php
$co6Numbers = [];
if (isset($_POST['exported_co6numbers'])) {
    $co6Numbers = json_decode($_POST['exported_co6numbers'], true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Exported CO6NUMBERs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .container { max-width: 600px; margin-top: 40px; }
        h2 { font-size: 1.6rem; font-weight: 600; margin-bottom: 1.5rem; }
        .table th, .table td { vertical-align: middle; }
    </style>
</head>
<body>
<div class="container">
    <h2 class="text-center mb-4">Exported CO6NUMBERs</h2>
    <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle">
            <thead class="table-light">
                <tr>
                    <th>Sr No</th>
                    <th>CO6NUMBER</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php
            foreach ($co6Numbers as $i => $co6) {
                echo '<tr>';
                echo '<td>' . ($i + 1) . '</td>';
                echo '<td>' . htmlspecialchars($co6) . '</td>';
                // Download button for each CO6NUMBER
                echo '<td>
                    <button type="button" class="btn btn-sm btn-primary download-btn" onclick="downloadNextFile(\'' . $co6 . '\')">Download</button>
                </td>';
                echo '</tr>';
            }
            ?>
            </tbody>
        </table>
    </div>
    <div class="text-center my-4">
        <button type="button" class="btn btn-success download-all-btn" onclick="downloadAllFiles()">Download All</button>
        <form method="post" action="mergeAndDownload.php" class="d-inline">
            <button type="submit" class="btn btn-warning ms-2">Merge & Download PDF</button>
        </form>
    </div>
</div>
<script>
function downloadNextFile(co6) {
    var url = "https://aims.indianrailways.gov.in/IPAS/downloadPDF?filetype=CO6&dfilename=" + co6;
    window.open(url, '_blank');
}

function downloadAllFiles() {
    var co6Array = <?php echo json_encode($co6Numbers); ?>;
    var downloaded = new Set();
    var index = 0;

    function next() {
        if (index < co6Array.length) {
            var co6 = co6Array[index];
            if (co6 && !downloaded.has(co6)) {
                downloadNextFile(co6);
                downloaded.add(co6);
            }
            index++;
            setTimeout(next, 5000);
        }
    }
    next();
}
</script>
</body>
</html>