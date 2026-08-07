<?php
require 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

header('Content-Type: text/plain; charset=utf-8');

try {
    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    $spreadsheet = $reader->load('BOOK.xlsx');
    $sheetData = $spreadsheet->getActiveSheet()->toArray();

    unset($sheetData[0]);

    $allocations = array();
    $subAllocationCode = '';
    $code = '';
    $heading = isset($sheetData[1][0]) ? $sheetData[1][0] : 'Day Book';

    foreach ($sheetData as $t) {
        if (strpos($t[0], 'ALLOCATION ') !== false) {
            $code = substr($t[0], 13, 4);
            $subAllocationCode = substr($t[0], 13, 8);

            if (substr($code, 2, 4) < 66 || substr($code, 2, 4) == 81 || substr($code, 2, 4) == 83) {
                if (array_key_exists($code, $allocations)) {
                    $allocations[$code][$subAllocationCode] = array();
                } else {
                    $allocations[$code] = array($subAllocationCode => []);
                }
            }
        } else if ($t[0] != 'SECTION' && $t[0] != '' && strpos($t[4], 'SYS-GENERATED') === false && $code != '' && $subAllocationCode != '' && (substr($code, 2, 4) < 66 || substr($code, 2, 4) == 81 || substr($code, 2, 4) == 83)) {
            array_push(
                $allocations[$code][$subAllocationCode],
                array(
                    'SECTION' => $t[0],
                    'CO6' => $t[1],
                    'CO7' => $t[2],
                    'BOOK DATE' => $t[3],
                    'PARTY NAME' => $t[4],
                    'BILL DESC' => $t[5],
                    'DEBIT' => $t[6],
                    'CREDIT' => $t[7],
                    'UNIQUE_WORK_ID' => isset($t[11]) ? $t[11] : ''
                )
            );
        }
    }

    $mode = isset($_GET['mode']) ? $_GET['mode'] : 'single';
    $allocationCode = isset($_GET['allocation']) ? $_GET['allocation'] : '';
    $selectionKey = str_replace('-', '', $allocationCode);

    if ($mode === 'all') {
        $workbook = new Spreadsheet();
        $summarySheet = $workbook->createSheet();
        $summarySheet->setTitle('Summary');
        $summarySheet->mergeCells('A1:C1');
        $summaryHeading = 'Daybook Summary';
        if ($selectionKey !== '') {
            $summaryHeading .= ' - Allocation ' . substr($selectionKey, 0, 2) . '-' . substr($selectionKey, 2, 4);
        } else {
            $summaryHeading .= ' - Allocation 20';
        }
        $summarySheet->setCellValue('A1', $summaryHeading);
        $summarySheet->setCellValue('A2', 'Allocation');
        $summarySheet->setCellValue('B2', 'For The Month');
        $summarySheet->setCellValue('C2', 'To The Month');

        $summaryRows = array();
        if (isset($_GET['summary'])) {
            $summaryRows = json_decode(urldecode($_GET['summary']), true);
        }

        if (!empty($summaryRows) && is_array($summaryRows)) {
            $rowIndex = 3;
            foreach ($summaryRows as $summaryRow) {
                $label = isset($summaryRow['label']) ? $summaryRow['label'] : '';
                $forValue = isset($summaryRow['for']) ? (float) str_replace(',', '', $summaryRow['for']) : 0;
                $toValue = isset($summaryRow['to']) ? (float) str_replace(',', '', $summaryRow['to']) : 0;

                $summarySheet->setCellValue('A' . $rowIndex, $label);
                $summarySheet->setCellValue('B' . $rowIndex, number_format($forValue, 2, '.', ''));
                $summarySheet->setCellValue('C' . $rowIndex, number_format($toValue, 2, '.', ''));
                $rowIndex++;
            }
        } else {
            $grandTotal = 0;
            $rowIndex = 3;
            foreach ($allocations as $allocation => $subAllocation) {
                $allocCode = substr($allocation, 0, 2) . '-' . substr($allocation, 2, 4);
                $allocationTotal = 0;
                foreach ($subAllocation as $entries) {
                    foreach ($entries as $entry) {
                        $allocationTotal += isset($entry['DEBIT']) ? $entry['DEBIT'] : 0;
                        $allocationTotal -= isset($entry['CREDIT']) ? $entry['CREDIT'] : 0;
                    }
                }
                $grandTotal += $allocationTotal;
                $summarySheet->setCellValue('A' . $rowIndex, $allocCode);
                $summarySheet->setCellValue('B' . $rowIndex, round($allocationTotal, 2));
                $summarySheet->setCellValue('C' . $rowIndex, round($allocationTotal, 2));
                $rowIndex++;
            }
            $summarySheet->setCellValue('A' . $rowIndex, 'TOTAL');
            $summarySheet->setCellValue('B' . $rowIndex, round($grandTotal, 2));
            $summarySheet->setCellValue('C' . $rowIndex, round($grandTotal, 2));
        }

        $rowIndex = $summarySheet->getHighestRow();
        $summarySheet->getStyle('A1:C' . $rowIndex)->getFont()->setName('Calibri')->setSize(11);
        $summarySheet->getStyle('A1:C2')->getFont()->setBold(true);
        $summarySheet->getStyle('A1:C2')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9EAF7');
        $summarySheet->getStyle('A1:C' . $rowIndex)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $summarySheet->getStyle('A1:C' . $rowIndex)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $summarySheet->getStyle('B3:C' . $rowIndex)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        $summarySheet->getStyle('B3:C' . $rowIndex)->getNumberFormat()->setFormatCode('#,##0.00');
        $summarySheet->getColumnDimension('A')->setWidth(24);
        $summarySheet->getColumnDimension('B')->setWidth(20);
        $summarySheet->getColumnDimension('C')->setWidth(20);
        $summarySheet->freezePane('A3');
        $summarySheet->setAutoFilter('A1:C' . $rowIndex);

        $sheetOrder = 0;
        foreach ($allocations as $allocation => $subAllocation) {
            $allocCode = substr($allocation, 0, 2) . '-' . substr($allocation, 2, 4);
            $sheet = $workbook->createSheet();
            $sheet->setTitle(substr('Allocation ' . $allocCode, 0, 31));
            writeAllocationSheet($sheet, $allocation, $subAllocation, $allocations, $heading, $allocCode);
            $sheetOrder++;
        }
        $workbook->removeSheetByIndex(0);

        $fileName = 'DayBook_All_Allocations_' . date('Y-m-d_H-i-s') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $fileName . '"');
        header('Cache-Control: max-age=0');
        $writer = new Xlsx($workbook);
        $writer->save('php://output');
        exit;
    }

    if ($mode === 'single' && $selectionKey !== '' && isset($allocations[$selectionKey])) {
        $workbook = new Spreadsheet();
        $sheet = $workbook->getActiveSheet();
        $allocCode = substr($selectionKey, 0, 2) . '-' . substr($selectionKey, 2, 4);
        $sheet->setTitle(substr('Allocation ' . $allocCode, 0, 31));
        writeAllocationSheet($sheet, $selectionKey, $allocations[$selectionKey], $allocations, $heading, $allocCode);

        $fileName = 'DayBook_' . $allocCode . '_' . date('Y-m-d_H-i-s') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $fileName . '"');
        header('Cache-Control: max-age=0');
        $writer = new Xlsx($workbook);
        $writer->save('php://output');
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'No allocation selected']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function writeAllocationSheet($sheet, $allocation, $subAllocation, $allocations, $heading, $allocCode)
{
    $sheet->setCellValue('A1', '📋 DAY BOOK ' . substr($heading, 22) . ' - ALLOCATION ' . $allocCode);
    $sheet->mergeCells('A1:' . getExcelColumnName(count($subAllocation) + 4) . '1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle('A1')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    $sheet->getRowDimension(1)->setRowHeight(24);

    $headers = array('Sec. - CO6 / CO7', 'BILL DESC / PARTY NAME', 'UWID');
    foreach ($subAllocation as $k => $v) {
        $headers[] = $k;
    }
    $headers[] = 'Total';

    $row = 3;
    foreach ($headers as $index => $header) {
        $sheet->setCellValue(getExcelColumnName($index + 1) . $row, $header);
    }
    $sheet->getStyle('A3:' . getExcelColumnName(count($headers)) . '3')->getFont()->setBold(true)->setSize(11);
    $sheet->getStyle('A3:' . getExcelColumnName(count($headers)) . '3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9EAF7');
    $sheet->getStyle('A3:' . getExcelColumnName(count($headers)) . '3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    $data = buildAllocationData($allocations, $allocation, $subAllocation);
    $row = 4;
    foreach ($data['rows'] as $dataRow) {
        foreach ($dataRow as $idx => $value) {
            $cellCoordinate = getExcelColumnName($idx + 1) . $row;
            $cleanValue = cleanExcelCellValue($value);
            if ($idx === 2 && is_numeric(trim((string) $cleanValue))) {
                $sheet->setCellValueExplicit($cellCoordinate, (int) trim((string) $cleanValue), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                $sheet->getStyle($cellCoordinate)->getNumberFormat()->setFormatCode('0');
            } else {
                $sheet->setCellValue($cellCoordinate, $cleanValue);
            }
        }
        $row++;
    }

    $totalRow = count($data['rows']) + 4;
    $sheet->setCellValue('A' . $totalRow, 'TOTAL');
    $sheet->setCellValue('B' . $totalRow, '');
    $sheet->setCellValue('C' . $totalRow, '');
    $sheet->setCellValue(getExcelColumnName(count($headers)) . $totalRow, $data['totals']['Total']);
    $sheet->getStyle('A' . $totalRow . ':' . getExcelColumnName(count($headers)) . $totalRow)->getFont()->setBold(true);

    $sheet->getColumnDimension('A')->setWidth(24);
    $sheet->getColumnDimension('B')->setWidth(40);
    $sheet->getColumnDimension('C')->setWidth(18);
    for ($i = 4; $i <= count($headers); $i++) {
        $sheet->getColumnDimension(getExcelColumnName($i))->setWidth(16);
    }
    $sheet->getStyle('A1:' . getExcelColumnName(count($headers)) . ($totalRow))->getAlignment()->setWrapText(true);
    $sheet->getStyle('A1:' . getExcelColumnName(count($headers)) . ($totalRow))->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
    $sheet->getStyle('A4:' . getExcelColumnName(count($headers)) . $totalRow)->getFont()->setSize(10);
    $sheet->getStyle('A4:' . getExcelColumnName(count($headers)) . $totalRow)->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK));
    $sheet->getStyle('A4:A' . $totalRow)->getFont()->setSize(9);
    $sheet->getStyle('A4:A' . $totalRow)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
    $sheet->getStyle('D4:' . getExcelColumnName(count($headers)) . $totalRow)->getNumberFormat()->setFormatCode('#,##0.00');
}

function buildAllocationData($allocations, $allocation, $subAllocation)
{
    $allocationArray = array();
    $totals = array();
    foreach ($subAllocation as $k => $v) {
        $totals[$k] = 0;
    }

    foreach ($allocations[$allocation] as $key => $subAllocations) {
        foreach ($subAllocations as $subAllocation2) {
            $groupKey = $subAllocation2['CO6'];
            if (!empty($subAllocation2['UNIQUE_WORK_ID'])) {
                $groupKey = $subAllocation2['CO6'] . '|' . $subAllocation2['UNIQUE_WORK_ID'];
            }

            if (array_key_exists($groupKey, $allocationArray)) {
                if ($subAllocation2['CREDIT'] != 0 && $subAllocation2['DEBIT'] != 0) {
                    if (array_key_exists($key, $allocationArray[$groupKey])) {
                        $allocationArray[$groupKey][$key][] = $subAllocation2['DEBIT'];
                        $allocationArray[$groupKey][$key][] = -$subAllocation2['CREDIT'];
                    } else {
                        $allocationArray[$groupKey][$key] = array($subAllocation2['DEBIT'], -$subAllocation2['CREDIT']);
                    }
                    $totals[$key] = round($totals[$key] + $subAllocation2['DEBIT'] - $subAllocation2['CREDIT'], 2);
                } else if ($subAllocation2['DEBIT'] != 0 && $subAllocation2['CREDIT'] == 0) {
                    if (array_key_exists($key, $allocationArray[$groupKey])) {
                        $allocationArray[$groupKey][$key][] = $subAllocation2['DEBIT'];
                    } else {
                        $allocationArray[$groupKey][$key] = array($subAllocation2['DEBIT']);
                    }
                    $totals[$key] = round($totals[$key] + $subAllocation2['DEBIT'], 2);
                } else if ($subAllocation2['DEBIT'] == 0 && $subAllocation2['CREDIT'] != 0) {
                    if (array_key_exists($key, $allocationArray[$groupKey])) {
                        $allocationArray[$groupKey][$key][] = -$subAllocation2['CREDIT'];
                    } else {
                        $allocationArray[$groupKey][$key] = array(-$subAllocation2['CREDIT']);
                    }
                    $totals[$key] = round($totals[$key] - $subAllocation2['CREDIT'], 2);
                } else {
                    if (!array_key_exists($key, $allocationArray[$groupKey])) {
                        $allocationArray[$groupKey][$key] = array(0);
                    }
                }
            } else {
                if ($subAllocation2['DEBIT'] != 0 && $subAllocation2['CREDIT'] != 0) {
                    $allocationArray[$groupKey] = array(
                        'SECTION' => $subAllocation2['SECTION'],
                        'CO6' => $subAllocation2['CO6'],
                        'CO7' => $subAllocation2['CO7'],
                        'BOOK DATE' => $subAllocation2['BOOK DATE'],
                        'PARTY NAME' => $subAllocation2['PARTY NAME'],
                        'BILL DESC' => $subAllocation2['BILL DESC'],
                        'UNIQUE_WORK_ID' => $subAllocation2['UNIQUE_WORK_ID'],
                        $key => array($subAllocation2['DEBIT'], -$subAllocation2['CREDIT'])
                    );
                    $totals[$key] = round($totals[$key] + $subAllocation2['DEBIT'] - $subAllocation2['CREDIT'], 2);
                } else if ($subAllocation2['DEBIT'] != 0 && $subAllocation2['CREDIT'] == 0) {
                    $allocationArray[$groupKey] = array(
                        'SECTION' => $subAllocation2['SECTION'],
                        'CO6' => $subAllocation2['CO6'],
                        'CO7' => $subAllocation2['CO7'],
                        'BOOK DATE' => $subAllocation2['BOOK DATE'],
                        'PARTY NAME' => $subAllocation2['PARTY NAME'],
                        'BILL DESC' => $subAllocation2['BILL DESC'],
                        'UNIQUE_WORK_ID' => $subAllocation2['UNIQUE_WORK_ID'],
                        $key => array($subAllocation2['DEBIT'])
                    );
                    $totals[$key] = round($totals[$key] + $subAllocation2['DEBIT'], 2);
                } else if ($subAllocation2['DEBIT'] == 0 && $subAllocation2['CREDIT'] != 0) {
                    $allocationArray[$groupKey] = array(
                        'SECTION' => $subAllocation2['SECTION'],
                        'CO6' => $subAllocation2['CO6'],
                        'CO7' => $subAllocation2['CO7'],
                        'BOOK DATE' => $subAllocation2['BOOK DATE'],
                        'PARTY NAME' => $subAllocation2['PARTY NAME'],
                        'BILL DESC' => $subAllocation2['BILL DESC'],
                        'UNIQUE_WORK_ID' => $subAllocation2['UNIQUE_WORK_ID'],
                        $key => array(-$subAllocation2['CREDIT'])
                    );
                    $totals[$key] = round($totals[$key] - $subAllocation2['CREDIT'], 2);
                } else {
                    $allocationArray[$groupKey] = array(
                        'SECTION' => $subAllocation2['SECTION'],
                        'CO6' => $subAllocation2['CO6'],
                        'CO7' => $subAllocation2['CO7'],
                        'BOOK DATE' => $subAllocation2['BOOK DATE'],
                        'PARTY NAME' => $subAllocation2['PARTY NAME'],
                        'BILL DESC' => $subAllocation2['BILL DESC'],
                        'UNIQUE_WORK_ID' => $subAllocation2['UNIQUE_WORK_ID'],
                        $key => array(0)
                    );
                }
            }
        }
    }

    $rows = array();
    $total = 0;
    $c = 0;
    $sortedRows = array();
    foreach ($allocationArray as $row) {
        $uwidValue = isset($row['UNIQUE_WORK_ID']) ? trim((string) $row['UNIQUE_WORK_ID']) : '';
        $numericUwid = 0;
        if ($uwidValue !== '' && is_numeric($uwidValue)) {
            $numericUwid = (float) $uwidValue;
        }
        $sortedRows[] = array(
            'row' => $row,
            'uwid' => $numericUwid,
            'uwid_text' => $uwidValue,
        );
    }

    usort($sortedRows, function ($a, $b) {
        $aValue = $a['uwid'];
        $bValue = $b['uwid'];
        if ($aValue == $bValue) {
            return strcmp($a['uwid_text'], $b['uwid_text']);
        }
        return ($aValue < $bValue) ? -1 : 1;
    });

    foreach ($sortedRows as $entry) {
        $row = $entry['row'];
        $c++;
        $rowValues = array();
        if (strpos($row['SECTION'], 'JV') !== false) {
            $rowValues[] = $c . ' JV - ' . $row['CO6'] . ' Dt: ' . $row['BOOK DATE'];
        } else {
            $rowValues[] = $c . ' ' . $row['SECTION'] . ' - ' . substr($row['CO6'], -4) . '/' . substr($row['CO7'], -4) . ' Dt: ' . $row['BOOK DATE'];
        }

        $rowValues[] = $row['BILL DESC'] . ' M/S ' . $row['PARTY NAME'];
        $rowValues[] = $row['UNIQUE_WORK_ID'];

        $subTotal = 0;
        foreach ($subAllocation as $k => $v) {
            if (array_key_exists($k, $row)) {
                if (is_array($row[$k])) {
                    $values = array();
                    foreach ($row[$k] as $value) {
                        $values[] = $value;
                        $subTotal += $value;
                    }
                    $rowValues[] = implode("\n", $values);
                } else {
                    $rowValues[] = $row[$k];
                    $subTotal += $row[$k];
                }
            } else {
                $rowValues[] = '---';
            }
        }

        $rowValues[] = number_format($subTotal, 2, '.', '');
        $rows[] = $rowValues;
        $total += $subTotal;
    }

    $totals['Total'] = number_format($total, 2, '.', '');
    return array('rows' => $rows, 'totals' => $totals);
}

function cleanExcelCellValue($value)
{
    if (is_array($value)) {
        $value = implode("\n", array_filter(array_map(function ($item) {
            return trim((string) $item);
        }, $value), function ($item) {
            return $item !== '';
        }));
    } else {
        $value = trim((string) $value);
    }

    $value = preg_replace('/\R\s*\R+/', "\n", $value);
    $value = preg_replace('/[\t\x0B\f]/', ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);

    return $value;
}

function getExcelColumnName($index)
{
    $column = '';
    while ($index > 0) {
        $index--; 
        $column = chr(ord('A') + ($index % 26)) . $column;
        $index = intdiv($index, 26);
    }
    return $column;
}
?>
