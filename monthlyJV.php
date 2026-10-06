<?php
/**
 * The unchanged JV separation page, run over a monthly run's nine Suspense Head files.
 * Its "Export to Excel" leads to downloadAllocationSheets.php as before.
 */
require __DIR__ . '/monthly/bootstrap.php';

list($run) = monthlyRunFromQuery(false);
$state = $run->state();
if (!$run->isComplete($state)) {
    monthlyErrorPage('JV separation for ' . $run->period()['label'] . ' runs once all ' . count(MonthlyRun::ALLOCATIONS) . ' Suspense Head files are downloaded and processed.', $run->month());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>JV Separation - <?php echo monthlyEscape($run->period()['label']); ?></title>
</head>
<body>
<p style="font-family:sans-serif;">
    <a href="monthly.php?month=<?php echo urlencode($run->month()); ?>">&larr; Monthly Process</a>
    &nbsp;|&nbsp; <?php echo monthlyEscape($run->period()['label']); ?> &middot; allocations <?php echo implode(', ', MonthlyRun::ALLOCATIONS); ?>
</p>
<?php
chdir($run->dir());
include __DIR__ . '/seperateJVAndAllocationSheet.php';
?>
</body>
</html>
