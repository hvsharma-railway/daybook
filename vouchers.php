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
        require 'vendor/autoload.php';

        use PhpOffice\PhpSpreadsheet\Spreadsheet;

        //CAPITAL HEAD 

        if ($file = fopen("CapitalHead.txt", "r")) {
          
            $data = array();
            $heading = "";
            
            while(!feof($file)) {
               
                $line = fgets($file);
                $lineText = explode("  ", $line);
                
                if( $lineText[0] === "X-I" || $lineText[0] === "SBNS" || $lineText[0] === "***" || $lineText[0] === "PEN"){
                    
                    $rowData = array();
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

                    $found = false;
                    for($j=0; $j<sizeof($data); $j++){
                        if( $data[$j][1] === $rowData[1]){
                            $found = true;
                            $data[$j][6] = (int)$data[$j][6]+(int)$rowData[6]-(int)$rowData[7];
                            break;
                        }
                    }
                    if( !$found ){
                         array_push($data, $rowData );
                    }
                }
                else if( strpos( $line, "CAPITAL HEAD [CO7] REPORT FROM" ) &&  $heading == ""){
                         $heading = $line;
                }
            }
            fclose($file);
            $sql = "SELECT DISTINCT CO6NUMBER, UEID FROM ACCO6ALC WHERE CO6NUMBER IN (";
             echo "<br/><p style='text-align:right; font-size:20px;'><b><a href='index.php'>GO BACK</a></b></p>";
             echo "<p>".$heading."</p>";
             echo "<table class='table'>
                      <thead>
                          <tr><th>S.No.</th><th>Section</th><th>CO6</th><th>CO7</th><th>Date</th><th>Party Name</th><th>AGMT/PO.NO</th><th>Amount</th></tr>
                      </thead>
                      <tbody>";
                      for( $j=0; $j<sizeof($data); $j++){
                           echo "<tr><td>".($j+1)."</td><td>".$data[$j][0]."</td><td>".$data[$j][1]."</td><td>".$data[$j][2]."</td><td>".$data[$j][3]."</td><td>".$data[$j][4]."</td><td>".$data[$j][5]."</td><td>".$data[$j][6]."</td></tr>";
                          
                           $sql = $sql."'".trim($data[$j][1])."'";
                           
                           if($j < sizeof($data)-1 ){
                                $sql = $sql.", ";
                           }
                      }
                      $sql = $sql.") AND UEID != 0 ";
                      echo "</tbody></table>";
                      echo "<br/><br/><span style='font-size:16px;'>SQL to get UWID agains CO6 No:</span><br> ".$sql;
                      echo "<br/><br/><p style='text-align:center; font-size:20px;'><b><a href='index.php'>GO BACK</a></b></p>";
        }else{?>
                 <script type='text/javascript'>
                       swal({
                            title: "File Not Found",
                            text: "plz upload capitalhead file first ",
                            icon: "error",
                            button: "Ok!",
                        }).then(()=>{
                            window.location.href="index.php";
                        });
                 </script>   
        <?php }
   ?>
  </div>
  </body>
</html>