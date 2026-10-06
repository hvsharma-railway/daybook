<?php
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Reading, checking and converting AIMS Suspense Head reports.
 *
 * AIMS sends "SuspenseHead.xls" while view.php, exportDayBookExcel.php and the JV separation
 * read .xlsx. A file is converted by writing every cell as the text PhpSpreadsheet reports
 * for it, which is exactly what those pages get back from toArray() - and matches the .xlsx
 * files users used to save by hand from Excel, where every cell is text. No value is
 * recalculated, rounded or reformatted.
 */
class SuspenseHeadFile
{
    // Columns view.php / exportDayBookExcel.php read by position from each "SECTION" header row
    const EXPECTED_COLUMNS = array(
        0 => 'SECTION',
        1 => 'CO6 NUMBER',
        2 => 'CO7 NUMBER',
        3 => 'BOOK DATE',
        4 => 'PARTY NAME',
        5 => 'BILL DESC',
        6 => 'DEBIT',
        7 => 'CREDIT',
        11 => 'UNIQUE_WORK_ID',
    );

    /**
     * Read the active sheet of any spreadsheet format AIMS may send (.xls, .xlsx, HTML table, text).
     *
     * @return array ['rows' => toArray() rows, 'format' => reader name]
     */
    public static function read($path)
    {
        try {
            $format = IOFactory::identify($path);
            $spreadsheet = IOFactory::createReader($format)->load($path);
        } catch (\Exception $e) {
            $head = (string) file_get_contents($path, false, null, 0, 4096);
            if (preg_match('/<(!doctype html|html|body)\b/i', $head)) {
                throw new RuntimeException('This is a web page (such as an AIMS login or error page), not the Suspense Head report.');
            }
            throw new RuntimeException('The file is not a readable spreadsheet (' . $e->getMessage() . ').');
        }

        return array('rows' => $spreadsheet->getActiveSheet()->toArray(), 'format' => $format);
    }

    /**
     * Check that the rows are the expected allocation's report for the expected period, laid
     * out the way view.php reads it. Throws RuntimeException with the reason otherwise.
     *
     * @return array ['heads' => sub-allocation headings, 'entries' => transaction rows]
     */
    public static function validate(array $rows, $allocation, array $period)
    {
        // view.php takes the Daybook title from row 2
        $heading = isset($rows[1][0]) ? trim((string) $rows[1][0]) : '';
        if (stripos($heading, 'SUSPENSE HEAD') === false) {
            throw new RuntimeException('Not a Suspense Head report: row 2 should read "SUSPENSE HEAD REPORT FROM ..." but reads "' . self::clip($heading) . '".');
        }
        if (!preg_match('#FROM\s+(\d{1,2})/(\d{1,2})/(\d{4})\s+TO\s+(\d{1,2})/(\d{1,2})/(\d{4})#i', $heading, $m)) {
            throw new RuntimeException('Report period not found in heading "' . self::clip($heading) . '".');
        }
        $from = sprintf('%d/%d/%d', $m[1], $m[2], $m[3]);
        $to = sprintf('%d/%d/%d', $m[4], $m[5], $m[6]);
        if ($from !== $period['start'] || $to !== $period['end']) {
            throw new RuntimeException('Report is for ' . $from . ' to ' . $to . ', expected ' . $period['start'] . ' to ' . $period['end'] . '.');
        }

        $heads = 0;
        $entries = 0;
        foreach ($rows as $index => $row) {
            $first = isset($row[0]) ? (string) $row[0] : '';
            if (strpos($first, 'ALLOCATION ') !== false) {
                // Same position view.php reads the code from: "ALLOCATION : 20164103-***"
                if (substr($first, 13, 2) !== (string) $allocation) {
                    throw new RuntimeException('File contains allocation ' . substr($first, 13, 8) . ' (row ' . ($index + 1) . '); expected only allocation ' . $allocation . '.');
                }
                $heads++;
            } elseif (strtoupper(trim($first)) === 'SECTION') {
                foreach (self::EXPECTED_COLUMNS as $column => $name) {
                    $actual = isset($row[$column]) ? strtoupper(trim((string) $row[$column])) : '';
                    if ($actual !== $name) {
                        throw new RuntimeException('Column layout changed: column ' . Coordinate::stringFromColumnIndex($column + 1) . ' of row ' . ($index + 1) . ' is "' . self::clip($actual) . '", expected "' . $name . '".');
                    }
                }
            } elseif (trim($first) !== '' && $heads > 0) {
                $entries++;
            }
        }

        return array('heads' => $heads, 'entries' => $entries);
    }

    /**
     * Write rows to an .xlsx file with every cell stored as text.
     */
    public static function writeXlsx(array $rows, $targetPath)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                if ($value === null) {
                    continue;
                }
                $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($c + 1) . ($r + 1), (string) $value, DataType::TYPE_STRING);
            }
        }

        $tmp = $targetPath . '.tmp';
        (new Xlsx($spreadsheet))->save($tmp);
        if (!rename($tmp, $targetPath)) {
            throw new RuntimeException('Could not save ' . basename($targetPath) . '.');
        }
    }

    /**
     * Confirm a converted file opens the way view.php opens it.
     */
    public static function assertReadableByDaybook($path)
    {
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $rows = $reader->load($path)->getActiveSheet()->toArray();
        if (!isset($rows[1][0]) || stripos((string) $rows[1][0], 'SUSPENSE HEAD') === false) {
            throw new RuntimeException(basename(dirname($path)) . '/' . basename($path) . ' does not open as a Suspense Head report.');
        }
    }

    private static function clip($text)
    {
        $text = (string) $text;
        return strlen($text) > 80 ? substr($text, 0, 77) . '...' : $text;
    }
}
