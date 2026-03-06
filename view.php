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
        require 'vendor/autoload.php';

        use PhpOffice\PhpSpreadsheet\Spreadsheet;

        try {

            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $spreadsheet = $reader->load("BOOK.xlsx");
            $sheetData = $spreadsheet->getActiveSheet()->toArray();

            $i = 0;
            unset($sheetData[0]);
            $allocations = array();
            $grandTotal = array();
            $subAllocationCode = "";
            $dayBookFor = "";
            $code = "";
            $heading = $sheetData[1][0];
            foreach ($sheetData as $t) {

                if (strpos($t[0], 'ALLOCATION ') !== false) {
                    $code = substr($t[0], 13, 4);
                    $allocationExist = false;
                    $subAllocationCode = substr($t[0], 13, 8);
                    if (substr($code, 2, 4) < 66 || substr($code, 2, 4) == 81 || substr($code, 2, 4) == 83) {
                        if (array_key_exists($code, $allocations)) {
                            $allocations[$code][$subAllocationCode] = array();
                        } else {
                            $allocations[$code] = array($subAllocationCode => []);
                        }
                    }

                    if ($dayBookFor === "") {
                        $dayBookFor = substr($t[0], 13, 2);
                    }
                } else if ($t[0] != "SECTION" && $t[0] != "" &&  strpos($t[4], "SYS-GENERATED") === false && $code != "" && $subAllocationCode != "" && (substr($code, 2, 4) < 66 || substr($code, 2, 4) == 81 || substr($code, 2, 4) == 83)) {
                     // NOTE: we now read UNIQUE_WORK_ID from column index 8 if present
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
                            "UNIQUE_WORK_ID" => isset($t[11]) ? $t[11] : ""   // <--- NEW
                        )
                    );
                }
                $i++;
            }

            foreach ($allocations as $allocation => $subAllocation) { ?>
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
                            $allocationArray = array();
                            foreach ($allocations[$allocation] as $key => $subAllocations) {
                                foreach ($subAllocations as $subAllocation2) {
                                    // Group by CO6 but keep rows distinct when UNIQUE_WORK_ID is present
                                    $groupKey = $subAllocation2['CO6'];
                                    if (!empty($subAllocation2['UNIQUE_WORK_ID'])) {
                                        $groupKey = $subAllocation2['CO6'] . '|' . $subAllocation2['UNIQUE_WORK_ID'];
                                    }

                                    // If this group already exists, accumulate into it; otherwise create it
                                    if (array_key_exists($groupKey, $allocationArray)) {
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
                                        // Create new grouped entry; store original CO6 and UNIQUE_WORK_ID inside
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
                <div class='text-center'><button id="<?php echo substr($allocation, 0, 2) . '-' . substr($allocation, 2, 4); ?>" class="btn">PRINT</button></div></br />
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
                <button id="Summary" class="btn">PRINT</button>
                <button id="PrintAll" class="btn">PRINT ALL</button></br /><br />
                <button id="addRow" class=" btn-sm">Add Row</button> &nbsp; | &nbsp; <button id="deleteRow" class=" btn-sm">Delete Row</button>
                <br />
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
        var rowCount = '<?php echo $count; ?>';

        function lastInputChange(id) {

            var key = id.substring(0, 2);
            var totalLastVal = 0.00,
                totalToEnd = 0.00;
            var toEnd = parseFloat($("#" + id).val()) + parseFloat($("#" + key + "FOR").html());

            $("#" + key + "TOEND").html(toEnd.toFixed(2));

            $(".lastInput").each(function() {
                totalLastVal = totalLastVal + parseFloat($("#" + this.id).val());
            });
            $(".toEnd").each(function() {
                totalToEnd = totalToEnd + parseFloat($("#" + this.id).html());
            });
            console.log(parseFloat(totalLastVal).toFixed(2));
            $("#totalLastMonth").val(parseFloat(totalLastVal).toFixed(2));
            $("#totalToEnd").html(totalToEnd.toFixed(2));

        }


        // NOTE the below function is being used when we have to sort the summary table based on the allocation number
        function sortTable(table, order) {
            var asc = order === 'asc',
                tbody = table.find('tbody');

            tbody.find('tr').sort(function(a, b) {
                if (asc) {
                    return $('td:first', a).text().localeCompare($('td:first', b).text());
                } else {
                    return $('td:first', b).text().localeCompare($('td:first', a).text());
                }
            }).appendTo(tbody);
            // return tbody;
        }

        // $(document).ready(function() {

        $(".lastInput").change(function() {

            var key = this.id.substring(0, 2);
            var totalLastVal = 0.00,
                totalToEnd = 0.00;
            var toEnd = parseFloat($("#" + this.id).val()) + parseFloat($("#" + key + "FOR").html());

            $("#" + key + "TOEND").html(toEnd.toFixed(2));

            $(".lastInput").each(function() {
                totalLastVal = totalLastVal + parseFloat($("#" + this.id).val());
            });
            $(".toEnd").each(function() {
                totalToEnd = totalToEnd + parseFloat($("#" + this.id).html());
            });
            $("#totalLastMonth").val(parseFloat(totalLastVal).toFixed(2));
            $("#totalToEnd").html(totalToEnd.toFixed(2));

        });
        $(function() {

            $("#addRow").click(function() {
                rowCount++;
                rowCount = String(rowCount).padStart(2, '0');
                var lastId = rowCount + "LAST";
                var forId = rowCount + "FOR";
                var toEndId = rowCount + 'TOEND';
                $("#lastRow").before("<tr id='customTr" + rowCount + "'>" +
                    "<td class='text-right customAllocation' style='height:36px;' id='newAllocation" + rowCount + "'><input class='text-right newAllocation' type='text' maxlength='5' style='width:50px;' id='newAllocation" + rowCount + "Value'/></td>" +
                    "<td class='text-right last'><input onchange=lastInputChange('" + lastId + "') class='text-right lastInput' id='" + rowCount + "LAST' type='number' value='0.00'/></td>" +
                    "<td class='text-right' id='" + rowCount + "FOR'>0.00</td>" +
                    "<td class='text-right toEnd' id='" + rowCount + "TOEND'>0.00</td>" +
                    "</tr>");
            });
            $("#deleteRow").click(function() {
                if (rowCount > 0) {
                    $("#customTr" + rowCount).remove();
                    rowCount--;
                }
            });

            $(".btn").not("#PrintAll").click(function() {
                $(".last").hide();

                var myMap = new Map();
                $(".customAllocation").each(function() {
                    myMap.set(this.id, $("#" + this.id).html());
                    $("#" + this.id).html($("#" + this.id + "Value").val());
                });

                
                var contents = $("#table" + this.id).html();

                // sorting based on allocation number
                sortTable($("#tableBody"), 'asc');
                contents = $("#table" + this.id).html();

                var frame1 = $('<iframe />');
                frame1[0].name = "frame1";
                frame1.css({
                    "position": "absolute",
                    "top": "-1000000px"
                });
                $("body").append(frame1);
                var frameDoc = frame1[0].contentWindow ? frame1[0].contentWindow : frame1[0].contentDocument.document ? frame1[0].contentDocument.document : frame1[0].contentDocument;
                frameDoc.document.open();
                //Create a new HTML document.
                frameDoc.document.write('<html><head><title>DIV Contents</title>');
                frameDoc.document.write('</head><body>');
                //Append the external CSS file.
                frameDoc.document.write('<link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css" rel="stylesheet" type="text/css" />');

                frameDoc.document.write(contents);
                //Append the DIV contents.

                frameDoc.document.write('</body></html>');
                frameDoc.document.close();
                setTimeout(function() {
                    window.frames["frame1"].focus();
                    window.frames["frame1"].print();
                    frame1.remove();
                }, 500);
                $(".last").show();
                console.log("myMap.entries()", myMap.entries());
                // return;

                // NOTE above for loop is written to preserve the new rows & it's data after printing operation is done  
                for (const [key, value] of myMap.entries()) {
                    let valueToBeUpdated = $("#" + key).text();
                    $("#" + key).html(value);
                    $("#" + key + "Value").val(valueToBeUpdated);
                    // console.log($(newAllocation5Value));
                }
            });

            // Print all allocation tables (each starts on a new page) and then the summary
            $("#PrintAll").click(function() {
                // Hide last-month inputs while printing
                $(".last").hide();

                // Preserve custom allocation inputs (as done for single prints)
                var myMap = new Map();
                $(".customAllocation").each(function() {
                    myMap.set(this.id, $("#" + this.id).html());
                    $("#" + this.id).html($("#" + this.id + "Value").val());
                });

                // Build combined document: each allocation table on its own page
                var combined = '';
                // find all divs with id starting with 'table' in the DOM order, but exclude the summary div
                $("div[id^='table']").not('#tableSummary').each(function(index) {
                    if (index > 0) {
                        combined += '<div style="page-break-before:always;"></div>';
                    }
                    combined += '<div>' + $(this).html() + '</div>';
                });

                // Append summary as the last page
                combined += '<div style="page-break-before:always;"></div>' + $('#tableSummary').html();

                // Create a hidden iframe and print the assembled content
                var frame1 = $('<iframe />');
                frame1[0].name = 'frame1';
                frame1.css({ position: 'absolute', top: '-1000000px' });
                $('body').append(frame1);
                var frameDoc = frame1[0].contentWindow ? frame1[0].contentWindow : frame1[0].contentDocument.document ? frame1[0].contentDocument.document : frame1[0].contentDocument;
                frameDoc.document.open();
                frameDoc.document.write('<html><head><title>All Allocation Day Books</title>');
                frameDoc.document.write('<link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css" rel="stylesheet" type="text/css" />');
                // Ensure tables have borders and page-breaks apply
                frameDoc.document.write('<style>table{border-collapse:collapse;} .table-bordered td, .table-bordered th{border:1px solid #000 !important;} @media print { .page-break{page-break-before:always;} }</style>');
                frameDoc.document.write('</head><body>');
                frameDoc.document.write(combined);
                frameDoc.document.write('</body></html>');
                frameDoc.document.close();
                setTimeout(function() {
                    window.frames['frame1'].focus();
                    window.frames['frame1'].print();
                    frame1.remove();

                    // Restore previously hidden last inputs and custom allocation values
                    $('.last').show();
                    for (const [key, value] of myMap.entries()) {
                        let valueToBeUpdated = $("#" + key).text();
                        $("#" + key).html(value);
                        $("#" + key + "Value").val(valueToBeUpdated);
                    }
                }, 500);
            });
        });

        // });
    </script>
</body>

</html>