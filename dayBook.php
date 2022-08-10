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
    </style>
  </head>
  <body>  
  <div style="margin-left:50px; margin-right:50px;" class="container">
  <?php
        
            $subAllocations = array();

            $currentAllocation = null;
            $currentSubAllocation = null;
            $vouchers = array();

            if ($file = fopen("CapitalHead.txt", "r")) {

                $vouchers = array();
                while(!feof($file)) {

                $line = fgets($file);
                $lineTextTemp = explode("  ", $line);
                $lineText = array();
                
                $currentTab = 0;
                for ($i = 0; $i < sizeof($lineTextTemp); $i++){
                     if($lineTextTemp[$i] != "" && $lineTextTemp[$i] != " "){
                        $lineText[$currentTab++] = $lineTextTemp[$i];
                     }
                }
                if( sizeof($lineText) == 7){
                    $lineText[7] = $lineText[6];
                    $lineText[6] = $lineText[5];
                    $lineText[5] = "****";
                }
                if( sizeof($lineText) > 0 && strlen($lineText[0]) == 8 ){
                        
                         if( $currentAllocation != substr($lineText[0],0,2) ){
                            $currentAllocation = substr($lineText[0],0,2);
                            echo "<h3>".$currentAllocation."</h3><br>";
                        }
                        if($currentSubAllocation != substr($lineText[0],0,4)){
                            $currentSubAllocation = substr($lineText[0],0,4);
                            $vouchers = array();
                            echo "<h4>".$currentSubAllocation."</h4><br>";
                        }else{
                            
                            
                        }
                    }
                    else if(sizeof($lineText) > 0 && ($lineText[0] === "***" || $lineText[0] === "X-I" || $lineText[0] === "SBNS" || $lineText[0] === "PEN")){
                            array_push($vouchers, $lineText);
                            echo $lineText[0]." ".$lineText[1]." ".$lineText[2]." ".$lineText[3]." ".$lineText[4]." ".$lineText[5]." ".$lineText[6]." ".$lineText[7]." "."<br>";
                    }    
                        
                        //echo $lineText[0]."<br>";
                    /* $rowData = array();
                        $currentTab = 0;
                        for($i =0; $i<sizeof($lineText); $i++){
                            
                            if($lineText[$i] != ''){
                                
                                if( $currentTab == 5 && is_numeric($lineText[$i])){
                                    array_push($rowData, "***" );
                                }
                                array_push($rowData, $lineText[$i] );
                                $currentTab++;
                            }

                        }
                    */
                    
                    /*else if( substr($lineText[0],0,2) === $allocationList[$a]."" ) {
                              
                            if( array_search( substr($lineText[0],0,4), $subAllocations ) === "" ){ echo ":P:";
                                array_push($subAllocations, substr($lineText[0],0,4));
                            }else{
                                echo ":NP:".array_search( substr($lineText[0],0,4), $subAllocations ) ;
                            }
                            echo substr($lineText[0],0,4)."<br>";
                    }*/
                    
              }
              
            }
        

        /*
        foreach ($sheetData as $t) {
           
            if(strpos($t[0], 'ALLOCATION ') !== false){

                   $allocationExist = false;
                   $subAllocationCode = substr($t[0],13,8);
                   $code = substr($t[0],13,4);
                   
                   if( substr($code,2, 4) < 66 || substr($code,2, 4) == 81 || substr($code,2, 4) == 83){
                        if(array_key_exists($code, $allocations)){
                            $allocations[$code][$subAllocationCode] = array();
                        }else{
                            $allocations[$code] = array($subAllocationCode=>[]);
                        }
                   }
            }
            
            else if($t[0] != "SECTION" && $t[0] != "" &&  strpos($t[4], "SYS-GENERATED") === false && (substr($code,2, 4) < 66 || substr($code,2, 4) == 81 || substr($code,2, 4) == 83) ){
                array_push($allocations[$code][$subAllocationCode], array("SECTION"=>$t[0], "CO6" => $t[1],"CO7"=>$t[2], "BOOK DATE"=>$t[3], "PARTY NAME"=>$t[4], "BILL DESC"=>$t[5], "DEBIT"=>$t[6], "CREDIT"=>$t[7]));
            }
            $i++;
        }

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
        } */
?>
            
  </div>
  </body>
</html>