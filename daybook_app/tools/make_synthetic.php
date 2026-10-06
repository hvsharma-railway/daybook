<?php
/**
 * Synthetic Suspense Head files that exercise view.php's edge cases.
 * Run in the PHP container: php tools/make_synthetic.php tests/fixtures/synthetic
 */
require '/var/www/html/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$header = array('SECTION', 'CO6 NUMBER', 'CO7 NUMBER', 'BOOK DATE', 'PARTY NAME', 'BILL DESC', 'DEBIT', 'CREDIT', 'SPU', 'CONTRACT ID', 'TAN NUMBER', 'UNIQUE_WORK_ID');

function write_report($path, $period, array $blocks, $header)
{
    $book = new Spreadsheet();
    $sheet = $book->getActiveSheet();
    $rows = array(array('WESTERN RAILWAY'), array('SUSPENSE HEAD   REPORT FROM ' . $period), array('Report generated on: 07.09.2026 at 12:40:55 PM'), array());
    foreach ($blocks as $allocation => $entries) {
        $rows[] = array('ALLOCATION : ' . preg_replace('/#.*/', '', $allocation) . '-***');
        $rows[] = $header;
        foreach ($entries as $entry) {
            $rows[] = $entry;
        }
        $rows[] = array(null, null, null, null, null, 'Allocation Total', '0', '0');
    }
    foreach ($rows as $r => $row) {
        foreach ($row as $c => $value) {
            if ($value !== null) {
                $sheet->setCellValueExplicitByColumnAndRow($c + 1, $r + 1, (string) $value, DataType::TYPE_STRING);
            }
        }
    }
    (new Xlsx($book))->save($path);
}

function e($section, $co6, $co7, $date, $party, $desc, $debit, $credit, $uwid = '')
{
    return array($section, $co6, $co7, $date, $party, $desc, $debit, $credit, 'ENGINEERING', '***', 'RKTS01812G', $uwid);
}

$dir = $argv[1];
@mkdir($dir, 0777, true);

// Edge cases for allocation 20
write_report("$dir/edge20.xlsx", '1/8/2026 TO 31/8/2026', array(
    '20164103' => array(
        e('01-JV', '081832601419', '081832601419', '31/08/2026', 'JV PARTY', '***', '2801', '0', '150416243002'),
        e('SBNS', '08180426001166', '08180426700142', '20/08/2026', 'ACME & CO <LTD>', 'PC "PRINTER"', '62288.1', '0', '150416243002'),
        e('SBNS', '08180426001166', '08180426700142', '20/08/2026', 'ACME & CO <LTD>', 'PC', '100.555', '0.004', '150416243002'),
        e('X-I', '08180126004208', '08180126700001', '21/08/2026', 'BOTH SIDES', 'BOTH', '500', '200', ''),
        e('X-I', '08180126004209', '08180126700002', '21/08/2026', 'ZERO ROW', 'ZERO', '0', '0', '0'),
        e('10-JV', '081832601420', '081832601420', '31/08/2026', 'SYS-GENERATED CONTRA REVENUE JV', '***', '0', '-5747', '150416243002'),
        e('X-I', '08180126004210', '08180126700003', '21/08/2026', 'MALFORMED', 'SPACES', ' 12', 'abc', '150416243009'),
        e('X-I', '08180126004211', '08180126700004', '21/08/2026', 'NEG ZERO', 'NZ', '0.00', '0.00', '150416243010'),
    ),
    '20164166' => array(
        e('SBNS', '08180426001166', '08180426700142', '22/08/2026', 'ACME & CO <LTD>', 'PC', '0', '1000.25', '150416243002'),
        e('X-I', '08180126004208', '08180126700001', '21/08/2026', 'BOTH SIDES', 'BOTH', '0', '0', ''),
        e('X-I', '08180126004212', '08180126700005', '23/08/2026', 'CREDIT ONLY', 'CR', '0', '-75.5', '150416243011'),
    ),
    '20164103#again' => array( // same head again: view.php resets it
        e('X-I', '08180126004213', '08180126700006', '24/08/2026', 'AFTER RESET', 'AR', '42', '0', '150416243012'),
    ),
    '20664103' => array( // code 66: excluded
        e('X-I', '08180126004214', '08180126700007', '24/08/2026', 'EXCLUDED', 'EX', '999', '0', '150466243001'),
    ),
    '20814103' => array( // code 81: included
        e('X-I', '08180126004215', '08180126700008', '24/08/2026', 'CODE 81', 'C81', '81.81', '0', '150481243001'),
    ),
    '20834103' => array( // code 83: included
        e('A', '1', '2', '24/08/2026', 'SHORT CO6', 'C83', '0.1', '0.3', '150483243001'),
        e('A', '1', '2', '24/08/2026', 'SHORT CO6', 'C83', '0.2', '0', '150483243001'),
    ),
), $header);

// Amounts that cancel out, producing float noise (view.php's "exponentially generated 0")
write_report("$dir/noise21.xlsx", '1/8/2026 TO 31/8/2026', array(
    '21646403' => array(
        e('SBNS', '08180426000001', '08180426700001', '20/08/2026', 'A', 'A', '0.1', '0', '150464243001'),
        e('SBNS', '08180426000002', '08180426700002', '20/08/2026', 'B', 'B', '0.2', '0', '150464243002'),
        e('SBNS', '08180426000003', '08180426700003', '20/08/2026', 'C', 'C', '0', '0.3', '150464243003'),
        e('SBNS', '08180426000004', '08180426700004', '20/08/2026', 'D', 'D', '1234567.89', '0', '150464243004'),
        e('SBNS', '08180426000005', '08180426700005', '20/08/2026', 'E', 'E', '0', '1234567.89', '150464243005'),
    ),
    '21646463' => array(
        e('SBNS', '08180426000006', '08180426700006', '20/08/2026', 'F', 'F', '0.001', '0', '150464243006'),
        e('SBNS', '08180426000007', '08180426700007', '20/08/2026', 'G', 'G', '0', '0.001', '150464243006'),
    ),
    '21656403' => array(
        e('SBNS', '08180426000008', '08180426700008', '20/08/2026', 'H', 'H', '99999999999.99', '0', '150465243001'),
        e('SBNS', '08180426000009', '08180426700009', '20/08/2026', 'I', 'I', '0', '0.01', '150465243001'),
    ),
), $header);
echo "ok\n";
