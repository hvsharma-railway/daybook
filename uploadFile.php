<html>
    <head>
        <title>Processing...</title>
        <script src="https://unpkg.com/sweetalert/dist/sweetalert.min.js"></script>
    </head>
    <body>
        <?php
            try{
                
            if( isset($_POST["uploadVouchers"])){
                $form = $_FILES['capitalHeadFile'];
                $capitalHeadFile = "CapitalHead.".pathinfo($form['name'], PATHINFO_EXTENSION);
                $tmp_path = $form['tmp_name'];
                 
                if ( isset($_FILES['capitalHeadFile']) && move_uploaded_file($tmp_path, $capitalHeadFile)){ ?>
                     <script type="text/javascript">
                       swal({
                            title: "File Uploaded Successfully",
                            text: "Now processing for your voucher list! ",
                            icon: "success",
                            button: "Ok!",
                        }).then(()=>{
                            window.location.href="vouchers.php";
                        });
                     </script>
                <?php }else{ ?>
                     <script type="text/javascript">
                       swal({
                            title: "File couln't uploaded",
                            text: "Error while uploading file! ",
                            icon: "error",
                            button: "Ok!",
                        }).then(()=>{
                            window.location.href="index.php";
                        });
                   </script>
                <?php }
            }
            else if(isset($_POST["uploadSuspenseHead"])){
                $form1 = $_FILES['file'];
                $suspenseHeadFile = "BOOK.".pathinfo($form1['name'], PATHINFO_EXTENSION);
                $tmp_path1 = $form1['tmp_name'];
                
                $form2 = $_FILES['ueid'];
                $ueidFile = "UEID.".pathinfo($form2['name'], PATHINFO_EXTENSION);
                $tmp_path2 = $form2['tmp_name'];
                
                if($_FILES['file']['name'] == "" || ($_FILES['file']['name'] != "" && move_uploaded_file($tmp_path1, $suspenseHeadFile))){
                    if($_FILES['ueid']['name'] == "" || ($_FILES['ueid']['name'] != "" && move_uploaded_file($tmp_path2, $ueidFile))){ ?>
                    <script type="text/javascript">
                       swal({
                            title: "File Uploaded Successfully",
                            text: "Now processing for your day book!",
                            icon: "success",
                            button: "Ok!",
                        }).then(()=>{
                            window.location.href="convert.php";
                        });
                     </script>
                <?php }else { ?>
                     <script type="text/javascript">
                       swal({
                            title: "File couln't uploaded!",
                            text: "Error while uploading file! ",
                            icon: "error",
                            button: "Ok!",
                        }).then(()=>{
                            window.location.href="index.php";
                        });
                      </script>
               <?php }
                }else { ?>
                     <script type="text/javascript">
                       swal({
                            title: "File couln't uploaded!",
                            text: "Error while uploading file! ",
                            icon: "error",
                            button: "Ok!",
                        }).then(()=>{
                            window.location.href="index.php";
                        });
                      </script>
               <?php }
            }else{
                echo "NONe";
            }
          }catch(Exception $e){?>
                    <script type="text/javascript">
                       swal({
                            title: "Exception",
                            text: "Something going wrong!",
                            icon: "error",
                            button: "Ok!",
                        }).then(()=>{
                            window.location.href="index.php";
                        });
                      </script>
          <?php }finally{
                
        }
     ?>
    </body>
</html>
