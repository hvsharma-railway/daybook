<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Day Book Portal</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</head>

<body class="bg-light">
  <div class="container py-5">
    <h1 class="text-center mb-5 fw-bold">Day Book Portal</h1>
    <div class="row g-4 justify-content-center">
      <div class="col-lg-4 col-md-6">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <form id="capitalHeadForm" action="uploadFile.php" method="post" enctype="multipart/form-data">
              <div class="mb-3">
                <label for="capitalHeadFile" class="form-label"><strong>Step 1:</strong> Capital Head (.txt)</label>
                <input type="file" class="form-control" id="capitalHeadFile" name="capitalHeadFile" accept=".txt">
                <input type="hidden" name="uploadVouchers" value="uploadVouchers" />
                <div class="msg text-danger mt-2" id="msg1"></div>
              </div>
              <button type="button" class="btn btn-primary w-100 mb-2" id="uploadVouchersBtn">Upload</button>
              <a href="vouchers.php" class="btn btn-link w-100">View vouchers</a>
            </form>
          </div>
        </div>
      </div>
      <div class="col-lg-4 col-md-12">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <form id="seperateJVForm" action="uploadFile.php" method="post" enctype="multipart/form-data">
              <div class="mb-3">
                <label for="jvFiles" class="form-label"><strong>Step 2:</strong> Seperate JV and Allocation (.xlsx, multiple files)</label>
                <input type="file" class="form-control" id="jvFiles" name="jvFiles[]" accept=".xlsx" multiple>
                <input type="hidden" name="seperateJVAndAllocationSheet" value="seperateJVAndAllocationSheet" />
                <div class="msg text-danger mt-2" id="msgJV"></div>
              </div>
              <button type="button" class="btn btn-warning w-100 mb-2" id="seperateJVBtn">Upload &amp; Process</button>
            </form>
          </div>
        </div>
      </div>
      <div class="col-lg-4 col-md-12">
        <div class="card shadow-sm h-100">
          <div class="card-body">
            <form id="suspenseHeadForm" action="uploadFile.php" method="post" enctype="multipart/form-data">
              <div class="mb-3">
                <label for="file" class="form-label"><strong>Step 3:</strong> SuspenseHead (.xlsx)</label>
                <input type="file" class="form-control" id="file" name="file" accept=".xlsx">
              </div>
              <div class="mb-3">
                <label for="ueid" class="form-label">Mapping (.txt)</label>
                <input type="file" class="form-control" id="ueid" name="ueid" accept=".txt">
                <input type="hidden" name="uploadSuspenseHead" value="uploadSuspenseHead" />
                <div class="msg text-danger mt-2" id="msg2"></div>
              </div>
              <button type="button" class="btn btn-success w-100 mb-2" id="uploadSuspenseHeadBtn">Upload</button>
              <a href="convert.php" class="btn btn-link w-100">Convert Day Book</a>
            </form>
          </div>
        </div>
      </div>
    </div>
    <footer class="mt-5">
      <div class="container">
        <div class="row justify-content-center">
          <div class="col-auto">
            <div class="text-center py-3 px-4 bg-white rounded-4 shadow-sm border border-2 border-primary-subtle">
              <span class="d-block fw-semibold text-primary mb-1">Designed by Rajat Kumar</span>
              <span class="d-block small text-secondary">J.E. (I.T.) RJT/WR</span>
              <hr class="my-2">
              <span class="d-block fw-semibold text-success mb-1">Currently Modified &amp; Managed by Harshvardhan Sharma</span>
              <span class="d-block small text-secondary">S.E. (I.T.)/RJT/WR</span>
            </div>
          </div>
        </div>
      </div>
    </footer>
  </div>
  <script>
    $(document).ready(function () {
      $("#uploadSuspenseHeadBtn").click(function () {
        $("#msg2").html("");
        if (($("#file").val() == "" || $("#file").val() == undefined) && ($("#ueid").val() == "" || $("#ueid").val() == undefined)) {
          $("#msg2").html("Kindly upload at least one file!");
          return false;
        } else if ($("#file").val() != "" && $("#file").val().split('.').pop().toLowerCase() != "xlsx") {
          $("#msg2").html("Please choose .xlsx SuspenseHead file.");
          return false;
        } else if ($("#ueid").val() != "" && $("#ueid").val().split('.').pop().toLowerCase() != "txt") {
          $("#msg2").html("Please choose .txt Mapping file.");
          return false;
        } else {
          $("#suspenseHeadForm").submit();
        }
      });
      $("#uploadVouchersBtn").click(function () {
        $("#msg1").html("");
        if ($("#capitalHeadFile").val() == "" || $("#capitalHeadFile").val() == undefined || $("#capitalHeadFile").val().split('.').pop().toLowerCase() != "txt") {
          $("#msg1").html("Please choose .txt CapitalHead file.");
          return false;
        } else {
          $("#capitalHeadForm").submit();
        }
      });
      $("#seperateJVBtn").click(function () {
        $("#msgJV").html("");
        var files = $("#jvFiles")[0].files;
        if (!files.length) {
          $("#msgJV").html("Please select at least one .xlsx file.");
          return false;
        }
        for (var i = 0; i < files.length; i++) {
          if (files[i].name.split('.').pop().toLowerCase() != "xlsx") {
            $("#msgJV").html("All files must be .xlsx format.");
            return false;
          }
        }
        $("#seperateJVForm").submit();
      });
    });
  </script>
</body>

</html>