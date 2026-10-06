<?php
class AimsException extends RuntimeException
{
}

/** The AIMS session is missing or no longer valid - every further request would fail the same way. */
class AimsSessionException extends AimsException
{
}

/**
 * Requests the Suspense Head report from AIMS, the same POST the AIMS "Generate Report" form makes.
 *
 * AIMS has no API login; requests carry the AIMS session cookie of a logged-in user, which is
 * supplied at runtime (pasted on the monthly page, or AIMS_COOKIE) and never stored in code.
 */
class AimsClient
{
    private $config;
    private $cookie;
    private $userAgent;

    public function __construct(array $config, $cookie, $userAgent)
    {
        $this->config = $config;
        $this->cookie = (string) $cookie;
        $this->userAgent = $userAgent !== '' ? $userAgent : 'Mozilla/5.0';
    }

    /**
     * Download one allocation's report for the period into $targetPath.
     *
     * @return string file name AIMS gave the download (e.g. SuspenseHead.xls)
     */
    public function downloadSuspenseHead($allocation, array $period, $targetPath)
    {
        if ($this->cookie === '') {
            throw new AimsSessionException('No AIMS session is set. Paste your AIMS session cookie under "AIMS session", or upload the file manually.');
        }

        $fields = array_merge(array(
            'STARTDATE' => $period['start'],
            'ENDDATE' => $period['end'],
            'cmbau' => $this->config['aims_au'],
            'chkDtlSum' => 'D',
            'chkTextExcel' => 'E',
            'allocation' => $allocation,
            'cmbVC' => '',
            'cmbcashjv' => '0',
        ), $this->config['aims_extra_fields']);

        $url = $this->config['aims_url'];
        $parts = parse_url($url);
        $origin = $parts['scheme'] . '://' . $parts['host'];
        $verify = (bool) $this->config['aims_ssl_verify'];
        $headers = array();

        $out = fopen($targetPath, 'wb');
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-IN,en-GB;q=0.9,en-US;q=0.8,en;q=0.7',
                'Upgrade-Insecure-Requests: 1',
                'Cookie: ' . $this->cookie,
                'Origin: ' . $origin,
                'Referer: ' . $this->config['aims_referer'],
            ),
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_ENCODING => '',
            CURLOPT_FILE => $out,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                $colon = strpos($line, ':');
                if ($colon !== false) {
                    $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
                }
                return strlen($line);
            },
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => (int) $this->config['aims_timeout'],
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ));
        $ok = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($out);

        if ($ok === false) {
            throw new AimsException('Could not reach AIMS: ' . $error);
        }
        if ($status >= 300 && $status < 400) {
            $location = isset($headers['location']) ? ' to ' . $headers['location'] : '';
            throw new AimsSessionException('AIMS redirected the request' . $location . '. The AIMS session has probably expired - log in to AIMS again and paste a fresh cookie.');
        }
        if ($status !== 200) {
            $title = self::pageTitle($targetPath);
            throw new AimsException('AIMS returned HTTP ' . $status . ($title !== '' ? ' ("' . $title . '")' : '') . '.');
        }
        if (filesize($targetPath) === 0) {
            throw new AimsException('AIMS returned an empty response.');
        }

        // AIMS labels the file text/plain, so judge by Content-Disposition and the content itself
        $disposition = isset($headers['content-disposition']) ? $headers['content-disposition'] : '';
        if (stripos($disposition, 'attachment') === false && self::isWebPage($targetPath)) {
            $title = self::pageTitle($targetPath);
            throw new AimsSessionException('AIMS returned a web page' . ($title !== '' ? ' ("' . $title . '")' : '') . ' instead of the Excel file. The AIMS session has probably expired - log in to AIMS again and paste a fresh cookie.');
        }

        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $disposition, $m)) {
            return basename(trim($m[1]));
        }
        return 'SuspenseHead.xls';
    }

    private static function head($path)
    {
        $handle = fopen($path, 'rb');
        $head = (string) fread($handle, 8192);
        fclose($handle);
        return $head;
    }

    private static function isWebPage($path)
    {
        $head = self::head($path);
        if (strncmp($head, "\xD0\xCF\x11\xE0", 4) === 0 || strncmp($head, 'PK', 2) === 0) {
            return false; // .xls / .xlsx
        }
        return preg_match('/<(!doctype html|html|form|body)\b/i', $head) === 1 && stripos($head, 'SUSPENSE HEAD') === false;
    }

    private static function pageTitle($path)
    {
        if (!is_file($path) || !preg_match('#<title[^>]*>(.*?)</title>#is', self::head($path), $m)) {
            return '';
        }
        return substr(trim(preg_replace('/\s+/', ' ', strip_tags($m[1]))), 0, 80);
    }
}
