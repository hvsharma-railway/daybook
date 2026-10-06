<?php
/**
 * Daybook view for one allocation of a monthly run: the unchanged view.php, run against that
 * allocation's BOOK.xlsx. Its Excel export and back links are pointed at the monthly versions.
 */
require __DIR__ . '/monthly/bootstrap.php';

list($run, $allocation) = monthlyRunFromQuery(true);
if (!$run->hasSource($allocation)) {
    monthlyErrorPage('Allocation ' . $allocation . ' for ' . $run->period()['label'] . ' has not been downloaded yet.', $run->month());
}

$query = 'month=' . urlencode($run->month()) . '&alloc=' . urlencode($allocation);
$banner = '<div style="background:#eef4fb;border-bottom:1px solid #c9d9ec;padding:8px 50px;font-family:sans-serif;font-size:14px;">'
    . '<a href="monthly.php?month=' . urlencode($run->month()) . '">&larr; Monthly Process</a>'
    . ' &nbsp;|&nbsp; ' . monthlyEscape($run->period()['label']) . ' &middot; Allocation ' . monthlyEscape($allocation) . '</div>';

ob_start(function ($html) use ($query, $run, $banner) {
    $html = str_replace(
        array('exportDayBookExcel.php?', 'href="index.php"'),
        array('monthlyExport.php?' . $query . '&', 'href="monthly.php?month=' . urlencode($run->month()) . '"'),
        $html
    );
    // Only the page's own <body>, not the one its print script writes
    $body = strpos($html, '<body>');
    return $body === false ? $html : substr_replace($html, '<body>' . $banner, $body, strlen('<body>'));
});

chdir($run->allocationDir($allocation));
include __DIR__ . '/view.php';
