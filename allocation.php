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
          width: 115px; border:1px solid black; text-align:center;
      }
      .data tbody tr td{
          width: 115px; border:1px solid black;
      }
      .posting-table{
          font-size: 14px;
      }
      .posting-table tr td{
          padding: 4px;
      }
    </style>
  </head>
  <body>  
  <div style="margin-left:50px; margin-right:50px;">
  <?php
        require 'vendor/autoload.php';

        use PhpOffice\PhpSpreadsheet\Spreadsheet;

        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();
        $spreadsheet = $reader->load("SuspenseHeadCapitals.xls");
        $sheetData = $spreadsheet->getActiveSheet()->toArray();

        $i=0;
        unset($sheetData[0]);
        $allocations = array();
        $subAllocationCode = ""; 
        
        $vouchers = array();

        foreach ($sheetData as $t) {  
           
            if( ($t[0] === "10-JV" || $t[0] === "X-I") && strpos($t[4], 'SYS-GENERATED') === false){
                 $isExist = false;
                 foreach( $vouchers as $voucher ){
                  if( $voucher["CO6"] === $t[1] && $voucher["CO7"] === $t[2]){
                      $isExist = true;
                      break;
                  }
                }
                if(!$isExist){
                    array_push($vouchers, array( "Type"=>$t[0], "CO6"=>$t[1], "CO7"=>$t[2], "Date"=>$t[3], "Party Name"=>$t[4], "Bill DESC"=>$t[5], "Postings"=>array()));
                }
            }
            $i++;
        }
        
        $num =0;
        foreach( $vouchers as $voucher ){
             
            $currentAllocation = 0;
            foreach ($sheetData as $t) {
                if( $t[0] === "10-JV" || $t[0] === "X-I"){
                    if( $voucher["CO6"] === $t[1] && $voucher["CO7"] === $t[2]){
                        if( $t[6] != 0 ){
                            $vouchers[$num]["Postings"][$currentAllocation]["Debit"]  = $t[6];
                        }
                        if( $t[7] != 0 ){
                            $vouchers[$num]["Postings"][$currentAllocation]["Credit"] = -$t[7];
                        }
                        
                    }
                }
                else if(strpos($t[0], 'ALLOCATION ') !== false){
                    $currentAllocation = substr($t[0],13,8);
                }
            }
            $num++;
        }
        
        ?> 
        <br><br><h1>Voucher Allocation</h1><br><br>

        <table class="table table-striped" style="border:1px solid black; font-size:12px;"> <?php
        foreach( $vouchers as $voucher ){
                 echo "<tr>";
                 echo "<td>".$voucher["Type"]." ".$voucher["CO6"]."</td>";
                 echo "<td>".$voucher["CO7"]."</td>";
                 echo "<td>".$voucher["Date"]."</td>";
                 echo "<td>".$voucher["Party Name"]."</td>";
                 echo "<td><table class='posting-table'>";
                 foreach( $voucher["Postings"] as $postings => $k ){ 
                     if(isset($k["Debit"])){
                        echo  "<tr><td><b>".$postings."</b></td> <td>".$k["Debit"]."</td></tr>";
                     }
                     if(isset($k["Debit"]) && isset($k["Credit"])){
                        echo "<tr><td></td><td style='color:red;'>".(-1*((int)$k["Credit"]))."</td></tr>";
                     }
                     if(!isset($k["Debit"]) && isset($k["Credit"])){
                        echo "<tr><td><b>".$postings."</b></td><td style='color:red;'>".(-1*((int)$k["Credit"]))."</td></tr>";
                     }
                 }
                 echo "</table></td>";
                 echo "</tr>";
        }
        echo "</table>";
        /*
        foreach( $allocations as $allocation => $subAllocation) { 
            echo "<h3><u>  &nbsp; &nbsp; &nbsp;".substr($allocation,0,2)."-".substr($allocation,2,4)." &nbsp; &nbsp; &nbsp; </u></h3>";
            ?>
              <table class="table table-striped" style="border:1px solid black; font-size:12px;">
                <thead>
                  <tr>
                    <th style="width:180px;"> Section - CO6/CO7</th><th style="width:110px;"> BILL DATE </th><th> PARTY NAME </th><th> BILL_DESC </th>
                    <?php $total= array(); 
                        foreach( $subAllocation as $k => $v) { 
                         echo "<th style='width:90px;'> ".$k." </th>";
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
                                    if( $subAllocation2["CREDIT"] != 0){
                                        $allocationArray[$subAllocation2['CO6']][$key] = -$subAllocation2["CREDIT"];
                                        $total[$key] = $total[$key]-$subAllocation2["CREDIT"];
                                    }else{
                                        $allocationArray[$subAllocation2['CO6']][$key] = $subAllocation2["DEBIT"];
                                        $total[$key] = $total[$key]+$subAllocation2["DEBIT"];
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
                        echo "<tr>";
                        if (strpos($row["SECTION"], 'JV') !== false) {
                            echo "<td>".++$c." JV - ".$row["CO6"]."</td>";
                        }else{
                            echo "<td>".++$c." ".$row["SECTION"]." - ".substr($row["CO6"],-4)." / ".substr($row["CO7"],-4)."</td>";
                        }
                        echo "<td>".$row["BOOK DATE"]."</td>";
                        echo "<td>".$row["PARTY NAME"]."</td>";
                        echo "<td>".$row["BILL DESC"]."</td>";
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
                ?> 
                
               </tbody>
             </table>
             </br/>
            <?php            
        }*/
?>
            
  </div>
  </body>
</html>