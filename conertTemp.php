<html>
  <head>
    <title>Day Book</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <style>
      .data thead tr th{
          width: 115px; border:1px solid black !important; text-align:center;
      }
      .data tbody tr td{
          width: 115px; border:1px solid black ;
      }
    </style>
    <style> .table-bordered tbody tr td, .table-bordered thead tr th { border: 1px solid black !important;} </style>
  </head>
  <body>  
  <div style="margin-left:50px; margin-right:50px;">
  <?php
        require 'vendor/autoload.php';

        use PhpOffice\PhpSpreadsheet\Spreadsheet;

        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $spreadsheet = $reader->load("BOOK.xlsx");
        $sheetData = $spreadsheet->getActiveSheet()->toArray();

        $i=0;
        unset($sheetData[0]);
        $allocations = array();
        $grandTotal = array();
        $subAllocationCode = ""; 
        $dayBookFor = "";
        $code = "";
        $heading = $sheetData[1][0];
        foreach ($sheetData as $t) {
            
            if(strpos($t[0], 'ALLOCATION ') !== false){
                   $code = substr($t[0],13,4);
                   $allocationExist = false;
                   $subAllocationCode = substr($t[0],13,8);
                   if( substr($code,2, 4) < 66 || substr($code,2, 4) == 81 || substr($code,2, 4) == 83){
                        if(array_key_exists($code, $allocations)){
                            $allocations[$code][$subAllocationCode] = array();
                        }else{
                            $allocations[$code] = array($subAllocationCode=>[]);
                        }
                   }

                   if($dayBookFor === ""){
                      $dayBookFor = substr($t[0],13,2);
                   }
            }
            
            else if($t[0] != "SECTION" && $t[0] != "" &&  strpos($t[4], "SYS-GENERATED") === false && $code != "" && $subAllocationCode != "" && (substr($code,2, 4) < 66 || substr($code,2, 4) == 81 || substr($code,2, 4) == 83) ){
                    array_push($allocations[$code][$subAllocationCode], array("SECTION"=>$t[0], "CO6" => $t[1],"CO7"=>$t[2], "BOOK DATE"=>$t[3], "PARTY NAME"=>$t[4], "BILL DESC"=>$t[5], "DEBIT"=>$t[6], "CREDIT"=>$t[7]));
            }
            $i++;
        }

        foreach( $allocations as $allocation => $subAllocation) { ?>
            <div id="<?php echo 'table'.substr($allocation,0,2)."-".substr($allocation,2,4); ?>">
            <?php echo "<h3><u>  ".$heading. " FOR THE ALLOCATION ".substr($allocation,0,2)."-".substr($allocation,2,4)." </u></h3>"; ?>
              <table class="table table-striped table-bordered" style=" font-size:12px;" >
                <thead style='font-size:14px;'>
                  <tr>
                    <th style="width:180px;">Sec. - CO6 / CO7</th><th style="width:110px;">DATE </th><th>BILL DESC / PARTY NAME </th>
                    <?php $total= array(); 
                        foreach( $subAllocation as $k => $v) { 
                         echo "<th style='width:90px;'>".$k." </th>";
                         $total[$k] = 0;
                    }?>
                    <th>Total</th>
                  </tr>
                </thead>
                <tbody>
                <?php
                    $allocationArray = array();
                    foreach($allocations[$allocation] as $key=> $subAllocations){

                        
                        foreach($subAllocations as $subAllocation2){
                            if(array_key_exists($subAllocation2['CO6'], $allocationArray) && $allocationArray[$subAllocation2['CO6']]["SubAllocation"] !== $key ){
                                    if( $subAllocation2["CREDIT"] != 0 && $subAllocation2["DEBIT"] != 0){
                                        
                                        $allocationArray[$subAllocation2['CO6']][$key] = array($subAllocation2["DEBIT"], -$subAllocation2["CREDIT"]);
                                        $total[$key] = $total[$key]-$subAllocation2["CREDIT"]+$subAllocation2["DEBIT"];
                                        //$allocationArray[$subAllocation2['CO6']][$key] = $subAllocation2["DEBIT"];
                                        //$total[$key] = $total[$key]+$subAllocation2["DEBIT"];
                                    }else if($subAllocation2["DEBIT"] != 0 && $subAllocation2["CREDIT"] == 0){
                                        $allocationArray[$subAllocation2['CO6']][$key] = array($subAllocation2["DEBIT"]);
                                        $total[$key] = $total[$key]+$subAllocation2["DEBIT"];
                                    }else if($subAllocation2["DEBIT"] == 0 && $subAllocation2["CREDIT"] != 0){
                                        $allocationArray[$subAllocation2['CO6']][$key] = array(-$subAllocation2["CREDIT"]);
                                        $total[$key] = $total[$key]-$subAllocation2["CREDIT"];
                                    }else if($subAllocation2["DEBIT"] == 0 && $subAllocation2["CREDIT"] == 0){
                                        $allocationArray[$subAllocation2['CO6']][$key] = array();
                                        //$total[$key] = $total[$key]+$subAllocation2["DEBIT"];
                                    }
                            }
                            else{
                                    if( $subAllocation2["DEBIT"] != 0 && $subAllocation2["CREDIT"] != 0){
                                   
                                        $allocationArray[$subAllocation2['CO6']] = array("SubAllocation"=> $key,"SECTION"=>$subAllocation2["SECTION"], "CO6" => $subAllocation2["CO6"],"CO7"=>$subAllocation2["CO7"], "BOOK DATE"=>$subAllocation2["BOOK DATE"], 
                                                                                    "PARTY NAME"=>$subAllocation2["PARTY NAME"], "BILL DESC"=>$subAllocation2["BILL DESC"], $key=>$subAllocation2["DEBIT"]);
                                    
                                        $allocationArray[$subAllocation2['CO6']] = array("SubAllocation"=> $key,"SECTION"=>$subAllocation2["SECTION"], "CO6" => $subAllocation2["CO6"],"CO7"=>$subAllocation2["CO7"], "BOOK DATE"=>$subAllocation2["BOOK DATE"], 
                                                                                    "PARTY NAME"=>$subAllocation2["PARTY NAME"], "BILL DESC"=>$subAllocation2["BILL DESC"], $key=>-$subAllocation2["CREDIT"]);
                                        
                                        $total[$key] = $total[$key]+$subAllocation2["DEBIT"];
                                        $total[$key] = $total[$key]-$subAllocation2["CREDIT"];

                                    }else if($subAllocation2["CREDIT"] != 0){
                                        $allocationArray[$subAllocation2['CO6']] = array("SubAllocation"=> $key,"SECTION"=>$subAllocation2["SECTION"], "CO6" => $subAllocation2["CO6"],"CO7"=>$subAllocation2["CO7"], "BOOK DATE"=>$subAllocation2["BOOK DATE"], 
                                                                                          "PARTY NAME"=>$subAllocation2["PARTY NAME"], "BILL DESC"=>$subAllocation2["BILL DESC"], $key=>-$subAllocation2["CREDIT"]);
                                    
                                        $total[$key] = $total[$key]-$subAllocation2["CREDIT"];
                                      }else{
                                        $allocationArray[$subAllocation2['CO6']] = array("SubAllocation"=> $key,"SECTION"=>$subAllocation2["SECTION"], "CO6" => $subAllocation2["CO6"],"CO7"=>$subAllocation2["CO7"], "BOOK DATE"=>$subAllocation2["BOOK DATE"], 
                                                                                            "PARTY NAME"=>$subAllocation2["PARTY NAME"], "BILL DESC"=>$subAllocation2["BILL DESC"], $key=>$subAllocation2["DEBIT"]);
                                    
                                        $total[$key] = $total[$key]+$subAllocation2["DEBIT"];
                                        }
                            }
                        }
                    }
                    $total["Total"] = 0;
                    $c =0;
                    foreach( $allocationArray as $row ) { 
                        echo "<tr style='border:1px solid black;'>";
                        if (strpos($row["SECTION"], 'JV') !== false) {
                            echo "<td>".++$c." JV - ".$row["CO6"]."</td>";
                        }else{
                            echo "<td>".++$c." ".$row["SECTION"]." - ".substr($row["CO6"],-4)." / ".substr($row["CO7"],-4)."</td>";
                        }
                        echo "<td>".$row["BOOK DATE"]."</td>";
                        echo "<td>".$row["BILL DESC"]." M/S ".$row["PARTY NAME"]."</td>";
                       
                        $subTotal = 0;
                        foreach( $subAllocation as $k => $v) {
                            if(array_key_exists($k, $row)){
                               echo "<td>".$row[$k]."</td>";
                               $subTotal = $subTotal+bcadd($row[$k],'0',2);
                            }else{
                               echo "<td>---</td>";
                            }
                        }
                        
                        $total["Total"] = $total["Total"]+$subTotal;
                       
                        echo "<td>".$subTotal."</td>";
                        echo "</tr>";        
                        
                    }
                    echo "<tr>";
                    echo "<td>&nbsp;</td>";
                    echo "<td>&nbsp;</td>";
                    echo "<td><b>TOTAL</b></td>";
                    foreach( $subAllocation as $k => $v) {
                        if(array_key_exists($k, $total)){
                           echo "<td><b>".$total[$k]."</b></td>";
                        }else{
                           echo "<td><b>0.00</b></td>";
                        }
                    }
                    echo "<td><b>".$total["Total"]."</b></td>";
                    echo "</tr>";
                    $grandTotal["Total".substr($allocation,0,2)."-".substr($allocation,2,4)] = $total["Total"];
                ?> 
                
               </tbody>
             </table>
            </div>
             <div class='text-center'><button id="<?php echo substr($allocation,0,2).'-'.substr($allocation,2,4); ?>"  class="btn">PRINT</button></div></br/>
            <?php            
      }
