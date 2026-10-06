<?php
/**
 * One month's Daybook run, kept under <runs_dir>/<YYYY-MM>/:
 *
 *   state.json                        status of each allocation and of JV separation
 *   <alloc>/BOOK.xlsx                 converted Suspense Head file, read by view.php / exportDayBookExcel.php
 *   <alloc>/original.<ext>            the file exactly as received from AIMS (or uploaded)
 *   daybook-generated-files/<n>.xlsx  the same nine files numbered 1..9, read by the JV separation
 *   outputs/                          Daybook Excel per allocation and the ZIP of all outputs
 *
 * Everything received, processed and generated is also recorded in MySQL (DaybookDb).
 *
 * The existing pages read fixed relative paths (BOOK.xlsx, daybook-generated-files/), so the
 * wrappers chdir() into these folders and include those pages unchanged.
 */
class MonthlyRun
{
    // The fixed Suspense Head allocations, in processing order (also the 1..9 file numbering)
    const ALLOCATIONS = array('20', '21', '26', '28', '29', '23', '33', '43', '53');

    const PENDING = 'pending';
    const DOWNLOADING = 'downloading';
    const DOWNLOADED = 'downloaded';
    const COMPLETED = 'completed';
    const FAILED = 'failed';

    // A download still marked as running after this long was interrupted (e.g. PHP timeout)
    const STALE_DOWNLOAD_SECONDS = 900;

    private $month;
    private $dir;

    public function __construct($runsDir, $month)
    {
        if (!self::isValidMonth($month)) {
            throw new InvalidArgumentException('Invalid month "' . $month . '" (expected YYYY-MM).');
        }
        $this->month = $month;
        $this->dir = rtrim($runsDir, '/\\') . '/' . $month;
    }

    public static function isValidMonth($month)
    {
        return is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1;
    }

    public static function isValidAllocation($allocation)
    {
        return in_array((string) $allocation, self::ALLOCATIONS, true);
    }

    public function month()
    {
        return $this->month;
    }

    /**
     * Report period for the month, e.g. 1/9/2026 to 30/9/2026 (the AIMS form's date format).
     */
    public function period()
    {
        $first = DateTime::createFromFormat('!Y-m-d', $this->month . '-01');
        return array(
            'start' => $first->format('j/n/Y'),
            'end' => $first->format('t/n/Y'),
            'startDisplay' => $first->format('d/m/Y'),
            'endDisplay' => $first->format('t/m/Y'),
            'label' => $first->format('F Y'),
        );
    }

    public function dir()
    {
        return $this->dir;
    }

    public function allocationDir($allocation)
    {
        return $this->dir . '/' . $allocation;
    }

    public function bookPath($allocation)
    {
        return $this->allocationDir($allocation) . '/BOOK.xlsx';
    }

    public function jvDir()
    {
        return $this->dir . '/daybook-generated-files';
    }

    private function jvPath($allocation)
    {
        return $this->jvDir() . '/' . (array_search((string) $allocation, self::ALLOCATIONS, true) + 1) . '.xlsx';
    }

    public function hasSource($allocation)
    {
        return is_file($this->bookPath($allocation));
    }

    public static function isReady($status)
    {
        return $status === self::DOWNLOADED || $status === self::COMPLETED;
    }

    /**
     * Complete only when every allocation is processed and JV separation has run on all of them.
     */
    public function isComplete(array $state)
    {
        if (empty($state['completedAt']) || $state['jv'] === null) {
            return false;
        }
        foreach (self::ALLOCATIONS as $allocation) {
            if ($state['allocations'][$allocation]['status'] !== self::COMPLETED) {
                return false;
            }
        }
        return true;
    }

    public function state()
    {
        if (!is_dir($this->dir)) {
            return $this->defaultState();
        }
        $self = $this;
        return $this->withLock(function () use ($self) {
            return $self->readState();
        });
    }

