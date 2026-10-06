<?php
/**
 * JSON actions behind monthly.php. All changes are POST with the page's CSRF token.
 *
 *   status                      progress of a month
 *   setCookie / clearCookie     AIMS session for this browser session
 *   download   (alloc)          fetch one allocation's report from AIMS
 *   upload     (alloc, file)    use a report downloaded by hand instead
 *   finalize                    all nine present -> JV separation + Daybook
 *   reset                       restart the month
 *   zip        (GET)            all outputs of a completed month
 */
require __DIR__ . '/monthly/bootstrap.php';

monthlyStartSession();
$action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : 'status';

function monthlyJson(array $data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

function monthlyRequestRun()
{
    $month = isset($_REQUEST['month']) ? (string) $_REQUEST['month'] : '';
    if (!MonthlyRun::isValidMonth($month)) {
        throw new InvalidArgumentException('Choose a valid month.');
    }
    return monthlyRun($month);
}

function monthlyRequestAllocation()
{
    $allocation = isset($_POST['alloc']) ? (string) $_POST['alloc'] : '';
    if (!MonthlyRun::isValidAllocation($allocation)) {
        throw new InvalidArgumentException('Unknown allocation "' . $allocation . '".');
    }
    return $allocation;
}

try {
    if ($action === 'zip') {
        $run = monthlyRequestRun();
        session_write_close();
        set_time_limit(600);
        try {
            $config = monthlyConfig();
            $zip = $run->buildOutputsZip($config['php_cli']);
        } catch (Exception $e) {
            monthlyErrorPage($e->getMessage(), $run->month());
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($zip) . '"');
        header('Content-Length: ' . filesize($zip));
        header('Cache-Control: no-store');
        readfile($zip);
        exit;
    }

    if ($action !== 'status') {
        $token = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['monthly_csrf'], $token)) {
            monthlyJson(array('ok' => false, 'error' => 'This page has expired. Reload it and try again.'), 403);
        }
    }

    switch ($action) {
        case 'status':
            $run = monthlyRequestRun();
            session_write_close();
            monthlyJson(array('ok' => true, 'state' => monthlyStatePayload($run)));

        case 'setCookie':
            $_SESSION['aims_cookie'] = monthlyNormalizeCookie(isset($_POST['cookie']) ? $_POST['cookie'] : '');
            $_SESSION['aims_cookie_set_at'] = time();
            monthlyJson(array('ok' => true, 'session' => monthlySessionInfo()));

        case 'clearCookie':
            unset($_SESSION['aims_cookie'], $_SESSION['aims_cookie_set_at']);
            monthlyJson(array('ok' => true, 'session' => monthlySessionInfo()));

        case 'download':
            $run = monthlyRequestRun();
            $allocation = monthlyRequestAllocation();
            $cookie = monthlyAimsCookie();
            $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
            session_write_close();

            $config = monthlyConfig();
            set_time_limit((int) $config['aims_timeout'] + 120);
            $run->markDownloading($allocation);
            $raw = tempnam(sys_get_temp_dir(), 'aims');
            $error = null;
            try {
                $client = new AimsClient($config, $cookie, $userAgent);
                $name = $client->downloadSuspenseHead($allocation, $run->period(), $raw);
                monthlyIngest($run, $allocation, $raw, $name, 'AIMS');
            } catch (Throwable $e) {
                $error = $e;
            }
            if (is_file($raw)) {
                unlink($raw);
            }
            if ($error === null) {
                monthlyJson(array('ok' => true, 'state' => monthlyStatePayload($run)));
            }
            $run->markFailed($allocation, $error->getMessage());
            monthlyJson(array(
                'ok' => false,
                'error' => $error->getMessage(),
                'errorType' => $error instanceof AimsSessionException ? 'session' : 'download',
                'state' => monthlyStatePayload($run),
            ));

        case 'upload':
            $run = monthlyRequestRun();
            $allocation = monthlyRequestAllocation();
            session_write_close();

            $file = isset($_FILES['file']) ? $_FILES['file'] : null;
            if ($file === null || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                $code = $file === null ? UPLOAD_ERR_NO_FILE : $file['error'];
                throw new RuntimeException($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE ? 'The file is too large to upload.' : 'No file was received.');
            }
            $run->markDownloading($allocation);
            try {
                monthlyIngest($run, $allocation, $file['tmp_name'], basename($file['name']), 'upload');
                monthlyJson(array('ok' => true, 'state' => monthlyStatePayload($run)));
            } catch (Throwable $e) {
                $run->markFailed($allocation, $e->getMessage());
                monthlyJson(array('ok' => false, 'error' => $e->getMessage(), 'errorType' => 'upload', 'state' => monthlyStatePayload($run)));
            }

        case 'finalize':
            $run = monthlyRequestRun();
            session_write_close();
            set_time_limit(600);
            try {
                $run->finalize();
                monthlyJson(array('ok' => true, 'state' => monthlyStatePayload($run)));
            } catch (Throwable $e) {
                monthlyJson(array('ok' => false, 'error' => $e->getMessage(), 'state' => monthlyStatePayload($run)));
            }

        case 'reset':
            $run = monthlyRequestRun();
            session_write_close();
            $run->reset();
            monthlyJson(array('ok' => true, 'state' => monthlyStatePayload($run)));

        default:
            monthlyJson(array('ok' => false, 'error' => 'Unknown action.'), 400);
    }
} catch (Throwable $e) {
    monthlyJson(array('ok' => false, 'error' => $e->getMessage()), 400);
}
