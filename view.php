<html>

<head>
    <title>Day Book</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <script src="https://unpkg.com/sweetalert/dist/sweetalert.min.js"></script>
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
                    <button id="<?php echo substr($allocation, 0, 2) . '-' . substr($allocation, 2, 4); ?>" class="btn print-btn">PRINT</button>
                    <button type="button" class="btn btn-default export-btn" data-allocation="<?php echo substr($allocation, 0, 2) . '-' . substr($allocation, 2, 4); ?>">EXPORT EXCEL</button>
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
                    <div class="btn-group" role="group" aria-label="Print Actions">
                        <button id="Summary" class="btn btn-primary summary-print-btn" type="button" title="Print summary table only">PRINT SUMMARY</button>
                        <button id="PrintAll" class="btn btn-success" type="button" title="Print all allocation tables and summary in one document">PRINT ALL</button>
                        <button id="ExportAll" class="btn btn-info export-btn" type="button" data-export-url="exportDayBookExcel.php?mode=all">EXPORT ALL EXCEL</button>
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
             * Excel export buttons trigger workbook download
             */
            $(".export-btn").click(function(e) {
                e.preventDefault();
                var exportUrl = $(this).data('export-url');
                if (!exportUrl) {
                    exportUrl = 'exportDayBookExcel.php?mode=single&allocation=' + encodeURIComponent($(this).data('allocation'));
                }

                if ($(this).attr('id') === 'ExportAll') {
                    var summaryData = [];
                    $("#tableSummary tbody tr").not('#lastRow').each(function() {
                        var $row = $(this);
                        var label = $row.find('.newAllocation').val() || $row.find('td').first().text().trim();
                        var lastValue = $row.find('.lastInput').val() || '0.00';
                        var forValue = $row.find('td').eq(2).text().trim();
                        var toValue = $row.find('td').eq(3).text().trim();

                        summaryData.push({
                            label: label,
                            last: lastValue,
                            for: forValue,
                            to: toValue
                        });
                    });

                    var $totalRow = $('#lastRow');
                    summaryData.push({
                        label: 'TOTAL',
                        last: $('#totalLastMonth').val() || '0.00',
                        for: $totalRow.find('td').eq(2).text().trim(),
                        to: $totalRow.find('td').eq(3).text().trim()
                    });

                    exportUrl += '&summary=' + encodeURIComponent(JSON.stringify(summaryData));
                }

                window.location.href = exportUrl;
            });

            /**
             * Print Individual Table Button (allocation print buttons only)
             * Prints a single allocation table for the clicked button
             */
            $(".print-btn").click(function() {
                var buttonId = this.id;
                var tableId = "#table" + buttonId;

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
                printDocument(tableContent, 'Allocation Day Book');

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
             * Print Summary Button: Print only the summary table
             */
            $(".summary-print-btn").click(function() {
                $(".last").hide();

                var customValues = new Map();
                $(".customAllocation").each(function() {
                    customValues.set(this.id, $("#" + this.id).html());
                    $("#" + this.id).html($("#" + this.id + "Value").val());
                });

                var summaryContent = $('#tableSummary').html();
                printDocument(summaryContent, 'Day Book Summary');

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
             * Print All Button: Assemble and print all allocation tables with summary
             * Each allocation table starts on a new page, summary on final page
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
                $("div[id^='table']").not('#tableSummary').each(function(index) {
                    // Add page break before each table except the first
                    if (index > 0) {
                        combinedHtml += '<div style="page-break-before:always;"></div>';
                    }
                    combinedHtml += '<div>' + $(this).html() + '</div>';
                });

                // Append summary table as the final page
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
        });
    </script>
</body>

</html>