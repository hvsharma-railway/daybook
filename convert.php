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
                    array_push($allocations[$code][$subAllocationCode], array("SECTION" => $t[0], "CO6" => $t[1], "CO7" => $t[2], "BOOK DATE" => $t[3], "PARTY NAME" => $t[4], "BILL DESC" => $t[5], "DEBIT" => $t[6], "CREDIT" => $t[7]));
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
                                    if (array_key_exists($subAllocation2['CO6'], $allocationArray) && $allocationArray[$subAllocation2['CO6']]["SubAllocation"] !== $key) {
                                        if ($subAllocation2["CREDIT"] != 0 && $subAllocation2["DEBIT"] != 0) {
                                            $allocationArray[$subAllocation2['CO6']][$key] = array($subAllocation2["DEBIT"], -$subAllocation2["CREDIT"]);
                                            $total[$key] = round($total[$key] - $subAllocation2["CREDIT"] + $subAllocation2["DEBIT"], 2);
                                        } else if ($subAllocation2["DEBIT"] != 0 && $subAllocation2["CREDIT"] == 0) {
                                            $allocationArray[$subAllocation2['CO6']][$key] = array($subAllocation2["DEBIT"]);

                                            $total[$key] = round($total[$key] + $subAllocation2["DEBIT"], 2);
                                        } else if ($subAllocation2["DEBIT"] == 0 && $subAllocation2["CREDIT"] != 0) {
                                            $allocationArray[$subAllocation2['CO6']][$key] = array(-$subAllocation2["CREDIT"]);
                                            $total[$key] = round($total[$key] - $subAllocation2["CREDIT"], 2);
                                        } else {
                                            $allocationArray[$subAllocation2['CO6']][$key] = array(0);
                                        }
                                    } else {
                                        if ($subAllocation2["DEBIT"] != 0 && $subAllocation2["CREDIT"] != 0) {

                                            $allocationArray[$subAllocation2['CO6']] = array(
                                                "SubAllocation" => $key, "SECTION" => $subAllocation2["SECTION"], "CO6" => $subAllocation2["CO6"], "CO7" => $subAllocation2["CO7"], "BOOK DATE" => $subAllocation2["BOOK DATE"],
                                                "PARTY NAME" => $subAllocation2["PARTY NAME"], "BILL DESC" => $subAllocation2["BILL DESC"], $key => array($subAllocation2["DEBIT"], -$subAllocation2["CREDIT"])
                                            );
                                            $total[$key] = round($total[$key] + $subAllocation2["DEBIT"] - $subAllocation2["CREDIT"], 2);
                                        } else if ($subAllocation2["DEBIT"] != 0 && $subAllocation2["CREDIT"] == 0) {
                                            $allocationArray[$subAllocation2['CO6']] = array(
                                                "SubAllocation" => $key, "SECTION" => $subAllocation2["SECTION"], "CO6" => $subAllocation2["CO6"], "CO7" => $subAllocation2["CO7"], "BOOK DATE" => $subAllocation2["BOOK DATE"],
                                                "PARTY NAME" => $subAllocation2["PARTY NAME"], "BILL DESC" => $subAllocation2["BILL DESC"], $key => array($subAllocation2["DEBIT"])
                                            );
                                            $total[$key] = round($total[$key] + $subAllocation2["DEBIT"], 2);
                                        } else if ($subAllocation2["DEBIT"] == 0 && $subAllocation2["CREDIT"] != 0) {
                                            $allocationArray[$subAllocation2['CO6']] = array(
                                                "SubAllocation" => $key, "SECTION" => $subAllocation2["SECTION"], "CO6" => $subAllocation2["CO6"], "CO7" => $subAllocation2["CO7"], "BOOK DATE" => $subAllocation2["BOOK DATE"],
                                                "PARTY NAME" => $subAllocation2["PARTY NAME"], "BILL DESC" => $subAllocation2["BILL DESC"], $key => array(-$subAllocation2["CREDIT"])
                                            );
                                            $total[$key] = round($total[$key] - $subAllocation2["CREDIT"], 2);
                                        } else {
                                            $allocationArray[$subAllocation2['CO6']] = array(
                                                "SubAllocation" => $key, "SECTION" => $subAllocation2["SECTION"], "CO6" => $subAllocation2["CO6"], "CO7" => $subAllocation2["CO7"], "BOOK DATE" => $subAllocation2["BOOK DATE"],
                                                "PARTY NAME" => $subAllocation2["PARTY NAME"], "BILL DESC" => $subAllocation2["BILL DESC"], $key => array(0)
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
                                /*START UEID PRINT*/
                                if ($file = fopen("UEID.txt", "r")) {

                                    $data = array();
                                    $found = false;
                                    $UWID = "";
                                    while (!feof($file)) {
                                        $line = fgets($file);
                                        $lineText = explode("	", $line);
                                        for ($i = 0; $i < sizeof($lineText); $i++) { //echo "<br/>COmpare *".$lineText[0]."**".$row["CO6"]."*";
                                            if ($lineText[0] === $row["CO6"] && strpos($UWID, $lineText[1]) === false) {
                                                $UWID = $UWID . $lineText[1] . ", ";
                                                $found = true;
                                            }
                                        }
                                    }
                                    if ($found) {
                                        echo "UWID: " . $UWID;
                                    }
                                } else {
                                    throw new Exception("UEID txt file not found");
                                }

                                /*END UEID PRINT*/
                                echo "<br/><br/><br/></td>";

                                $subTotal = 0;
                                foreach ($subAllocation as $k => $v) {
                                    if (array_key_exists($k, $row)) {
                                        if (is_array($row[$k]) != 1) {
                                            echo "<td class='text-right'>" . $row[$k] . "</td>";
                                        } else if ($k !== "") {
                                            echo "<td class='text-right'>";
                                            echo (isset($row[$k][0])) ? $row[$k][0] : "";
                                            echo (isset($row[$k][0]) && isset($row[$k][1])) ? "<br/>" : "";
                                            echo (isset($row[$k][1])) ? $row[$k][1] : "";
                                            echo "</td>";
                                        }
                                        $subTotal = $subTotal + bcadd($row[$k][0], '0', 2);
                                        if ((isset($row[$k][0]) && isset($row[$k][1]))) {
                                            $subTotal = $subTotal + $row[$k][1];
                                        }
                                    } else {
                                        echo "<td class='text-right'>---</td>";
                                    }
                                }

                                $total["Total"] = $total["Total"] + $subTotal;

                                echo "<td class='text-right'>" . $subTotal . "</td>";
                                echo "</tr>";
                            }
                            echo "<tr>";
                            echo "<td>&nbsp;</td>";
                            //echo "<td>&nbsp;</td>";
                            echo "<td><b>TOTAL</b></td>";
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
                                    <td class='text-right last'><input class='text-right lastInput' id="<?php echo $count . "Last"; ?>" type='number' value="0.00" /></td>
                                    <td class='text-right' id="<?php echo $count . "FOR"; ?>"><?php echo $grandTotal["Total" . substr($allocation, 0, 2) . "-" . substr($allocation, 2, 4)];
                                                                                                $forTheMonthTotal = $forTheMonthTotal + $grandTotal["Total" . substr($allocation, 0, 2) . "-" . substr($allocation, 2, 4)]; ?></td>
                                    <td class='text-right toEnd' id="<?php echo $count . "TOEND"; ?>"><?php echo $grandTotal["Total" . substr($allocation, 0, 2) . "-" . substr($allocation, 2, 4)]; ?></td>
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

                </div>
                <button id="Summary" class="btn">PRINT</button></br /><br />
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

            var key = id.substring(0, 1);
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

                var key = this.id.substring(0, 1);
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

                $(".btn").click(function() {
                    $(".last").hide();

                    var myMap = new Map();
                    $(".customAllocation").each(function() {
                        myMap.set(this.id, $("#" + this.id).html());
                        $("#" + this.id).html($("#" + this.id + "Value").val());
                    });
                    var contents = $("#table" + this.id).html();
               
                    // sorting based on allocation number
                    sortTable($("#table" + this.id),'asc');
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

                    // TODO do check this condition for any problems
                    if(this.id === 'Summary'){
                        frameDoc.document.write('<style> .table-bordered tbody tr td, .table-bordered thead tr th { border: 1px solid black !important; width:450px;}  .signature {margin-top: 200px;display:flex; justify-content:space-between;}</style>');
                        frameDoc.document.write(contents + '<div class="signature"><span> <strong>JE (IT) </strong> </span><span> <strong>SSO (B&B) </strong> </span><span> <strong>ADFM </strong> </span> </div>');
                    } else {
                        frameDoc.document.write(contents);
                    }

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
                        console.log( $("#" +key).text());
                        let valueToBeUpdated = $("#" + key).text();
                        $("#" + key).html(value);
                        $("#" + key + "Value").val(valueToBeUpdated);
                        // console.log($(newAllocation5Value));
                    }
                    console.log(myMap);
                });
            });

        // });
    </script>
</body>

</html>