<?php
/**
 * Run the existing exportDayBookExcel.php (mode=all) on one allocation folder and write the
 * workbook to stdout. Used by MonthlyRun::buildOutputsZip().
 *
 *   php monthly/cli_export.php <folder containing BOOK.xlsx>
 */
if (PHP_SAPI !== 'cli' || !isset($argv[1]) || !is_file($argv[1] . '/BOOK.xlsx')) {
    fwrite(STDERR, "Usage: php cli_export.php <folder containing BOOK.xlsx>\n");
    exit(1);
}

set_include_path(dirname(__DIR__) . PATH_SEPARATOR . get_include_path());
chdir($argv[1]);
$_GET = array('mode' => 'all');
require dirname(__DIR__) . '/exportDayBookExcel.php';

// exportDayBookExcel.php exits after sending a workbook; reaching here means it did not
fwrite(STDERR, "exportDayBookExcel.php did not produce a workbook\n");
exit(1);
