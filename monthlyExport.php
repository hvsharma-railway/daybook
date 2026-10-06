<?php
/**
 * The unchanged exportDayBookExcel.php, run against one allocation's BOOK.xlsx of a monthly run.
 * Its own parameters (mode, allocation, summary) pass straight through.
 */
require __DIR__ . '/monthly/bootstrap.php';

list($run, $allocation) = monthlyRunFromQuery(true);
if (!$run->hasSource($allocation)) {
    monthlyErrorPage('Allocation ' . $allocation . ' for ' . $run->period()['label'] . ' has not been downloaded yet.', $run->month());
}

chdir($run->allocationDir($allocation));
include __DIR__ . '/exportDayBookExcel.php';