    /**
     * Start (re)downloading an allocation: its previous files are removed so a stale copy can
     * never be processed, and the month is no longer complete.
     */
    public function markDownloading($allocation)
    {
        DaybookDb::setAllocationStatus($this, $allocation, self::DOWNLOADING);
        DaybookDb::logEvent($this, $allocation, 'download_started');
        $this->removeSource($allocation);
        $this->update(function ($state) use ($allocation) {
            $state = MonthlyRun::invalidate($state);
            $state['allocations'][$allocation] = array(
                'status' => MonthlyRun::DOWNLOADING,
                'message' => '',
                'updatedAt' => time(),
            );
            return $state;
        });
    }

    public function markFailed($allocation, $message)
    {
        try {
            DaybookDb::setAllocationStatus($this, $allocation, self::FAILED, $message);
            DaybookDb::logEvent($this, $allocation, 'failed', $message);
        } catch (Exception $e) {
            $message .= ' (Also could not record this in the database: ' . $e->getMessage() . ')';
        }
        $this->removeSource($allocation);
        $this->update(function ($state) use ($allocation, $message) {
            $state = MonthlyRun::invalidate($state);
            $state['allocations'][$allocation] = array(
                'status' => MonthlyRun::FAILED,
                'message' => $message,
                'updatedAt' => time(),
            );
            return $state;
        });
    }

    /**
     * Save a validated report: converted BOOK.xlsx, the original file, and its numbered copy for JV separation.
     */
    public function storeSource($allocation, array $rows, $rawPath, $originalName, array $details)
    {
        $dir = $this->allocationDir($allocation);
        self::ensureDir($dir);
        self::ensureDir($this->jvDir());

        SuspenseHeadFile::writeXlsx($rows, $this->bookPath($allocation));

        $extension = strtolower(preg_replace('/[^A-Za-z0-9]/', '', pathinfo($originalName, PATHINFO_EXTENSION)));
        foreach (glob($dir . '/original.*') as $old) {
            unlink($old);
        }
        if (!copy($rawPath, $dir . '/original.' . ($extension !== '' ? $extension : 'xls'))
            || !copy($this->bookPath($allocation), $this->jvPath($allocation))) {
            throw new RuntimeException('Could not save the files for allocation ' . $allocation . '.');
        }

        $details['sourceName'] = $originalName;
        DaybookDb::storeSource($this, $allocation, $rawPath, $originalName, $this->bookPath($allocation), $rows, $details);
        DaybookDb::logEvent($this, $allocation, 'downloaded', $originalName . ' via ' . $details['via'] . ', ' . $details['entries'] . ' entries');

        $this->update(function ($state) use ($allocation, $originalName, $details) {
            $state = MonthlyRun::invalidate($state);
            $state['allocations'][$allocation] = array_merge($details, array(
                'status' => MonthlyRun::DOWNLOADED,
                'message' => '',
                'sourceName' => $originalName,
                'updatedAt' => time(),
            ));
            return $state;
        });
    }

    /**
     * Steps 2 and 3, only once all nine files are present: run the existing JV separation over
     * them and confirm each converted file opens the way view.php reads it.
     */
    public function finalize()
    {
        $state = $this->state();
        $missing = array();
        foreach (self::ALLOCATIONS as $allocation) {
            if (!self::isReady($state['allocations'][$allocation]['status']) || !$this->hasSource($allocation) || !is_file($this->jvPath($allocation))) {
                $missing[] = $allocation;
            }
        }
        if ($missing) {
            throw new RuntimeException('Cannot continue: allocation ' . implode(', ', $missing) . ' not downloaded yet (' . (count(self::ALLOCATIONS) - count($missing)) . ' / ' . count(self::ALLOCATIONS) . ' ready).');
        }

        list($jvNumbers, $nonJvNumbers) = separateJVAndNonJVNumbers($this->jvDir());

        $config = monthlyConfig();
        $excelFiles = array();
        foreach (self::ALLOCATIONS as $allocation) {
            SuspenseHeadFile::assertReadableByDaybook($this->bookPath($allocation));
            $excelFiles[$allocation] = $this->daybookExcelPath($allocation);
            $this->exportDaybookExcel($config['php_cli'], $allocation, $excelFiles[$allocation]);
        }

        DaybookDb::storeCompletion($this, $jvNumbers, $nonJvNumbers, $excelFiles);
        DaybookDb::logEvent($this, null, 'completed', count($jvNumbers) . ' JV CO6, ' . count($nonJvNumbers) . ' allocation-sheet CO6');

        return $this->update(function ($state) use ($jvNumbers, $nonJvNumbers) {
            foreach (MonthlyRun::ALLOCATIONS as $allocation) {
                $state['allocations'][$allocation]['status'] = MonthlyRun::COMPLETED;
            }
            $state['jv'] = array('jvNumbers' => $jvNumbers, 'nonJvNumbers' => $nonJvNumbers);
            $state['completedAt'] = time();
            return $state;
        });
    }

