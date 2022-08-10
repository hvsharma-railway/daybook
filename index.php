<html>
    <head>
        <title>Day Book</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
        <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
        <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    </head>
<body> 
<div class="container"> 
  <div class="row" style="margin-top:15px; margin-bottom:50px;">
      <div class="col-lg-12 text-center">
           <br><img src="download.png" style="height:80px;"/> 
           <h4 style="font-weight:bold;">BOOKS Section</h4><h2 style="font-weight:bold;">RAJKOT, WR</h2>
      </div>
  </div>
  <br/>
  <div class="row">
    <div class="col-lg-6 card text-center" style="margin:auto auto; height: ">
         <form id="capitalHeadForm" action="uploadFile.php" method="post" enctype="multipart/form-data">
           <h3 style="font-weight:bold;">VOUCHERS</h3><br/>
           <p>Please upload CapitalHead (.txt) file to get voucher list</p>
           <input type="file" class="form-control form-control-md"  id="capitalHeadFile" name="capitalHeadFile" />
           <input type="hidden" name="uploadVouchers" id="uploadVouchers" value="uploadVouchers"/>
           <br/><button type="button" class="btn btn-primary" id="uploadVouchersBtn" name="uploadVouchersBtn"> Upload </button>
           <br/><p id="msg1">&nbsp;</p>
       </form>
       <p style='text-align:center;'><a href="vouchers.php">Click here</a> if already uploaded!</a></p>
    </div> 
    <div class="col-lg-6 card text-center" style="margin:auto auto; height:" >
      <form id="suspenseHeadForm" action="uploadFile.php" method="post" enctype="multipart/form-data">
           <h3 style="font-weight:bold;">DAY BOOK</h3>
           <br/><p>Please upload SuspenseHead (.xlsx) file as downloaded from  IPAS</p>
           <input type="file" class="form-control form-control-md"  id="file" name="file" />
           <br/><p>Please upload Mapping (.txt) file to map CO6 with UEID</p>
           <input type="file" class="form-control form-control-md"  id="ueid" name="ueid" />
           <input type="hidden" name="uploadSuspenseHead" id="uploadSuspenseHead" value="uploadSuspenseHead"/>
           <br/><button type="button" class="btn btn-primary" id="uploadSuspenseHeadBtn" name="uploadSuspenseHeadBtn"> Upload </button>
           <br/><p id="msg2">&nbsp;</p>
       </form>
       <p style='text-align:center;'><a href="convert.php">Click here</a> if already uploaded!</a></p>
     </div>
  </div>
  <br/>
  <div class="row">
    <div class="col-lg-12 text-center">
        <p>A module Designed and Developed by <i>RAJAT KUMAR, JE(IT) RJT WR</i></p>
    </div> 
  </div>
  
   
</div>

<script>
    $(document).ready(function(){
       
       $("#uploadSuspenseHeadBtn").click(function(){

        $("#msg2").html("&nbsp;");
        if( ( $("#file").val() == "" || $("#file").val() == undefined) && ($("#ueid").val() == "" || $("#ueid").val() == undefined)){
            $("#msg2").html("<span style='color:red'>Kindly upload atleast one file!</span>");
            return false;
        }
        else if( $("#file").val() != "" && $("#file").val().split('.').pop().toLowerCase() != "xlsx" ){
            $("#msg2").html("<span style='color:red'>Please choose .xlsx suspensehead file of a particular allocation </span>");
            return false;
        }else if($("#ueid").val() != "" &&  $("#ueid").val().split('.').pop().toLowerCase() != "txt" ){
            $("#msg2").html("<span style='color:red'>Please choose .txt file in order to map CO6 with UEID!</span>");
            return false;
        }else{
            $("#suspenseHeadForm").submit();
        }
       });
       
       $("#uploadVouchersBtn").click(function(){
          
        $("#msg1").html("&nbsp;");
        console.log($("#capitalHeadFile").val()); 
        if( $("#capitalHeadFile").val() == "" || $("#capitalHeadFile").val() == undefined ||  $("#capitalHeadFile").val().split('.').pop().toLowerCase() != "txt" ){
            $("#msg1").html("<span style='color:red'>Please choose .txt file in order to get voucher list</span>");
            return false;
          }else{
            $("#capitalHeadForm").submit();
          }
       });;

    });
    </script>
</body>
</html>