<?php
/**
 * Shared setup for the monthly Daybook workflow (monthly*.php).
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../jvSeparation.php';
require_once __DIR__ . '/MonthlyRun.php';
require_once __DIR__ . '/SuspenseHeadFile.php';
require_once __DIR__ . '/AimsClient.php';
require_once __DIR__ . '/DaybookDb.php';

// The existing pages require 'vendor/autoload.php' relative to the app; keep that working after chdir()
set_include_path(dirname(__DIR__) . PATH_SEPARATOR . get_include_path());

function monthlyConfig()
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
        if (is_file(__DIR__ . '/config.local.php')) {
            $config = array_merge($config, require __DIR__ . '/config.local.php');
        }
    }
    return $config;
}

function monthlyRun($month)
{
    $config = monthlyConfig();
    return new MonthlyRun($config['runs_dir'], $month);
}

function monthlyDefaultMonth()
{
    // The Daybook is prepared early in the month for the month just closed
    return date('Y-m', strtotime('first day of last month'));
}

function monthlyStartSession()
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['monthly_csrf'])) {
        $_SESSION['monthly_csrf'] = bin2hex(random_bytes(16));
    }
}

/**
 * Clean a pasted AIMS Cookie header ("Cookie: a=1; b=2" or one cookie per line).
 */
function monthlyNormalizeCookie($raw)
{
    $cookie = preg_replace('/^\s*cookie:\s*/i', '', trim((string) $raw));
    $cookie = preg_replace('/\s*[\r\n]+\s*/', '; ', $cookie);
    if ($cookie === '' || strpos($cookie, '=') === false) {
        throw new InvalidArgumentException('That does not look like a cookie header (expected name=value; name2=value2).');
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $cookie)) {
        throw new InvalidArgumentException('The cookie contains invalid characters.');
    }
    return $cookie;
}

/**
 * The AIMS cookie to use: the one pasted in this browser session, else the server-wide setting.
 */
function monthlyAimsCookie()
{
    if (!empty($_SESSION['aims_cookie'])) {
        return $_SESSION['aims_cookie'];
    }
    $config = monthlyConfig();
    return (string) $config['aims_cookie'];
}

/**
 * What the page may show about the AIMS session: where it comes from and cookie names, never values.
 */
function monthlySessionInfo()
{
    $config = monthlyConfig();
    if (!empty($_SESSION['aims_cookie'])) {
        $source = 'pasted';
        $cookie = $_SESSION['aims_cookie'];
    } elseif ((string) $config['aims_cookie'] !== '') {
        $source = 'server';
        $cookie = $config['aims_cookie'];
    } else {
        return array('configured' => false, 'source' => 'none', 'cookieNames' => array(), 'setAt' => null);
    }
    $names = array();
    foreach (explode(';', $cookie) as $pair) {
        $name = trim(strtok($pair, '='));
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return array(
        'configured' => true,
        'source' => $source,
        'cookieNames' => $names,
        'setAt' => $source === 'pasted' && isset($_SESSION['aims_cookie_set_at']) ? $_SESSION['aims_cookie_set_at'] : null,
    );
}

/**
 * Validate a received report and store it as the allocation's source file.
 */
function monthlyIngest(MonthlyRun $run, $allocation, $path, $originalName, $via)
{
    $sheet = SuspenseHeadFile::read($path);
    $counts = SuspenseHeadFile::validate($sheet['rows'], $allocation, $run->period());
    $run->storeSource($allocation, $sheet['rows'], $path, $originalName, array(
        'heads' => $counts['heads'],
        'entries' => $counts['entries'],
        'via' => $via,
        'format' => $sheet['format'],
    ));
}

/**
 * Everything the monthly page needs to draw the run's progress.
 */
function monthlyStatePayload(MonthlyRun $run)
{
    $config = monthlyConfig();
    $state = $run->state();
    $allocations = array();
    $ready = 0;
    foreach (MonthlyRun::ALLOCATIONS as $allocation) {
        $entry = $state['allocations'][$allocation];
        $entry['code'] = $allocation;
        if (MonthlyRun::isReady($entry['status'])) {
            $ready++;
        }
        $allocations[] = $entry;
    }
    return array(
        'month' => $run->month(),
        'period' => $run->period(),
        'au' => $config['aims_au'],
        'allocations' => $allocations,
        'readyCount' => $ready,
        'total' => count(MonthlyRun::ALLOCATIONS),
        'jv' => $state['jv'],
        'complete' => $run->isComplete($state),
        'completedAt' => $state['completedAt'],
        'session' => monthlySessionInfo(),
    );
}

function monthlyEscape($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

/**
 * Plain error page for the wrapper pages.
 */
function monthlyErrorPage($message, $month = null)
{
    http_response_code(400);
    $back = 'monthly.php' . ($month !== null ? '?month=' . urlencode($month) : '');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Daybook</title>'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head>'
        . '<body class="bg-light"><div class="container py-5" style="max-width:720px"><div class="alert alert-danger">'
        . monthlyEscape($message) . '</div><a href="' . monthlyEscape($back) . '">&larr; Back to Monthly Process</a></div></body></html>';
    exit;
}

/**
 * Month and allocation from the query string for the wrapper pages; shows an error page if invalid.
 *
 * @return array [MonthlyRun, allocation or null]
 */
function monthlyRunFromQuery($needAllocation)
{
    $month = isset($_GET['month']) ? (string) $_GET['month'] : '';
    if (!MonthlyRun::isValidMonth($month)) {
        monthlyErrorPage('Choose a valid month.');
    }
    $allocation = null;
    if ($needAllocation) {
        $allocation = isset($_GET['alloc']) ? (string) $_GET['alloc'] : '';
        if (!MonthlyRun::isValidAllocation($allocation)) {
            monthlyErrorPage('Unknown allocation "' . $allocation . '".', $month);
        }
    }
    return array(monthlyRun($month), $allocation);
}