    /**
     * Restart the month from scratch.
     */
    public function reset()
    {
        DaybookDb::deleteRun($this);
        DaybookDb::logEvent($this, null, 'restarted');
        if (is_dir($this->dir)) {
            self::removeTree($this->dir);
        }
    }

    /**
     * ZIP of every output: Daybook Excel per allocation (made by the existing exportDayBookExcel.php),
     * the Suspense Head files, and the JV / CO6 lists.
     */
    public function buildOutputsZip($phpBinary)
    {
        $state = $this->state();
        if (!$this->isComplete($state)) {
            throw new RuntimeException('The month is not complete yet; outputs are only available once all ' . count(self::ALLOCATIONS) . ' allocations are processed.');
        }

        $outDir = $this->dir . '/outputs';
        self::ensureDir($outDir);
        $zipPath = $outDir . '/Daybook_' . $this->month . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the ZIP file.');
        }
        foreach (self::ALLOCATIONS as $allocation) {
            $excel = $this->daybookExcelPath($allocation);
            if (!is_file($excel)) {
                $this->exportDaybookExcel($phpBinary, $allocation, $excel);
            }
            $zip->addFile($excel, 'Daybook Excel/' . basename($excel));
            $zip->addFile($this->bookPath($allocation), 'Suspense Head files/' . $allocation . '.xlsx');
            foreach (glob($this->allocationDir($allocation) . '/original.*') as $original) {
                $zip->addFile($original, 'Suspense Head files (as received)/' . $allocation . ' - ' . $state['allocations'][$allocation]['sourceName']);
            }
        }
        $zip->addFromString('JV and CO6/JV CO6 numbers.txt', implode("\r\n", $state['jv']['jvNumbers']) . "\r\n");
        $zip->addFromString('JV and CO6/CO6 numbers for allocation sheets.txt', implode("\r\n", $state['jv']['nonJvNumbers']) . "\r\n");
        $period = $this->period();
        $zip->addFromString('README.txt', implode("\r\n", array(
            'Daybook outputs for ' . $period['label'] . ' (' . $period['startDisplay'] . ' to ' . $period['endDisplay'] . ')',
            '',
            'Daybook Excel/                     one workbook per allocation (same as EXPORT ALL EXCEL)',
            'Suspense Head files/               the nine reports used, as read by the Daybook',
            'Suspense Head files (as received)/ the reports exactly as downloaded from AIMS / uploaded',
            'JV and CO6/                        JV CO6 numbers and CO6 numbers for allocation sheets',
            '',
            'Last Month (opening) figures are entered on each allocation\'s View page, as before.',
            'For PDF: open the allocation\'s View page and use PRINT ALL / PRINT SUMMARY -> Save as PDF.',
            '',
        )));
        if (!$zip->close()) {
            throw new RuntimeException('Could not write the ZIP file.');
        }
        return $zipPath;
    }

    private function daybookExcelPath($allocation)
    {
        return $this->dir . '/outputs/DayBook_' . $this->month . '_Allocation_' . $allocation . '.xlsx';
    }

    /**
     * Run the existing exportDayBookExcel.php (mode=all) for one allocation in a separate PHP
     * process, since it sends its workbook to output and exits.
     */
    private function exportDaybookExcel($phpBinary, $allocation, $target)
    {
        self::ensureDir(dirname($target));
        $process = proc_open(
            array($phpBinary, '-d', 'memory_limit=512M', __DIR__ . '/cli_export.php', $this->allocationDir($allocation)),
            array(1 => array('file', $target, 'wb'), 2 => array('pipe', 'w')),
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start PHP to export allocation ' . $allocation . '.');
        }
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $head = is_file($target) ? (string) file_get_contents($target, false, null, 0, 300) : '';
        if ($exitCode !== 0 || strncmp($head, 'PK', 2) !== 0) {
            throw new RuntimeException('Excel export failed for allocation ' . $allocation . ': ' . trim(substr($errors !== '' ? $errors : $head, 0, 300)));
        }
    }

    /**
     * @internal public only for use inside closures
     */
    public function readState()
    {
        $state = $this->defaultState();
        $file = $this->dir . '/state.json';
        if (is_file($file)) {
            $saved = json_decode((string) file_get_contents($file), true);
            if (is_array($saved)) {
                foreach (self::ALLOCATIONS as $allocation) {
                    if (isset($saved['allocations'][$allocation])) {
                        $state['allocations'][$allocation] = $saved['allocations'][$allocation];
                    }
                }
                $state['jv'] = isset($saved['jv']) ? $saved['jv'] : null;
                $state['completedAt'] = isset($saved['completedAt']) ? $saved['completedAt'] : null;
            }
        }

        foreach (self::ALLOCATIONS as $allocation) {
            $entry = &$state['allocations'][$allocation];
            if ($entry['status'] === self::DOWNLOADING && time() - (int) $entry['updatedAt'] > self::STALE_DOWNLOAD_SECONDS) {
                $entry = array('status' => self::FAILED, 'message' => 'The download was interrupted. Retry it.', 'updatedAt' => time());
            } elseif (self::isReady($entry['status']) && !$this->hasSource($allocation)) {
                $entry = array('status' => self::FAILED, 'message' => 'The downloaded file is missing. Download it again.', 'updatedAt' => time());
                $state['completedAt'] = null;
            }
            unset($entry);
        }
        return $state;
    }

    /**
     * @internal Any change to a source file voids the JV separation and the month's completion.
     */
    public static function invalidate(array $state)
    {
        $state['jv'] = null;
        $state['completedAt'] = null;
        foreach ($state['allocations'] as $allocation => $entry) {
            if ($entry['status'] === self::COMPLETED) {
                $state['allocations'][$allocation]['status'] = self::DOWNLOADED;
            }
        }
        return $state;
    }

    private function defaultState()
    {
        $allocations = array();
        foreach (self::ALLOCATIONS as $allocation) {
            $allocations[$allocation] = array('status' => self::PENDING, 'message' => '', 'updatedAt' => null);
        }
        return array('allocations' => $allocations, 'jv' => null, 'completedAt' => null);
    }

    private function update($change)
    {
        $self = $this;
        $file = $this->dir . '/state.json';
        return $this->withLock(function () use ($self, $change, $file) {
            $state = $change($self->readState());
            $tmp = $file . '.tmp';
            if (file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT)) === false || !rename($tmp, $file)) {
                throw new RuntimeException('Could not save the month\'s progress.');
            }
            return $state;
        });
    }

    private function withLock($callback)
    {
        self::ensureDir($this->dir);
        $lock = fopen($this->dir . '/state.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function removeSource($allocation)
    {
        foreach (array_merge(array($this->bookPath($allocation), $this->jvPath($allocation), $this->daybookExcelPath($allocation)), glob($this->allocationDir($allocation) . '/original.*') ?: array()) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private static function ensureDir($dir)
    {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create folder ' . $dir . '.');
        }
    }

    private static function removeTree($dir)
    {
        foreach (array_diff(scandir($dir), array('.', '..')) as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? self::removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
