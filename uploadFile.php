<html>
    <head>
        <title>Processing...</title>
        <script src="https://unpkg.com/sweetalert/dist/sweetalert.min.js"></script>
    </head>
    <body>
        <?php
            try{
                
            if(isset($_POST["uploadSuspenseHead"])){
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
                            window.location.href="view.php";
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
            } else if( isset($_POST["uploadVoucherExcel"])){
                $form = $_FILES['voucherExcelFile'];
                $voucherExcelFile = "VoucherExcelFile.".pathinfo($form['name'], PATHINFO_EXTENSION);
                $tmp_path = $form['tmp_name'];
                 
                if ( isset($_FILES['voucherExcelFile']) && move_uploaded_file($tmp_path, $voucherExcelFile)){ ?>
                     <script type="text/javascript">
                       swal({
                            title: "File Uploaded Successfully",
                            text: "Now processing for your voucher list! ",
                            icon: "success",
                            button: "Ok!",
                        }).then(()=>{
                            window.location.href="downloadAllocationSheets.php";
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
            } else if (isset($_POST['seperateJVAndAllocationSheet']) && isset($_FILES['jvFiles']) && count($_FILES['jvFiles']['name']) > 0 && $_FILES['jvFiles']['name'][0] != "") {
                $targetDir = "daybook-generated-files";
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0777, true);
                }
                $fileCount = count($_FILES['jvFiles']['name']);
                for ($i = 0; $i < $fileCount; $i++) {
                    $tmpName = $_FILES['jvFiles']['tmp_name'][$i];
                    $newName = $targetDir . "/" . ($i + 1) . ".xlsx";
                    move_uploaded_file($tmpName, $newName);
                }
                ?>
                <script type="text/javascript">
                    swal({
                        title: "Files Uploaded Successfully",
                        text: "Redirecting to JV/Allocation processing...",
                        icon: "success",
                        button: "Ok!",
                    }).then(()=>{
                        window.location.href="seperateJVAndAllocationSheet.php";
                    });
                </script>
                <?php
            } else{
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
