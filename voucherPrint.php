<!DOCTYPE html>
<html>
  <head>
    <title>Voucher Print</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <style>
         .table-bordered, .table-bordered thead tr th, .table-bordered tbody tr td{
            border:1px solid black;
            text-align: right;
         }
        </style>
  </head>
<body>

<div class="container text-center">

  <h2>PRINT VOUCHER ALLOCATION</h2>
  <p>Please enter full CO6 no. in order to print amount distribution in various allocations.</p>
  <form class="form-inline" action="" method="post">
    <div class="form-group">
      <label for="email">CO6 Number: </label>
      <input type="number" class="form-control" id="co6" placeholder="Enter CO6 Number" name="co6" value="'<?php (isset($_POST["co6"]))?$_POST["co6"]: "" ?>'">
    </div>
    <button type="submit" class="btn btn-default">Submit</button>
  </form>
  <br/>
  <?php if (isset($_POST["co6"])){ 
      
      if ($file = fopen("CapitalHead.txt", "r")) {
          
            $data = array();

            $currentAllocation = "";
            $currentSubAllocation = "";
            $co6Details = "";
            $distributions = array();

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

                    if( $lineText[4] == $_POST["co6"]){
                        
                        $currentTab = 0;
                        for($i =0; $i<sizeof($lineText); $i++){
                        
                            if($lineText[$i] != ''){
                                
                                if( $currentTab == 5 && is_numeric($lineText[$i])){
                                    if(isset($distributions[$currentSubAllocation])){  
                                        $currentDistribution = $distributions[$currentSubAllocation];
                                        array_push($currentDistribution, array($currentAllocation => $lineText[$i]));
                                        $distributions[$currentSubAllocation] = $currentDistribution;
                                     }else{
                                        $distributions[$currentSubAllocation] = array(array($currentAllocation => $lineText[$i]));
                                     }
                                } 
                                else if($currentTab == 6 && is_numeric($lineText[$i])){
                                    if(isset($distributions[$currentSubAllocation])){  
                                        $currentDistribution = $distributions[$currentSubAllocation];
                                        array_push($currentDistribution, array($currentAllocation => $lineText[$i]));
                                        $distributions[$currentSubAllocation] = $currentDistribution;
                                     }else{
                                        $distributions[$currentSubAllocation] = array(array($currentAllocation => $lineText[$i]));
                                     }
                                }
                                array_push($rowData, $lineText[$i] );
                                $currentTab++;
                            }
    
                        }
                        if( $co6Details == ""){
                            $co6Details = "CO6:".$lineText[4].", CO7:".$lineText[7].", Date:".$lineText[10].",<br> Party:".$lineText[11].", Desc:".$lineText[15]; 
                        }
                        
                        
                        
                    }else{
                        //echo ":".$lineText[4].":".$_POST["co6"].":<br/>";
                        
                    }

                    
                }
                else if($lineText[0] != "" && is_numeric($lineText[0])){ 
                    $currentAllocation = $lineText[0];
                    $currentSubAllocation = substr($lineText[0],0,4);
                }
            }
      }
      
      
      
      
      
  ?>
  <div class="row">
    <div class="col-lg-4"></div>
    <div class="col-lg-4" id="printDiv">
    <?php echo "<p style='font-size:16px;'>".$co6Details."</p><br>"; foreach ($distributions as $distribution => $arr){ ?>
    <table class="table table-bordered" >
      <thead>
          <tr><th>Allocation</th><th>Amount</th></tr>
      </thead>
      <tbody>
          <?php $total= 0; for($k=0; $k<sizeof($arr); $k++){ 
                    foreach($arr[$k] as $key=>$value){ $total=$total+$value; ?>
                <tr><td><?php echo $key; ?></td><td><?php echo $value; ?></td></tr>
          <?php } } ?>
          <tr><td><b>TOTAL</b></td><td><b><?php echo $total; ?></b></td></tr>
      </tbody>
    </table>
    <?php } ?>
    <br/>
    </div>
    <div class="col-lg-4"></div>
  </div>
  <br/>
  <div class='text-center'><button id="print"  class="print">PRINT</button></div></br/>
    <a href="">Back</a>
  <?php } ?>
</div>

<script type='text/javascript'>

 $(document).ready(function(){
   
    $("#print").click(function () {
        $(".last").hide();
        var contents = $("#"+this.id+"Div").html();
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
        frameDoc.document.write('<style> .table-bordered tbody tr td, .table-bordered thead tr th { border: 1px solid black !important; } .table-bordered{width:300px;}</style>');
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
  </script>  
</body>
</html>