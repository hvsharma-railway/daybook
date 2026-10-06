<?php
/**
 * MySQL record of the monthly workflow: runs, allocation status, file contents, parsed
 * Suspense Head entries, JV / CO6 numbers and an event log.
 *
 * The files on disk remain the working copies the existing pages read; the database keeps
 * everything that was received, processed and generated.
 */
class DaybookDb
{
    private static $pdo = null;

    public static function pdo()
    {
        if (self::$pdo === null) {
            $config = monthlyConfig();
            try {
                $pdo = new PDO(
                    'mysql:host=' . $config['db_host'] . ';port=' . $config['db_port'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
                    $config['db_user'],
                    $config['db_password'],
                    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
                );
            } catch (PDOException $e) {
                throw new RuntimeException('Could not connect to the database: ' . $e->getMessage());
            }
            foreach (array_filter(array_map('trim', explode(';', file_get_contents(__DIR__ . '/schema.sql')))) as $statement) {
                $statement = trim(preg_replace('/^--.*$/m', '', $statement));
                if ($statement !== '') {
                    $pdo->exec($statement);
                }
            }
            self::$pdo = $pdo;
        }
        return self::$pdo;
    }

    /**
     * Id of the month's run row, created on first use.
     */
    public static function runId(MonthlyRun $run)
    {
        $config = monthlyConfig();
        $period = $run->period();
        $pdo = self::pdo();
        $pdo->prepare('INSERT INTO daybook_runs (month, period_start, period_end, au) VALUES (?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)')
            ->execute(array($run->month(), $period['start'], $period['end'], $config['aims_au']));
        return (int) $pdo->lastInsertId();
    }

    public static function setAllocationStatus(MonthlyRun $run, $allocation, $status, $message = null, array $details = array())
    {
        $runId = self::runId($run);
        self::pdo()->prepare('INSERT INTO daybook_run_allocations
                (run_id, allocation, status, message, via, source_name, source_format, sub_allocation_heads, entry_count)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), message = VALUES(message), via = VALUES(via),
                    source_name = VALUES(source_name), source_format = VALUES(source_format),
                    sub_allocation_heads = VALUES(sub_allocation_heads), entry_count = VALUES(entry_count)')
            ->execute(array(
                $runId, $allocation, $status, $message,
                isset($details['via']) ? $details['via'] : null,
                isset($details['sourceName']) ? $details['sourceName'] : null,
                isset($details['format']) ? $details['format'] : null,
                isset($details['heads']) ? $details['heads'] : null,
                isset($details['entries']) ? $details['entries'] : null,
            ));
        // Any change to an allocation re-opens the month
        self::pdo()->prepare("UPDATE daybook_runs SET status = 'in_progress', completed_at = NULL WHERE id = ?")->execute(array($runId));
    }

    /**
     * Replace an allocation's stored files and entries with a newly received report.
     */
    public static function storeSource(MonthlyRun $run, $allocation, $originalPath, $originalName, $convertedPath, array $rows, array $details)
    {
        $runId = self::runId($run);
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM daybook_files WHERE run_id = ? AND allocation = ?')->execute(array($runId, $allocation));
            $pdo->prepare('DELETE FROM suspense_head_entries WHERE run_id = ? AND allocation = ?')->execute(array($runId, $allocation));
            self::insertFile($runId, $allocation, 'original', $originalPath, $originalName, 'application/vnd.ms-excel');
            self::insertFile($runId, $allocation, 'converted', $convertedPath, $allocation . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            self::insertEntries($runId, $allocation, $rows);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        self::setAllocationStatus($run, $allocation, MonthlyRun::DOWNLOADED, null, $details);
    }

    /**
     * Record a completed month: JV separation result, generated Daybook Excel files, statuses.
     */
    public static function storeCompletion(MonthlyRun $run, array $jvNumbers, array $nonJvNumbers, array $excelFiles)
    {
        $runId = self::runId($run);
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM daybook_co6_numbers WHERE run_id = ?')->execute(array($runId));
            $insert = $pdo->prepare('INSERT INTO daybook_co6_numbers (run_id, kind, position, co6_number) VALUES (?, ?, ?, ?)');
            foreach (array('jv' => $jvNumbers, 'allocation_sheet' => $nonJvNumbers) as $kind => $numbers) {
                foreach (array_values($numbers) as $i => $number) {
                    $insert->execute(array($runId, $kind, $i + 1, $number));
                }
            }
            $pdo->prepare("DELETE FROM daybook_files WHERE run_id = ? AND kind = 'daybook_excel'")->execute(array($runId));
            foreach ($excelFiles as $allocation => $path) {
                self::insertFile($runId, $allocation, 'daybook_excel', $path, basename($path), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            }
            $pdo->prepare("UPDATE daybook_run_allocations SET status = 'completed', message = NULL WHERE run_id = ?")->execute(array($runId));
            $pdo->prepare("UPDATE daybook_runs SET status = 'completed', completed_at = NOW(), jv_count = ?, allocation_sheet_count = ? WHERE id = ?")
                ->execute(array(count($jvNumbers), count($nonJvNumbers), $runId));
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Restart: drop the month's data (the event log is kept).
     */
    public static function deleteRun(MonthlyRun $run)
    {
        self::pdo()->prepare('DELETE FROM daybook_runs WHERE month = ?')->execute(array($run->month()));
    }

    public static function logEvent(MonthlyRun $run, $allocation, $event, $message = null)
    {
        self::pdo()->prepare('INSERT INTO daybook_events (month, allocation, event, message) VALUES (?, ?, ?, ?)')
            ->execute(array($run->month(), $allocation, $event, $message));
    }

    private static function insertFile($runId, $allocation, $kind, $path, $name, $mime)
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Could not read ' . $name . ' to store it.');
        }
        $statement = self::pdo()->prepare('INSERT INTO daybook_files (run_id, allocation, kind, file_name, mime_type, size_bytes, sha256, content)
                                           VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->bindValue(1, $runId, PDO::PARAM_INT);
        $statement->bindValue(2, $allocation);
        $statement->bindValue(3, $kind);
        $statement->bindValue(4, $name);
        $statement->bindValue(5, $mime);
        $statement->bindValue(6, strlen($content), PDO::PARAM_INT);
        $statement->bindValue(7, hash('sha256', $content));
        $statement->bindValue(8, $content, PDO::PARAM_LOB);
        $statement->execute();
    }

    /**
     * Transaction rows under each "ALLOCATION : ..." heading, read the same way view.php reads them.
     */
    private static function insertEntries($runId, $allocation, array $rows)
    {
        $insert = self::pdo()->prepare('INSERT INTO suspense_head_entries
            (run_id, allocation, sub_allocation, row_no, section, co6_number, co7_number, book_date, party_name, bill_desc,
             debit, credit, spu, contract_id, tan_number, unique_work_id, is_jv, is_sys_generated)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $subAllocation = '';
        foreach ($rows as $index => $t) {
            $first = isset($t[0]) ? (string) $t[0] : '';
            if (strpos($first, 'ALLOCATION ') !== false) {
                $subAllocation = substr($first, 13, 8);
                continue;
            }
            if ($first === '' || $first === 'SECTION' || $subAllocation === '') {
                continue;
            }
            $cell = function ($i) use ($t) {
                return isset($t[$i]) && $t[$i] !== '' ? (string) $t[$i] : null;
            };
            $insert->execute(array(
                $runId, $allocation, $subAllocation, $index + 1,
                $cell(0), $cell(1), $cell(2), $cell(3), $cell(4), $cell(5),
                self::amount($cell(6)), self::amount($cell(7)),
                $cell(8), $cell(9), $cell(10), $cell(11),
                strpos($first, 'JV') !== false ? 1 : 0,
                strpos((string) $cell(4), 'SYS-GENERATED') !== false ? 1 : 0,
            ));
        }
    }

    private static function amount($value)
    {
        $value = $value === null ? '' : str_replace(',', '', trim($value));
        return is_numeric($value) ? $value : null;
    }
}