?>
  <br/>
  <div>
       <div id="tableSummary">
       <h3>Day Book Summary For Allocation Code:<?php echo $dayBookFor; $forTheMonthTotal = 0?></h3><br/>
       <table class="table table-striped table-bordered" style="width:40%; font-size:16px;">
          <thead style="font-size:18px;">
             <tr>
                 <th class='text-right'>Sub-Allocations</th>
                 <th class="text-right last">Last Month</th>
                 <th class='text-right'>For The Month</th>
                 <th class='text-right'>To The Month</th>
             </tr>
          </thead>
          <tbody>
             <?php $count=0; foreach( $allocations as $allocation => $subAllocation) { $count++;?>
                <tr>
                    <td class='text-right'><?php echo substr($allocation,0,2)."-".substr($allocation,2,4); ?></td>
                    <td class='text-right last'><input class='text-right lastInput' id="<?php echo $count."Last"; ?>" type='number' value="0.00"/></td>
                    <td class='text-right' id="<?php echo $count."FOR"; ?>" ><?php echo $grandTotal["Total".substr($allocation,0,2)."-".substr($allocation,2,4)]; 
                        $forTheMonthTotal = $forTheMonthTotal+$grandTotal["Total".substr($allocation,0,2)."-".substr($allocation,2,4)]; ?></td>
                    <td class='text-right' id="<?php echo $count."TOEND"; ?>"><?php echo $grandTotal["Total".substr($allocation,0,2)."-".substr($allocation,2,4)]; ?></td>
                </tr>
             <?php } ?>
                <tr>
                    <td class='text-right'><b>TOTAL</b></td>
                    <td class='text-right last'><input class='text-right' type='number' value="0.00" id="totalLastMonth" disabled/></td>
                    <td class='text-right'><?php echo $forTheMonthTotal; ?></td>
                    <td class='text-right' id="totalToEnd"><?php echo $forTheMonthTotal; ?></td>
                </tr>
          </tbody>
       </table>
       </div>
       <button id="Summary"  class="btn">PRINT</button></br/>
  </div>    
  </div>
  <script type='text/javascript'>

 $(document).ready(function(){

    $(".lastInput").change(function(){
        var key = this.id.substring(0, 1);
        $("#totalLastMonth").val(parseFloat($("#totalLastMonth").val())+parseFloat($("#"+this.id).val()));
        $("#totalToEnd").html(parseFloat($("#totalToEnd").html())+parseFloat($("#"+this.id).val()));
        var toEnd = parseFloat($("#"+this.id).val())+parseFloat($("#"+key+"FOR").html());
        $("#"+key+"TOEND").html(toEnd);

    });
    $(function () {
    $(".btn").click(function () {
        $(".last").hide();
        var contents = $("#table"+this.id).html();
        var frame1 = $('<iframe />');
        frame1[0].name = "frame1";
        frame1.css({ "position": "absolute", "top": "-1000000px" });
        $("body").append(frame1);
        var frameDoc = frame1[0].contentWindow ? frame1[0].contentWindow : frame1[0].contentDocument.document ? frame1[0].contentDocument.document : frame1[0].contentDocument;
        frameDoc.document.open();
        //Create a new HTML document.
        frameDoc.document.write('<html><head><title>DIV Contents</title>');
        frameDoc.document.write('</head><body>');
        //Append the external CSS file.
        frameDoc.document.write('<link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css" rel="stylesheet" type="text/css" />');
        frameDoc.document.write('<style> .table-bordered tbody tr td, .table-bordered thead tr th { border: 1px solid black !important;} </style>');
        //Append the DIV contents.
        frameDoc.document.write(contents);
        frameDoc.document.write('</body></html>');
        frameDoc.document.close();
        setTimeout(function () {
            window.frames["frame1"].focus();
            window.frames["frame1"].print();
            frame1.remove();
        }, 500);
        $(".last").show();
    });
});
   
 });
  </script>  
 </body>
</html>