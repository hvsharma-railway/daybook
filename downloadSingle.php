<?php
$co6 = isset($_GET['co6']) ? trim($_GET['co6']) : '';
if ($co6 === '') exit('Invalid CO6 number.');

$url = "https://aims.indianrailways.gov.in/IPAS/downloadPDF?filetype=CO6&dfilename=" . $co6;
header("Location: $url");
exit;
?>