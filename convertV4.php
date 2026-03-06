<html>

<head>
    <title>Day Book</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <script src="https://unpkg.com/sweetalert/dist/sweetalert.min.js"></script>
    <!-- Load XLSX library with multiple CDN fallbacks -->
    <script src="https://unpkg.com/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script>
        // Fallback loader if unpkg fails
        if (typeof XLSX === 'undefined') {
            var script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js';
            document.head.appendChild(script);
            script.onerror = function() {
                console.error('Failed to load XLSX from all CDNs. Excel export will not work.');
            };
        }
    </script>
    <style>
        .data thead tr th {
            width: 115px;
            border: 1px solid black !important;
            text-align: center;
        }

        .data tbody tr td {
            width: 115px;
            border: 1px solid black;
        }

        .table-bordered tbody tr td,
        .table-bordered thead tr th {
            border: 1px solid black !important;
        }
    </style>
</head>

<body>
    <div style="margin-left:50px; margin-right:50px;">
        <p style='text-align:right; font-size:24px;'><b><a href="index.php">GO BACK</a></b></p>
        <?php
        /**
         * ALLOCATION DAY BOOK CONVERTER
         * 
         * Purpose: Convert allocation data from BOOK.xlsx into organized day book tables
         *          grouped by allocation code and sub-allocation, with distinct rows per UEID.
         * 
         * Key Logic:
         * - Reads BOOK.xlsx file containing allocation and transaction data
         * - Groups data by ALLOCATION CODE (first level) and SUB-ALLOCATION (second level)
         * - Within each sub-allocation, rows are grouped by CO6 + UNIQUE_WORK_ID (UEID)
         *   If UEID is different, rows remain separate even with same CO6
         * - Calculates subtotals and grand totals for each allocation
         * 
         * Data Structure:
         * $allocations[$code][$subAllocationCode] = array of transactions
         *   where each transaction has SECTION, CO6, CO7, DATE, PARTY, BILL DESC, DEBIT, CREDIT, UEID
         */
        require 'vendor/autoload.php';

        use PhpOffice\PhpSpreadsheet\Spreadsheet;

        try {
            // Load Excel file and read sheet data
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $spreadsheet = $reader->load("BOOK.xlsx");
            $sheetData = $spreadsheet->getActiveSheet()->toArray();

            $i = 0;
            unset($sheetData[0]); // Skip header row
            
            // Initialize storage arrays
            $allocations = array(); // Nested array: allocation => subAllocation => transactions
            $grandTotal = array();  // Store totals per allocation for summary
            $subAllocationCode = "";
            $dayBookFor = "";
            $code = "";
            $heading = $sheetData[1][0]; // Document header (e.g., month/year)

            /**
             * PARSE EXCEL DATA
             * Extract allocation headers and transaction rows
             */
            foreach ($sheetData as $t) {
                // Detect ALLOCATION header row
                if (strpos($t[0], 'ALLOCATION ') !== false) {
                    $code = substr($t[0], 13, 4); // Extract 4-char code (e.g., "01-1234")
                    $subAllocationCode = substr($t[0], 13, 8); // Extract 8-char code with sub-allocation
                    
                    // Only process allocations within specified code ranges
                    if (substr($code, 2, 4) < 66 || substr($code, 2, 4) == 81 || substr($code, 2, 4) == 83) {
                        if (array_key_exists($code, $allocations)) {
                            $allocations[$code][$subAllocationCode] = array();
                        } else {
                            $allocations[$code] = array($subAllocationCode => []);
                        }
                    }

                    if ($dayBookFor === "") {
                        $dayBookFor = substr($t[0], 13, 2); // Extract allocation code prefix
                    }
                } 
                // Detect data rows (exclude headers and SYS-GENERATED entries)
                else if ($t[0] != "SECTION" && $t[0] != "" && strpos($t[4], "SYS-GENERATED") === false && 
                         $code != "" && $subAllocationCode != "" && 
                         (substr($code, 2, 4) < 66 || substr($code, 2, 4) == 81 || substr($code, 2, 4) == 83)) {
                    
                    // Add transaction to allocation
                    array_push(
                        $allocations[$code][$subAllocationCode],
                        array(
                            "SECTION" => $t[0],
                            "CO6" => $t[1],
                            "CO7" => $t[2],
                            "BOOK DATE" => $t[3],
                            "PARTY NAME" => $t[4],
                            "BILL DESC" => $t[5],
                            "DEBIT" => $t[6],
                            "CREDIT" => $t[7],
                            "UNIQUE_WORK_ID" => isset($t[11]) ? $t[11] : "" // UEID from column 12
                        )
                    );
                }
                $i++;
            }

            /**
             * RENDER ALLOCATION TABLES
             * Generate HTML table for each allocation code
             */
            foreach ($allocations as $allocation => $subAllocation) {
                ?>
                <div id="<?php echo 'table' . substr($allocation, 0, 2) . "-" . substr($allocation, 2, 4); ?>">
                    <?php echo "<h3><u> DAY BOOK " . substr($heading, 22) . " FOR THE ALLOCATION " . substr($allocation, 0, 2) . "-" . substr($allocation, 2, 4) . " </u></h3>"; ?>
                    <table class="table table-striped table-bordered" style=" font-size:12px;">
                        <thead style='font-size:14px;'>
                            <tr>
                                <th style="width:180px;">Sec. - CO6 / CO7</th>
                                <!--<th style="width:110px;">DATE </th>-->
                                <th>BILL DESC / PARTY NAME </th>
                                <th>UWID </th>
                                <?php $total = array();
                                foreach ($subAllocation as $k => $v) {

                                    echo "<th style='width:90px;'>" . $k . "</th>";

                                    $total[$k] = 0;
                                } ?>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            /**
                             * BUILD ALLOCATION ARRAY
                             * Group transactions by CO6 + UNIQUE_WORK_ID (UEID) within each sub-allocation
                             * 
                             * Key: If two rows have same CO6 but different UEID, they appear as separate entries
                             *      If two rows have same CO6 and same (or both empty) UEID, values accumulate
                             */
                            $allocationArray = array();
                            foreach ($allocations[$allocation] as $key => $subAllocations) {
                                foreach ($subAllocations as $subAllocation2) {
                                    // Create composite grouping key: CO6 + UEID (if UEID present)
                                    $groupKey = $subAllocation2['CO6'];
                                    if (!empty($subAllocation2['UNIQUE_WORK_ID'])) {
                                        $groupKey = $subAllocation2['CO6'] . '|' . $subAllocation2['UNIQUE_WORK_ID'];
                                    }

                                    // Accumulate values if group already exists, otherwise create new entry
                                    if (array_key_exists($groupKey, $allocationArray)) {
                                        // Group exists: accumulate debit/credit values
                                        if ($subAllocation2["CREDIT"] != 0 && $subAllocation2["DEBIT"] != 0) {
                                            if (array_key_exists($key, $allocationArray[$groupKey])) {
                                                $allocationArray[$groupKey][$key][] = $subAllocation2["DEBIT"];
                                                $allocationArray[$groupKey][$key][] = -$subAllocation2["CREDIT"];
                                            } else {
                                                $allocationArray[$groupKey][$key] = array($subAllocation2["DEBIT"], -$subAllocation2["CREDIT"]);
                                            }
                                            $total[$key] = round($total[$key] + $subAllocation2["DEBIT"] - $subAllocation2["CREDIT"], 2);
                                        } else if ($subAllocation2["DEBIT"] != 0 && $subAllocation2["CREDIT"] == 0) {
                                            if (array_key_exists($key, $allocationArray[$groupKey])) {
                                                $allocationArray[$groupKey][$key][] = $subAllocation2["DEBIT"];
                                            } else {
                                                $allocationArray[$groupKey][$key] = array($subAllocation2["DEBIT"]);
                                            }
                                            $total[$key] = round($total[$key] + $subAllocation2["DEBIT"], 2);
                                        } else if ($subAllocation2["DEBIT"] == 0 && $subAllocation2["CREDIT"] != 0) {
                                            if (array_key_exists($key, $allocationArray[$groupKey])) {
                                                $allocationArray[$groupKey][$key][] = -$subAllocation2["CREDIT"];
                                            } else {
                                                $allocationArray[$groupKey][$key] = array(-$subAllocation2["CREDIT"]);
                                            }
                                            $total[$key] = round($total[$key] - $subAllocation2["CREDIT"], 2);
                                        } else {
                                            if (!array_key_exists($key, $allocationArray[$groupKey])) {
                                                $allocationArray[$groupKey][$key] = array(0);
                                            }
                                        }
                                    } else {
                                        // New group: create entry with transaction details
                                        if ($subAllocation2["DEBIT"] != 0 && $subAllocation2["CREDIT"] != 0) {
                                            $allocationArray[$groupKey] = array(
                                                "SubAllocation" => $key,
                                                "SECTION" => $subAllocation2["SECTION"],
                                                "CO6" => $subAllocation2["CO6"],
                                                "CO7" => $subAllocation2["CO7"],
                                                "BOOK DATE" => $subAllocation2["BOOK DATE"],
                                                "PARTY NAME" => $subAllocation2["PARTY NAME"],
                                                "BILL DESC" => $subAllocation2["BILL DESC"],
                                                "UNIQUE_WORK_ID" => $subAllocation2["UNIQUE_WORK_ID"],
                                                $key => array($subAllocation2["DEBIT"], -$subAllocation2["CREDIT"])
                                            );
                                            $total[$key] = round($total[$key] + $subAllocation2["DEBIT"] - $subAllocation2["CREDIT"], 2);
                                        } else if ($subAllocation2["DEBIT"] != 0 && $subAllocation2["CREDIT"] == 0) {
                                            $allocationArray[$groupKey] = array(
                                                "SubAllocation" => $key,
                                                "SECTION" => $subAllocation2["SECTION"],
                                                "CO6" => $subAllocation2["CO6"],
                                                "CO7" => $subAllocation2["CO7"],
                                                "BOOK DATE" => $subAllocation2["BOOK DATE"],
                                                "PARTY NAME" => $subAllocation2["PARTY NAME"],
                                                "BILL DESC" => $subAllocation2["BILL DESC"],
                                                "UNIQUE_WORK_ID" => $subAllocation2["UNIQUE_WORK_ID"],
                                                $key => array($subAllocation2["DEBIT"])
                                            );
                                            $total[$key] = round($total[$key] + $subAllocation2["DEBIT"], 2);
                                        } else if ($subAllocation2["DEBIT"] == 0 && $subAllocation2["CREDIT"] != 0) {
                                            $allocationArray[$groupKey] = array(
                                                "SubAllocation" => $key,
                                                "SECTION" => $subAllocation2["SECTION"],
                                                "CO6" => $subAllocation2["CO6"],
                                                "CO7" => $subAllocation2["CO7"],
                                                "BOOK DATE" => $subAllocation2["BOOK DATE"],
                                                "PARTY NAME" => $subAllocation2["PARTY NAME"],
                                                "BILL DESC" => $subAllocation2["BILL DESC"],
                                                "UNIQUE_WORK_ID" => $subAllocation2["UNIQUE_WORK_ID"],
                                                $key => array(-$subAllocation2["CREDIT"])
                                            );
                                            $total[$key] = round($total[$key] - $subAllocation2["CREDIT"], 2);
                                        } else {
                                            $allocationArray[$groupKey] = array(
                                                "SubAllocation" => $key,
                                                "SECTION" => $subAllocation2["SECTION"],
                                                "CO6" => $subAllocation2["CO6"],
                                                "CO7" => $subAllocation2["CO7"],
                                                "BOOK DATE" => $subAllocation2["BOOK DATE"],
                                                "PARTY NAME" => $subAllocation2["PARTY NAME"],
                                                "BILL DESC" => $subAllocation2["BILL DESC"],
                                                "UNIQUE_WORK_ID" => $subAllocation2["UNIQUE_WORK_ID"],
                                                $key => array(0)
                                            );
                                        }
                                    }
                                }
                            }
                            $total["Total"] = 0;
                            $c = 0;
                            foreach ($allocationArray as $row) {
                                echo "<tr style='border:1px solid black;'>";
                                if (strpos($row["SECTION"], 'JV') !== false) {
                                    echo "<td>" . ++$c . " JV - " . $row["CO6"] . "<br/>Dt: " . $row["BOOK DATE"] . "</td>";
                                } else {
                                    echo "<td>" . ++$c . " " . $row["SECTION"] . " - " . substr($row["CO6"], -4) . " / " . substr($row["CO7"], -4) . "<br/>Dt: " . $row["BOOK DATE"] . "</td>";
                                }
                                // echo "<td>".$row["BOOK DATE"]."</td>";
                                echo "<td>";
                                echo $row["BILL DESC"] . " M/S " . $row["PARTY NAME"] . "<br/>";
                                /*END UEID PRINT*/
                                echo "<br/><br/><br/></td>";
                                echo "<td>";
                                echo $row["UNIQUE_WORK_ID"];
                                echo "<br/><br/><br/></td>";
                                $subTotal = 0;
                                foreach ($subAllocation as $k => $v) {
                                    if (array_key_exists($k, $row)) {
                                        if (is_array($row[$k]) != 1) {
                                            echo "<td class='text-right'>" . $row[$k] . "</td>";
                                        } else if ($k !== "") {
                                            echo "<td class='text-right'>";
                                            // Display all values in the array with <br/> between them
                                            $first = true;
                                            foreach ($row[$k] as $value) {
                                                if (!$first) {
                                                    echo "<br/>";
                                                }
                                                echo $value;
                                                $first = false;
                                            }
                                            echo "</td>";
                                        }
                                        // Calculate subtotal - sum all values in the array
                                        if (is_array($row[$k])) {
                                            foreach ($row[$k] as $value) {
                                                $subTotal = $subTotal + bcadd($value, '0', 2);
                                            }
                                        } else {
                                            $subTotal = $subTotal + bcadd($row[$k][0], '0', 2);
                                        }
                                    } else {
                                        echo "<td class='text-right'>---</td>";
                                    }
                                }
                                $total["Total"] = $total["Total"] + $subTotal;
                                // Below code handles the exponentially generated 0 value;
                                // starts //
                                if (number_format($subTotal, 2) == 0) {
                                    $subTotal = number_format($subTotal, 2);
                                }

                                if (number_format($total["Total"], 2) == 0) {
                                    $total["Total"] = number_format($total["Total"], 2);
                                }
                                // ends //

                                echo "<td class='text-right'>" . $subTotal . "</td>";
                                echo "</tr>";
                            }
                            echo "<tr>";
                            //echo "<td>&nbsp;</td>";
                            echo "<td colspan='3'><b>TOTAL</b></td>";
                            foreach ($subAllocation as $k => $v) {
                                if (array_key_exists($k, $total)) {
                                    echo "<td class='text-right'><b>" . $total[$k] . "</b></td>";
                                } else {
                                    echo "<td class='text-right'><b>0.00</b></td>";
                                }
                            }
                            echo "<td class='text-right'><b>" . $total["Total"] . "</b></td>";
                            echo "</tr>";
                            $grandTotal["Total" . substr($allocation, 0, 2) . "-" . substr($allocation, 2, 4)] = $total["Total"];
                            ?>

                        </tbody>
                    </table>
                </div>
                <div class='text-center'>
                    <button id="<?php echo substr($allocation, 0, 2) . '-' . substr($allocation, 2, 4); ?>-pdf" class="btn btn-primary btn-sm" type="button" title="Print this allocation table as PDF">PRINT PDF</button>
                    <button id="<?php echo substr($allocation, 0, 2) . '-' . substr($allocation, 2, 4); ?>-excel" class="btn btn-info btn-sm" type="button" title="Export this allocation table as Excel">PRINT EXCEL</button>
                </div></br />
            <?php
            }
            ?>
            <br />
            <div>
                <div id="tableSummary">
                    <h3>Day Book Summary For Allocation Code: <?php echo $dayBookFor;
                                                                $forTheMonthTotal = 0 ?> </h3><br />
                    <table class="table table-striped table-bordered" id="tableBody" style="width:40%; font-size:16px;">
                        <thead style="font-size:18px;">
                            <tr>
                                <th class='text-right'>Sub-Allocations</th>
                                <th class="text-right last">Last Month</th>
                                <th class='text-right'>For The Month</th>
                                <th class='text-right'>To The Month</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $count = 0;
                            foreach ($allocations as $allocation => $subAllocation) {
                                $count++; ?>
                                <tr>
                                    <td class='text-right'><?php echo substr($allocation, 0, 2) . "-" . substr($allocation, 2, 4); ?></td>
                                    <td class='text-right last'><input class='text-right lastInput' id="<?php echo str_pad($count, 2, '0', STR_PAD_LEFT) . "Last"; ?>" type='number' value="0.00" /></td>
                                    <td class='text-right' id="<?php echo str_pad($count, 2, '0', STR_PAD_LEFT) . "FOR"; ?>"><?php echo $grandTotal["Total" . substr($allocation, 0, 2) . "-" . substr($allocation, 2, 4)];
                                                                                                $forTheMonthTotal = $forTheMonthTotal + $grandTotal["Total" . substr($allocation, 0, 2) . "-" . substr($allocation, 2, 4)]; ?></td>
                                    <td class='text-right toEnd' id="<?php echo str_pad($count, 2, '0', STR_PAD_LEFT) . "TOEND"; ?>"><?php echo $grandTotal["Total" . substr($allocation, 0, 2) . "-" . substr($allocation, 2, 4)]; ?></td>
                                </tr>
                            <?php } ?>
                            <tr id='lastRow'>
                                <td class='text-right'><b>TOTAL</b></td>
                                <td class='text-right last'><input class='text-right' type='number' value="0.00" id="totalLastMonth" disabled /></td>
                                <td class='text-right'><?php echo $forTheMonthTotal; ?></td>
                                <td class='text-right' id="totalToEnd"><?php echo $forTheMonthTotal; ?></td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="row" style="margin-top:80px; margin-bottom:40px;">
                        <div class="col-lg-2 col-xs-2">AC / SE (IT)</div>
                        <div class="col-lg-2 col-xs-2">SSO (Books)</div>
                        <div class="col-lg-2 col-xs-2">ADFM / SrDFM RJT</div>
                    </div>
                </div>
                <div class="btn-toolbar" style="margin: 20px 0;">
                    <div class="btn-group" role="group" aria-label="Row Management">
                        <button id="addRow" class="btn btn-info btn-sm" type="button" title="Add a new row to the summary table">Add Row</button>
                        <button id="deleteRow" class="btn btn-warning btn-sm" type="button" title="Delete the last added row from the summary table">Delete Row</button>
                    </div>
                </div>
                <div class="btn-toolbar" style="margin: 15px 0;">
                    <div class="btn-group" role="group" aria-label="PDF Export">
                        <button id="Summary" class="btn btn-primary" type="button" title="Print summary table as PDF">PRINT PDF</button>
                        <button id="PrintAll" class="btn btn-success" type="button" title="Print all allocation tables and summary as PDF in one document">PRINT ALL PDF</button>
                    </div>
                </div>
                <div class="btn-toolbar" style="margin: 15px 0;">
                    <div class="btn-group" role="group" aria-label="Excel Export">
                        <button id="ExcelSummary" class="btn btn-info" type="button" title="Export summary table as Excel">EXPORT EXCEL</button>
                        <button id="ExcelAll" class="btn btn-success" type="button" title="Export all allocation tables and summary as Excel">EXPORT ALL EXCEL</button>
                    </div>
                </div>
                <p style='text-align:center; font-size:24px;'><b><a href="index.php">BACK</a></b></p>
            </div>
        <?php } catch (Exception $e) {
            echo $e->getMessage(); ?>
            <script type='text/javascript'>
                swal({
                    title: "File Not Found",
                    text: "Plz upload suspensehead (.xlsx ) and ueid mapping (.txt) file first ",
                    icon: "error",
                    button: "Ok!",
                }).then(() => {
                    window.location.href = "index.php";
                });
            </script>
        <?php } ?>
    </div>
    <script type='text/javascript'>
        /**
         * GLOBAL VARIABLES & INITIALIZATION
         */
        var rowCount = '<?php echo $count; ?>'; // Counter for dynamically added summary table rows

        /**
         * UTILITY FUNCTIONS
         */

        /**
         * Recalculate summary totals when Last Month input changes
         * Computes "To End Of Month" = Last Month + For The Month
         * 
         * @param {string} id - The input field ID (format: "##LAST")
         */
        function lastInputChange(id) {
            var key = id.substring(0, 2); // Extract allocation code prefix
            var totalLastVal = 0.00;
            var totalToEnd = 0.00;
            
            // Calculate "To End" value for this allocation
            var toEnd = parseFloat($("#" + id).val()) + parseFloat($("#" + key + "FOR").html());
            $("#" + key + "TOEND").html(toEnd.toFixed(2));

            // Recalculate total row (sum all Last Month and To End values)
            $(".lastInput").each(function() {
                totalLastVal = totalLastVal + parseFloat($("#" + this.id).val());
            });
            $(".toEnd").each(function() {
                totalToEnd = totalToEnd + parseFloat($("#" + this.id).html());
            });
            
            $("#totalLastMonth").val(parseFloat(totalLastVal).toFixed(2));
            $("#totalToEnd").html(totalToEnd.toFixed(2));
        }

        /**
         * Sort table rows by the first column (allocation code) in ascending or descending order
         * 
         * @param {jQuery} table - jQuery reference to table element
         * @param {string} order - 'asc' for ascending, 'desc' for descending
         */
        function sortTable(table, order) {
            var asc = order === 'asc';
            var tbody = table.find('tbody');

            tbody.find('tr').sort(function(a, b) {
                var aText = $('td:first', a).text();
                var bText = $('td:first', b).text();
                return asc ? aText.localeCompare(bText) : bText.localeCompare(aText);
            }).appendTo(tbody);
        }

        /**
         * Create and show a print preview by loading HTML into a hidden iframe
         * 
         * @param {string} htmlContent - The HTML content to print
         * @param {string} title - The print document title
         */
        function printDocument(htmlContent, title) {
            var frame = $('<iframe />');
            frame[0].name = 'printFrame';
            frame.css({ position: 'absolute', top: '-1000000px' });
            $('body').append(frame);

            // Cross-browser iframe document access
            var frameDoc = frame[0].contentWindow ? 
                frame[0].contentWindow : 
                frame[0].contentDocument.document ? 
                frame[0].contentDocument.document : 
                frame[0].contentDocument;

            frameDoc.document.open();
            frameDoc.document.write('<html><head><title>' + title + '</title>');
            frameDoc.document.write('<link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css" rel="stylesheet" type="text/css" />');
            frameDoc.document.write('<style>');
            frameDoc.document.write('table{border-collapse:collapse;}');
            frameDoc.document.write('.table-bordered td, .table-bordered th{border:1px solid #000 !important;}');
            frameDoc.document.write('@media print { .page-break{page-break-before:always;} }');
            frameDoc.document.write('</style>');
            frameDoc.document.write('</head><body>');
            frameDoc.document.write(htmlContent);
            frameDoc.document.write('</body></html>');
            frameDoc.document.close();

            // Trigger print after brief delay to ensure content is rendered
            setTimeout(function() {
                window.frames['printFrame'].focus();
                window.frames['printFrame'].print();
                frame.remove();
            }, 500);
        }

        /**
         * Create a formatted Excel worksheet from data array
         * Properly builds cell objects with formatting, borders, colors, and fonts
         * 
         * @param {Array} ws_data - 2D array of worksheet data
         * @param {number} headerRowIndex - Row index where headers start (0-based)
         * @param {string} titleText - Optional title text
         * @returns {Object} Formatted XLSX worksheet
         */
        function createFormattedWorksheet(ws_data, headerRowIndex, titleText) {
            if (!ws_data || ws_data.length === 0) return XLSX.utils.aoa_to_sheet([]);

            var ws = {};
            
            // Define formatting styles
            var titleFill = { fgColor: { rgb: 'FF1F4E78' } }; // Darker blue
            var titleFont = { bold: true, color: { rgb: 'FFFFFFFF' }, size: 14 };
            var titleAlignment = { horizontal: 'left', vertical: 'center', wrapText: true };
            
            var headerFill = { fgColor: { rgb: 'FF4472C4' } }; // Lighter blue
            var headerFont = { bold: true, color: { rgb: 'FFFFFFFF' }, size: 11 };
            var headerAlignment = { horizontal: 'center', vertical: 'center', wrapText: true };
            
            var dataAlignment = { horizontal: 'left', vertical: 'center', wrapText: false };
            var numberAlignment = { horizontal: 'right', vertical: 'center', wrapText: false };
            
            var borderStyle = { style: 'thin', color: { rgb: 'FF000000' } };
            var borders = { 
                top: borderStyle, 
                bottom: borderStyle, 
                left: borderStyle, 
                right: borderStyle 
            };

            // Calculate column widths
            var colWidths = [];
            var numCols = 0;
            ws_data.forEach(function(row) {
                numCols = Math.max(numCols, row.length);
                row.forEach(function(cell, colIdx) {
                    var cellLength = String(cell).length;
                    colWidths[colIdx] = Math.max(colWidths[colIdx] || 12, Math.min(cellLength + 3, 50));
                });
            });

            // Build worksheet with formatted cells
            for (var R = 0; R < ws_data.length; R++) {
                for (var C = 0; C < numCols; C++) {
                    var cellAddress = XLSX.utils.encode_col(C) + XLSX.utils.encode_row(R);
                    var cellValue = ws_data[R][C];
                    
                    // Create cell object with value and type
                    var cellObj = {
                        v: cellValue,
                        t: 's' // Default to string
                    };

                    // Determine if value is numeric
                    var numValue = Number(cellValue);
                    var isNumeric = !isNaN(numValue) && cellValue !== '' && cellValue !== null;
                    
                    if (isNumeric) {
                        cellObj.t = 'n';
                        cellObj.v = numValue;
                        
                        // Apply number formatting
                        if (String(cellValue).includes('.')) {
                            cellObj.numFmt = '0.00';
                        } else {
                            cellObj.numFmt = '0';
                        }
                    } else {
                        cellObj.t = 's';
                        cellObj.v = String(cellValue);
                    }

                    // Apply borders to all cells
                    cellObj.border = borders;

                    // Title row formatting
                    if (titleText && R === 0) {
                        cellObj.fill = titleFill;
                        cellObj.font = titleFont;
                        cellObj.alignment = titleAlignment;
                    }
                    // Spacing row
                    else if (titleText && R === 1) {
                        cellObj.border = borders;
                    }
                    // Header row formatting
                    else if (R === headerRowIndex) {
                        cellObj.fill = headerFill;
                        cellObj.font = headerFont;
                        cellObj.alignment = headerAlignment;
                    }
                    // Data rows
                    else if (R > headerRowIndex) {
                        cellObj.alignment = isNumeric ? numberAlignment : dataAlignment;
                    }

                    ws[cellAddress] = cellObj;
                }
            }

            // Set worksheet range
            if (numCols > 0 && ws_data.length > 0) {
                ws['!ref'] = 'A1:' + XLSX.utils.encode_col(numCols - 1) + XLSX.utils.encode_row(ws_data.length - 1);
            }

            // Set column widths
            ws['!cols'] = colWidths.map(function(width) {
                return { wch: width };
            });

            // Set row heights
            ws['!rows'] = [];
            if (titleText) {
                ws['!rows'][0] = { hpt: 28 }; // Title row height
                ws['!rows'][1] = { hpt: 5 };  // Spacing row
                ws['!rows'][2] = { hpt: 25 }; // Header row
            } else {
                ws['!rows'][0] = { hpt: 25 }; // Header row
            }
            
            // Data rows
            for (var i = headerRowIndex + 1; i < ws_data.length; i++) {
                ws['!rows'][i] = { hpt: 18 };
            }

            return ws;
        }

        /**
         * Apply Excel formatting to worksheet cells
         * Handles borders, colors, fonts, alignment, and cell values
         * 
         * @param {Object} ws - XLSX worksheet object
         * @param {Array} ws_data - 2D array of worksheet data
         * @param {number} headerRowIndex - Row index where actual headers start (0-based)
         * @param {string} titleText - Title text if added
         */
        function applyExcelFormatting(ws, ws_data, headerRowIndex, titleText) {
            // This function is now deprecated - use createFormattedWorksheet instead
            // But keeping for compatibility
            return ws;
        }

        /**
         * Export table data to Excel workbook format with professional formatting
         * Includes title row, styled headers, borders, and proper column widths
         * 
         * @param {string} tableHtml - HTML table content to convert
         * @param {string} sheetName - Excel sheet name (max 31 chars)
         * @param {string} fileName - Output file name (without .xlsx)
         * @param {string} titleText - Optional title to display above table
         */
        function exportToExcel(tableHtml, sheetName, fileName, titleText) {
            console.log('exportToExcel called with:', sheetName, fileName);
            
            // Check if XLSX library is loaded
            if (typeof XLSX === 'undefined') {
                alert('Excel export library not loaded. Please refresh the page and try again.');
                console.error('XLSX library is not available');
                return;
            }
            
            try {
                // Create a temporary div and set the HTML to parse
                var tmpDiv = $('<div>' + tableHtml + '</div>');
                var table = tmpDiv.find('table').first();
                
                if (table.length === 0) {
                    alert('No table found to export');
                    console.error('No table found in HTML');
                    return;
                }

                // Parse table to array format
                var ws_data = [];
                
                // Add title row if provided
                if (titleText) {
                    ws_data.push([titleText]);
                    ws_data.push([]); // Empty row for spacing
                }
                
                table.find('tr').each(function() {
                    var rowData = [];
                    $(this).find('th, td').each(function() {
                        rowData.push($(this).text().trim());
                    });
                    ws_data.push(rowData);
                });

                console.log('Parsed rows:', ws_data.length);

                // Create worksheet with proper formatting (includes borders, colors, fonts)
                var dataStartRow = titleText ? 2 : 0;
                var ws = createFormattedWorksheet(ws_data, dataStartRow, titleText);
                
                var wb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, sheetName.substring(0, 31));

                // Download the file
                var timestamp = new Date().toISOString().split('T')[0];
                var downloadFileName = fileName + '_' + timestamp + '.xlsx';
                XLSX.writeFile(wb, downloadFileName);
                console.log('Excel file exported:', downloadFileName);
            } catch (e) {
                console.error('Error exporting to Excel:', e);
                alert('Error exporting to Excel: ' + e.message);
            }
        }

        /**
         * Export multiple tables to a single Excel workbook with multiple sheets
         * Each sheet features professional formatting with styled headers and borders
         * 
         * @param {Array} tables - Array of {html, name} objects
         * @param {string} fileName - Output file name (without .xlsx)
         */
        function exportMultipleTablesToExcel(tables, fileName) {
            console.log('exportMultipleTablesToExcel called with', tables.length, 'tables');
            
            // Check if XLSX library is loaded
            if (typeof XLSX === 'undefined') {
                alert('Excel export library not loaded. Please refresh the page and try again.');
                console.error('XLSX library is not available');
                return;
            }
            
            try {
                var wb = XLSX.utils.book_new();

                tables.forEach(function(tableObj) {
                    var tmpDiv = $('<div>' + tableObj.html + '</div>');
                    var table = tmpDiv.find('table').first();
                    
                    if (table.length > 0) {
                        var ws_data = [];
                        
                        // Add title row
                        ws_data.push([tableObj.name]);
                        ws_data.push([]); // Spacing row
                        
                        table.find('tr').each(function() {
                            var rowData = [];
                            $(this).find('th, td').each(function() {
                                rowData.push($(this).text().trim());
                            });
                            ws_data.push(rowData);
                        });

                        // Create worksheet with proper formatting
                        var ws = createFormattedWorksheet(ws_data, 2, tableObj.name);
                        
                        XLSX.utils.book_append_sheet(wb, ws, tableObj.name.substring(0, 31));
                        console.log('Added sheet:', tableObj.name);
                    }
                });

                // Download the file
                var timestamp = new Date().toISOString().split('T')[0];
                var downloadFileName = fileName + '_' + timestamp + '.xlsx';
                XLSX.writeFile(wb, downloadFileName);
                console.log('Excel file exported:', downloadFileName);
            } catch (e) {
                console.error('Error exporting to Excel:', e);
                alert('Error exporting to Excel: ' + e.message);
            }
        }

        /**
         * EVENT HANDLERS
         */

        /**
         * Handle Last Month input field changes to recalculate totals
         */
        $(".lastInput").change(function() {
            var id = this.id;
            lastInputChange(id);
        });

        /**
         * Document ready: Initialize all interactive elements
         */
        $(function() {

            /**
             * Add Row Button: Dynamically insert a new summary table row
             */
            $("#addRow").click(function() {
                rowCount++;
                rowCount = String(rowCount).padStart(2, '0');
                var lastId = rowCount + "LAST";
                var forId = rowCount + "FOR";
                var toEndId = rowCount + 'TOEND';

                // Build and insert new row before the total row
                var newRow = '<tr id="customTr' + rowCount + '">' +
                    '<td class="text-right customAllocation" style="height:36px;" id="newAllocation' + rowCount + '">' +
                    '<input class="text-right newAllocation" type="text" maxlength="5" style="width:50px;" id="newAllocation' + rowCount + 'Value"/>' +
                    '</td>' +
                    '<td class="text-right last">' +
                    '<input onchange="lastInputChange(\'' + lastId + '\')" class="text-right lastInput" id="' + rowCount + 'LAST" type="number" value="0.00"/>' +
                    '</td>' +
                    '<td class="text-right" id="' + rowCount + 'FOR">0.00</td>' +
                    '<td class="text-right toEnd" id="' + rowCount + 'TOEND">0.00</td>' +
                    '</tr>';

                $("#lastRow").before(newRow);
            });

            /**
             * Delete Row Button: Remove the last dynamically added row
             */
            $("#deleteRow").click(function() {
                if (rowCount > 0) {
                    $("#customTr" + rowCount).remove();
                    rowCount--;
                }
            });

            /**
             * PDF: Print Individual Allocation Table Button
             * Prints a single allocation as PDF
             */
            $("button[id$='-pdf']").click(function() {
                var allocCode = this.id.replace('-pdf', '');
                var tableId = "#table" + allocCode;

                // Hide Last Month column for cleaner print
                $(".last").hide();

                // Preserve custom allocation inputs before printing
                var customValues = new Map();
                $(".customAllocation").each(function() {
                    customValues.set(this.id, $("#" + this.id).html());
                    $("#" + this.id).html($("#" + this.id + "Value").val());
                });

                // Get the allocation table to print
                var tableContent = $(tableId).html();

                // Sort summary table for consistent output
                sortTable($("#tableBody"), 'asc');

                // Print the document
                printDocument(tableContent, 'Allocation Day Book - ' + allocCode);

                // Restore UI state
                setTimeout(function() {
                    $(".last").show();
                    for (const [key, value] of customValues.entries()) {
                        var displayValue = $("#" + key).text();
                        $("#" + key).html(value);
                        $("#" + key + "Value").val(displayValue);
                    }
                }, 600);
            });

            /**
             * EXCEL: Export Individual Allocation Table Button
             * Exports a single allocation as Excel
             */
            $("button[id$='-excel']").click(function(e) {
                e.preventDefault();
                var allocCode = this.id.replace('-excel', '');
                console.log('Individual excel export clicked for allocation:', allocCode);
                
                var tableId = "#table" + allocCode;
                var tableContent = $(tableId).html();
                
                if (!tableContent) {
                    console.warn('Table not found for allocation:', allocCode);
                    alert('Table not found for allocation: ' + allocCode);
                    return;
                }

                // Export to Excel with title
                exportToExcel(tableContent, 'Allocation ' + allocCode, 'DayBook_' + allocCode, 'Allocation ' + allocCode);
            });

            /**
             * PDF: Print Summary Only Button
             */
            $("#Summary").click(function() {
                // Hide Last Month column for cleaner print
                $(".last").hide();

                // Print the summary table
                printDocument($('#tableSummary').html(), 'Day Book Summary');

                // Restore UI state
                setTimeout(function() {
                    $(".last").show();
                }, 600);
            });

            /**
             * EXCEL: Export Summary Table Only Button
             */
            $("#ExcelSummary").click(function(e) {
                e.preventDefault();
                console.log('ExcelSummary button clicked');
                // Export summary table to Excel with title
                exportToExcel($('#tableSummary').html(), 'Summary', 'DayBook_Summary', 'Day Book Summary');
            });

            /**
             * PDF: Print All Allocations and Summary Button
             * Assemble and print all allocation tables with summary, each on new page
             */
            $("#PrintAll").click(function() {
                // Hide Last Month column for cleaner print
                $(".last").hide();

                // Preserve custom allocation inputs before printing
                var customValues = new Map();
                $(".customAllocation").each(function() {
                    customValues.set(this.id, $("#" + this.id).html());
                    $("#" + this.id).html($("#" + this.id + "Value").val());
                });

                // Assemble all allocation tables with page breaks
                var combinedHtml = '';
                
                // Collect all allocation tables (exclude summary)
                $("div[id^='table']").not('#tableSummary').each(function(index) {
                    // Add page break before each table except the first
                    if (index > 0) {
                        combinedHtml += '<div style="page-break-before:always;"></div>';
                    }
                    combinedHtml += '<div>' + $(this).html() + '</div>';
                });

                // Add summary table as the final page
                combinedHtml += '<div style="page-break-before:always;"></div>' + $('#tableSummary').html();

                // Print the combined document
                printDocument(combinedHtml, 'All Allocation Day Books');

                // Restore UI state
                setTimeout(function() {
                    $(".last").show();
                    for (const [key, value] of customValues.entries()) {
                        var displayValue = $("#" + key).text();
                        $("#" + key).html(value);
                        $("#" + key + "Value").val(displayValue);
                    }
                }, 600);
            });

            /**
             * EXCEL: Export All Allocations and Summary Button
             * Exports all tables to a multi-sheet Excel workbook
             */
            $("#ExcelAll").click(function(e) {
                e.preventDefault();
                console.log('ExcelAll button clicked');
                
                var tables = [];

                // Collect all allocation tables
                $("div[id^='table']").not('#tableSummary').each(function() {
                    var allocCode = this.id.replace('table', '');
                    tables.push({
                        html: $(this).html(),
                        name: 'Allocation_' + allocCode
                    });
                });

                // Add summary table as last sheet
                tables.push({
                    html: $('#tableSummary').html(),
                    name: 'Summary'
                });

                console.log('Total sheets to export:', tables.length);
                
                if (tables.length === 0) {
                    alert('No tables found to export');
                    console.warn('No tables found for export');
                    return;
                }

                // Export to Excel with multiple sheets
                exportMultipleTablesToExcel(tables, 'DayBook_All');
            });
        });
    </script>
</body>

</html>