<?php
/**
 * Shopware 5
 * Copyright (c) shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 */

namespace Shopware\Components\Session;

use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use ReturnTypeWillChange;
use SessionHandlerInterface;

/**
 * Session handler using a PDO connection to read and write data.
 *
 * It works with MySQL, PostgreSQL, Oracle, SQL Server and SQLite and implements
 * different locking strategies to handle concurrent access to the same session.
 * Locking is necessary to prevent loss of data due to race conditions and to keep
 * the session data consistent between read() and write(). With locking, requests
 * for the same session will wait until the other one finished writing. For this
 * reason it's best practice to close a session as early as possible to improve
 * concurrency. PHPs internal files session handler also implements locking.
 *
 * Attention: Since SQLite does not support row level locks but locks the whole database,
 * it means only one session can be accessed at a time. Even different sessions would wait
 * for another to finish. So saving session in SQLite should only be considered for
 * development or prototypes.
 *
 * Session data is a binary string that can contain non-printable characters like the null byte.
 * For this reason it must be saved in a binary column in the database like BLOB in MySQL.
 * Saving it in a character column could corrupt the data. You can use createTable()
 * to initialize a correctly defined table.
 *
 * @see http://php.net/sessionhandlerinterface
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Michael Williams <michael.williams@funsational.com>
 * @author Tobias Schultze <http://tobion.de>
 */
class PdoSessionHandler implements SessionHandlerInterface
{
    /**
     * No locking is done. This means sessions are prone to loss of data due to
     * race conditions of concurrent requests to the same session. The last session
     * write will win in this case. It might be useful when you implement your own
     * logic to deal with this like an optimistic approach.
     */
    public const LOCK_NONE = 0;

    /**
     * Creates an application-level lock on a session. The disadvantage is that the
     * lock is not enforced by the database and thus other, unaware parts of the
     * application could still concurrently modify the session. The advantage is it
     * does not require a transaction.
     * This mode is not available for SQLite and not yet implemented for oci and sqlsrv.
     */
    public const LOCK_ADVISORY = 1;

    /**
     * Issues a real row lock. Since it uses a transaction between opening and
     * closing a session, you have to be careful when you use same database connection
     * that you also use for your application logic. This mode is the default because
     * it's the only reliable solution across DBMSs.
     */
    public const LOCK_TRANSACTIONAL = 2;

    /**
     * @var PDO|null PDO instance or null when not connected yet
     */
    private $pdo;

    /**
     * @var string|false|null DSN string or null for session.save_path or false when lazy connection disabled
     */
    private $dsn = false;

    /**
     * @var string Database driver
     */
    private $driver;

    /**
     * @var string Table name
     */
    private $table = 'sessions';

    /**
     * @var string Column for session id
     */
    private $idCol = 'sess_id';

    /**
     * @var string Column for session data
     */
    private $dataCol = 'sess_data';

    /**
     * @var string Column for lifetime
     */
    private $expiryCol = 'sess_expiry';

    /**
     * @var string Column for timestamp
     */
    private $timeCol = 'sess_time';

    /**
     * @var string Username when lazy-connect
     */
    private $username = '';

    /**
     * @var string Password when lazy-connect
     */
    private $password = '';

    /**
     * @var array Connection options when lazy-connect
     */
    private $connectionOptions = [];

    /**
     * @var int The strategy for locking, see constants
     */
    private $lockMode = self::LOCK_TRANSACTIONAL;

    /**
     * It's an array to support multiple reads before closing which is manual, non-standard usage.
     *
     * @var PDOStatement[] An array of statements to release advisory locks
     */
    private $unlockStatements = [];

    /**
     * @var bool True when the current session exists but expired according to session.gc_maxlifetime
     */
    private $sessionExpired = false;

    /**
     * @var bool Whether a transaction is active
     */
    private $inTransaction = false;

    /**
     * @var bool Whether gc() has been called
     */
    private $gcCalled = false;

    /**
     * You can either pass an existing database connection as PDO instance or
     * pass a DSN string that will be used to lazy-connect to the database
     * when the session is actually used. Furthermore it's possible to pass null
     * which will then use the session.save_path ini setting as PDO DSN parameter.
     *
     * List of available options:
     *  * db_table: The name of the table [default: sessions]
     *  * db_id_col: The column where to store the session id [default: sess_id]
     *  * db_data_col: The column where to store the session data [default: sess_data]
     *  * db_expiry_col: The column where to store the expirytime [default: sess_expiry]
     *  * db_time_col: The column where to store the timestamp [default: sess_time]
     *  * db_username: The username when lazy-connect [default: '']
     *  * db_password: The password when lazy-connect [default: '']
     *  * db_connection_options: An array of driver-specific connection options [default: array()]
     *  * lock_mode: The strategy for locking, see constants [default: LOCK_TRANSACTIONAL]
     *
     * @param PDO|string|null $pdoOrDsn A \PDO instance or DSN string or null
     * @param array           $options  An associative array of options
     *
     * @throws InvalidArgumentException When PDO error mode is not PDO::ERRMODE_EXCEPTION
     */
    public function __construct($pdoOrDsn = null, array $options = [])
    {
        if ($pdoOrDsn instanceof PDO) {
            if ($pdoOrDsn->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
                throw new InvalidArgumentException(sprintf('"%s" requires PDO error mode attribute be set to throw Exceptions (i.e. $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION))', __CLASS__));
            }

            $this->pdo = $pdoOrDsn;
            $this->driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } else {
            $this->dsn = $pdoOrDsn;
        }

        $this->table = isset($options['db_table']) ? $options['db_table'] : $this->table;
        $this->idCol = isset($options['db_id_col']) ? $options['db_id_col'] : $this->idCol;
        $this->dataCol = isset($options['db_data_col']) ? $options['db_data_col'] : $this->dataCol;
        $this->expiryCol = isset($options['db_expiry_col']) ? $options['db_expiry_col'] : $this->expiryCol;
        $this->timeCol = isset($options['db_time_col']) ? $options['db_time_col'] : $this->timeCol;
        $this->username = isset($options['db_username']) ? $options['db_username'] : $this->username;
        $this->password = isset($options['db_password']) ? $options['db_password'] : $this->password;
        $this->connectionOptions = isset($options['db_connection_options']) ? $options['db_connection_options'] : $this->connectionOptions;
        $this->lockMode = isset($options['lock_mode']) ? $options['lock_mode'] : $this->lockMode;
    }

    /**
     * Creates the table to store sessions which can be called once for setup.
     *
     * Session ID is saved in a column of maximum length 128 because that is enough even
     * for a 512 bit configured session.hash_function like Whirlpool. Session data is
     * saved in a BLOB. One could also use a shorter inlined varbinary column
     * if one was sure the data fits into it.
     *
     * @throws PDOException    When the table already exists
     * @throws DomainException When an unsupported PDO driver is used
     */
    public function createTable()
    {
        // connect if we are not yet
        $this->getConnection();

        switch ($this->driver) {
            case 'mysql':
                // We use varbinary for the ID column because it prevents unwanted conversions:
                // - character set conversions between server and client
                // - trailing space removal
                // - case-insensitivity
                // - language processing like é == e
                $sql = "CREATE TABLE $this->table ($this->idCol VARBINARY(128) NOT NULL PRIMARY KEY, $this->dataCol BLOB NOT NULL, $this->expiryCol MEDIUMINT NOT NULL, $this->timeCol INTEGER UNSIGNED NOT NULL) COLLATE utf8_bin, ENGINE = InnoDB";
                break;
            case 'sqlite':
                $sql = "CREATE TABLE $this->table ($this->idCol TEXT NOT NULL PRIMARY KEY, $this->dataCol BLOB NOT NULL, $this->expiryCol INTEGER NOT NULL, $this->timeCol INTEGER NOT NULL)";
                break;
            case 'pgsql':
                $sql = "CREATE TABLE $this->table ($this->idCol VARCHAR(128) NOT NULL PRIMARY KEY, $this->dataCol BYTEA NOT NULL, $this->expiryCol INTEGER NOT NULL, $this->timeCol INTEGER NOT NULL)";
                break;
            case 'oci':
                $sql = "CREATE TABLE $this->table ($this->idCol VARCHAR2(128) NOT NULL PRIMARY KEY, $this->dataCol BLOB NOT NULL, $this->expiryCol INTEGER NOT NULL, $this->timeCol INTEGER NOT NULL)";
                break;
            case 'sqlsrv':
                $sql = "CREATE TABLE $this->table ($this->idCol VARCHAR(128) NOT NULL PRIMARY KEY, $this->dataCol VARBINARY(MAX) NOT NULL, $this->expiryCol INTEGER NOT NULL, $this->timeCol INTEGER NOT NULL)";
                break;
            default:
                throw new DomainException(sprintf('Creating the session table is currently not implemented for PDO driver "%s".', $this->driver));
        }

        try {
            $this->pdo->exec($sql);
        } catch (PDOException $e) {
            $this->rollback();

            throw $e;
        }
    }

    /**
     * Returns true when the current session exists but expired according to session.gc_maxlifetime.
     *
     * Can be used to distinguish between a new session and one that expired due to inactivity.
     *
     * @return bool Whether current session expired
     */
    public function isSessionExpired()
    {
        return $this->sessionExpired;
    }

    /**
     * {@inheritdoc}
     *
     * @deprecated - Native return and parameter type will be added with Shopware 5.8
     */
    #[ReturnTypeWillChange]
    public function open($savePath, $sessionName)
    {
        if ($this->pdo === null) {
            $this->connect($this->dsn ?: $savePath);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @deprecated - Native return and parameter type will be added with Shopware 5.8
     */
    #[ReturnTypeWillChange]
    public function read($sessionId)
    {
        try {
            return $this->doRead($sessionId);
        } catch (PDOException $e) {
            $this->rollback();

            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     *
     * @deprecated - Native return and parameter type will be added with Shopware 5.8
     */
    #[ReturnTypeWillChange]
    public function gc($maxlifetime)
    {
        // We delay gc() to close() so that it is executed outside the transactional and blocking read-write process.
        // This way, pruning expired sessions does not block them from being started while the current session is used.
        $this->gcCalled = true;

        return 1;
    }

    /**
     * {@inheritdoc}
     *
     * @deprecated - Native return and parameter type will be added with Shopware 5.8
     */
    #[ReturnTypeWillChange]
    public function destroy($sessionId)
    {
        // delete the record associated with this id
        $sql = "DELETE FROM $this->table WHERE $this->idCol = :id";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindParam(':id', $sessionId, PDO::PARAM_STR);
            $stmt->execute();
        } catch (PDOException $e) {
            $this->rollback();

            throw $e;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @deprecated - Native return and parameter type will be added with Shopware 5.8
     */
    #[ReturnTypeWillChange]
    public function write($sessionId, $data)
    {
        $maxlifetime = (int) \ini_get('session.gc_maxlifetime');

        try {
            // We use a single MERGE SQL query when supported by the database.
            $mergeStmt = $this->getMergeStatement($sessionId, $data, $maxlifetime);
            if ($mergeStmt !== null) {
                $mergeStmt->execute();

                return true;
            }

            $updateStmt = $this->pdo->prepare(
                "UPDATE $this->table SET $this->dataCol = :data, $this->expiryCol = :expiry, $this->timeCol = :time WHERE $this->idCol = :id"
            );
            $updateStmt->bindParam(':id', $sessionId, PDO::PARAM_STR);
            $updateStmt->bindParam(':data', $data, PDO::PARAM_LOB);
            $updateStmt->bindValue(':expiry', time() + $maxlifetime, PDO::PARAM_INT);
            $updateStmt->bindValue(':time', time(), PDO::PARAM_INT);
            $updateStmt->execute();

            // When MERGE is not supported, like in Postgres < 9.5, we have to use this approach that can result in
            // duplicate key errors when the same session is written simultaneously (given the LOCK_NONE behavior).
            // We can just catch such an error and re-execute the update. This is similar to a serializable
            // transaction with retry logic on serialization failures but without the overhead and without possible
            // false positives due to longer gap locking.
            if (!$updateStmt->rowCount()) {
                try {
                    $insertStmt = $this->pdo->prepare(
                        "INSERT INTO $this->table ($this->idCol, $this->dataCol, $this->expiryCol, $this->timeCol) VALUES (:id, :data, :expiry, :time)"
                    );
                    $insertStmt->bindParam(':id', $sessionId, PDO::PARAM_STR);
                    $insertStmt->bindParam(':data', $data, PDO::PARAM_LOB);
                    $insertStmt->bindValue(':expiry', time() + $maxlifetime, PDO::PARAM_INT);
                    $insertStmt->bindValue(':time', time(), PDO::PARAM_INT);
                    $insertStmt->execute();
                } catch (PDOException $e) {
                    // Handle integrity violation SQLSTATE 23000 (or a subclass like 23505 in Postgres) for duplicate keys
                    if (strpos($e->getCode(), '23') === 0) {
                        $updateStmt->execute();
                    } else {
                        throw $e;
                    }
                }
            }
        } catch (PDOException $e) {
            $this->rollback();

            throw $e;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @deprecated - Native return and parameter type will be added with Shopware 5.8
     */
    #[ReturnTypeWillChange]
    public function close()
    {
        $this->commit();

        while ($unlockStmt = array_shift($this->unlockStatements)) {
            $unlockStmt->execute();
        }

        if ($this->gcCalled) {
            $this->gcCalled = false;

            // delete the session records that have expired
            $sql = "DELETE FROM $this->table WHERE $this->expiryCol < :time";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':time', time(), PDO::PARAM_INT);
            $stmt->execute();
        }

        if ($this->dsn !== false) {
            $this->pdo = null; // only close lazy-connection
        }

        return true;
    }

    /**
     * Return a PDO instance.
     *
     * @return PDO
     */
    protected function getConnection()
    {
        if ($this->pdo === null) {
            $this->connect($this->dsn ?: \ini_get('session.save_path'));
        }

        return $this->pdo;
    }

    /**
     * Lazy-connects to the database.
     *
     * @param string $dsn DSN string
     */
    private function connect($dsn)
    {
        $this->pdo = new PDO($dsn, $this->username, $this->password, $this->connectionOptions);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Helper method to begin a transaction.
     *
     * Since SQLite does not support row level locks, we have to acquire a reserved lock
     * on the database immediately. Because of https://bugs.php.net/42766 we have to create
     * such a transaction manually which also means we cannot use PDO::commit or
     * PDO::rollback or PDO::inTransaction for SQLite.
     *
     * Also MySQLs default isolation, REPEATABLE READ, causes deadlock for different sessions
     * due to http://www.mysqlperformanceblog.com/2013/12/12/one-more-innodb-gap-lock-to-avoid/ .
     * So we change it to READ COMMITTED.
     */
    private function beginTransaction()
    {
        if (!$this->inTransaction) {
            if ($this->driver === 'sqlite') {
                $this->pdo->exec('BEGIN IMMEDIATE TRANSACTION');
            } else {
                if ($this->driver === 'mysql') {
                    $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                }
                $this->pdo->beginTransaction();
            }
            $this->inTransaction = true;
        }
    }

    /**
     * Helper method to commit a transaction.
     */
    private function commit()
    {
        if ($this->inTransaction) {
            try {
                // commit read-write transaction which also releases the lock
                if ($this->driver === 'sqlite') {
                    $this->pdo->exec('COMMIT');
                } else {
                    $this->pdo->commit();
                }
                $this->inTransaction = false;
            } catch (PDOException $e) {
                $this->rollback();

                throw $e;
            }
        }
    }

    /**
     * Helper method to rollback a transaction.
     */
    private function rollback()
    {
        // We only need to rollback if we are in a transaction. Otherwise the resulting
        // error would hide the real problem why rollback was called. We might not be
        // in a transaction when not using the transactional locking behavior or when
        // two callbacks (e.g. destroy and write) are invoked that both fail.
        if ($this->inTransaction) {
            if ($this->driver === 'sqlite') {
                $this->pdo->exec('ROLLBACK');
            } else {
                $this->pdo->rollBack();
            }
            $this->inTransaction = false;
        }
    }

    /**
     * Reads the session data in respect to the different locking strategies.
     *
     * We need to make sure we do not return session data that is already considered garbage according
     * to the session.gc_maxlifetime setting because gc() is called after read() and only sometimes.
     *
     * @param string $sessionId Session ID
     *
     * @return string The session data
     */
    private function doRead($sessionId)
    {
        $this->sessionExpired = false;

        if ($this->lockMode === self::LOCK_ADVISORY) {
            $this->unlockStatements[] = $this->doAdvisoryLock($sessionId);
        }

        $selectSql = $this->getSelectSql();
        $selectStmt = $this->pdo->prepare($selectSql);
        $selectStmt->bindParam(':id', $sessionId, PDO::PARAM_STR);

        while (true) {
            $selectStmt->execute();
            $sessionRows = $selectStmt->fetchAll(PDO::FETCH_NUM);

            if ($sessionRows) {
                if ($sessionRows[0][1] < time()) {
                    $this->sessionExpired = true;

                    return '';
                }

                return \is_resource($sessionRows[0][0]) ? stream_get_contents($sessionRows[0][0]) : $sessionRows[0][0];
            }

            if ($this->lockMode === self::LOCK_TRANSACTIONAL && $this->driver !== 'sqlite') {
                // Exclusive-reading of non-existent rows does not block, so we need to do an insert to block
                // until other connections to the session are committed.
                try {
                    $insertStmt = $this->pdo->prepare(
                        "INSERT INTO $this->table ($this->idCol, $this->dataCol, $this->expiryCol, $this->timeCol) VALUES (:id, :data, :expiry, :time)"
                    );
                    $insertStmt->bindParam(':id', $sessionId, PDO::PARAM_STR);
                    $insertStmt->bindValue(':data', '', PDO::PARAM_LOB);
                    $insertStmt->bindValue(':expiry', 0, PDO::PARAM_INT);
                    $insertStmt->bindValue(':time', time(), PDO::PARAM_INT);
                    $insertStmt->execute();
                } catch (PDOException $e) {
                    // Catch duplicate key error because other connection created the session already.
                    // It would only not be the case when the other connection destroyed the session.
                    if (strpos($e->getCode(), '23') === 0) {
                        // Retrieve finished session data written by concurrent connection by restarting the loop.
                        // We have to start a new transaction as a failed query will mark the current transaction as
                        // aborted in PostgreSQL and disallow further queries within it.
                        $this->rollback();
                        $this->beginTransaction();
                        continue;
                    }

                    throw $e;
                }
            }

            return '';
        }
    }

    /**
     * Executes an application-level lock on the database.
     *
     * @param string $sessionId Session ID
     *
     * @throws DomainException When an unsupported PDO driver is used
     *
     * @return PDOStatement The statement that needs to be executed later to release the lock
     *
     * @todo implement missing advisory locks
     *       - for oci using DBMS_LOCK.REQUEST
     *       - for sqlsrv using sp_getapplock with LockOwner = Session
     */
    private function doAdvisoryLock($sessionId)
    {
        switch ($this->driver) {
            case 'mysql':
                // should we handle the return value? 0 on timeout, null on error
                // we use a timeout of 50 seconds which is also the default for innodb_lock_wait_timeout
                $stmt = $this->pdo->prepare('SELECT GET_LOCK(:key, 50)');
                $stmt->bindValue(':key', $sessionId, PDO::PARAM_STR);
                $stmt->execute();

                $releaseStmt = $this->pdo->prepare('DO RELEASE_LOCK(:key)');
                $releaseStmt->bindValue(':key', $sessionId, PDO::PARAM_STR);

                return $releaseStmt;
            case 'pgsql':
                // Obtaining an exclusive session level advisory lock requires an integer key.
                // So we convert the HEX representation of the session id to an integer.
                // Since integers are signed, we have to skip one hex char to fit in the range.
                if (4 === PHP_INT_SIZE) {
                    $sessionInt1 = hexdec(substr($sessionId, 0, 7));
                    $sessionInt2 = hexdec(substr($sessionId, 7, 7));

                    $stmt = $this->pdo->prepare('SELECT pg_advisory_lock(:key1, :key2)');
                    $stmt->bindValue(':key1', $sessionInt1, PDO::PARAM_INT);
                    $stmt->bindValue(':key2', $sessionInt2, PDO::PARAM_INT);
                    $stmt->execute();

                    $releaseStmt = $this->pdo->prepare('SELECT pg_advisory_unlock(:key1, :key2)');
                    $releaseStmt->bindValue(':key1', $sessionInt1, PDO::PARAM_INT);
                    $releaseStmt->bindValue(':key2', $sessionInt2, PDO::PARAM_INT);
                } else {
                    $sessionBigInt = hexdec(substr($sessionId, 0, 15));

                    $stmt = $this->pdo->prepare('SELECT pg_advisory_lock(:key)');
                    $stmt->bindValue(':key', $sessionBigInt, PDO::PARAM_INT);
                    $stmt->execute();

                    $releaseStmt = $this->pdo->prepare('SELECT pg_advisory_unlock(:key)');
                    $releaseStmt->bindValue(':key', $sessionBigInt, PDO::PARAM_INT);
                }

                return $releaseStmt;
            case 'sqlite':
                throw new DomainException('SQLite does not support advisory locks.');
            default:
                throw new DomainException(sprintf('Advisory locks are currently not implemented for PDO driver "%s".', $this->driver));
        }
    }

    /**
     * Return a locking or nonlocking SQL query to read session information.
     *
     * @throws DomainException When an unsupported PDO driver is used
     *
     * @return string The SQL string
     */
    private function getSelectSql()
    {
        if ($this->lockMode === self::LOCK_TRANSACTIONAL) {
            $this->beginTransaction();

            switch ($this->driver) {
                case 'mysql':
                case 'oci':
                case 'pgsql':
                    return "SELECT $this->dataCol, $this->expiryCol, $this->timeCol FROM $this->table WHERE $this->idCol = :id FOR UPDATE";
                case 'sqlsrv':
                    return "SELECT $this->dataCol, $this->expiryCol, $this->timeCol FROM $this->table WITH (UPDLOCK, ROWLOCK) WHERE $this->idCol = :id";
                case 'sqlite':
                    // we already locked when starting transaction
                    break;
                default:
                    throw new DomainException(sprintf('Transactional locks are currently not implemented for PDO driver "%s".', $this->driver));
            }
        }

        return "SELECT $this->dataCol, $this->expiryCol, $this->timeCol FROM $this->table WHERE $this->idCol = :id";
    }

    /**
     * Returns a merge/upsert (i.e. insert or update) statement when supported by the database for writing session data.
     *
     * @param string $sessionId   Session ID
     * @param string $data        Encoded session data
     * @param int    $maxlifetime session.gc_maxlifetime
     *
     * @return PDOStatement|null The merge statement or null when not supported
     */
    private function getMergeStatement($sessionId, $data, $maxlifetime)
    {
        $mergeSql = null;
        switch (true) {
            case $this->driver === 'mysql':
                $mergeSql = "INSERT INTO $this->table ($this->idCol, $this->dataCol, $this->expiryCol, $this->timeCol) VALUES (:id, :data, :expiry, :time) " .
                    "ON DUPLICATE KEY UPDATE $this->dataCol = VALUES($this->dataCol), $this->expiryCol = VALUES($this->expiryCol), $this->timeCol = VALUES($this->timeCol)";
                break;
            case $this->driver === 'oci':
                // DUAL is Oracle specific dummy table
                $mergeSql = "MERGE INTO $this->table USING DUAL ON ($this->idCol = ?) " .
                    "WHEN NOT MATCHED THEN INSERT ($this->idCol, $this->dataCol, $this->expiryCol, $this->timeCol) VALUES (?, ?, ?, ?) " .
                    "WHEN MATCHED THEN UPDATE SET $this->dataCol = ?, $this->expiryCol = ?, $this->timeCol = ?";
                break;
            case $this->driver === 'sqlsrv' && version_compare($this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION), '10', '>='):
                // MERGE is only available since SQL Server 2008 and must be terminated by semicolon
                // It also requires HOLDLOCK according to http://weblogs.sqlteam.com/dang/archive/2009/01/31/UPSERT-Race-Condition-With-MERGE.aspx
                $mergeSql = "MERGE INTO $this->table WITH (HOLDLOCK) USING (SELECT 1 AS dummy) AS src ON ($this->idCol = ?) " .
                    "WHEN NOT MATCHED THEN INSERT ($this->idCol, $this->dataCol, $this->expiryCol, $this->timeCol) VALUES (?, ?, ?, ?) " .
                    "WHEN MATCHED THEN UPDATE SET $this->dataCol = ?, $this->expiryCol = ?, $this->timeCol = ?;";
                break;
            case $this->driver === 'sqlite':
                $mergeSql = "INSERT OR REPLACE INTO $this->table ($this->idCol, $this->dataCol, $this->expiryCol, $this->timeCol) VALUES (:id, :data, :expiry, :time)";
                break;
            case $this->driver === 'pgsql' && version_compare($this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION), '9.5', '>='):
                $mergeSql = "INSERT INTO $this->table ($this->idCol, $this->dataCol, $this->expiryCol, $this->timeCol) VALUES (:id, :data, :expiry, :time) " .
                    "ON CONFLICT ($this->idCol) DO UPDATE SET ($this->dataCol, $this->expiryCol, $this->timeCol) = (EXCLUDED.$this->dataCol, EXCLUDED.$this->expiryCol, EXCLUDED.$this->timeCol)";
                break;
        }

        if ($mergeSql !== null) {
            $mergeStmt = $this->pdo->prepare($mergeSql);

            if ($this->driver === 'sqlsrv' || $this->driver === 'oci') {
                $mergeStmt->bindParam(1, $sessionId, PDO::PARAM_STR);
                $mergeStmt->bindParam(2, $sessionId, PDO::PARAM_STR);
                $mergeStmt->bindParam(3, $data, PDO::PARAM_LOB);
                $mergeStmt->bindValue(4, time() + $maxlifetime, PDO::PARAM_INT);
                $mergeStmt->bindValue(5, time(), PDO::PARAM_INT);
                $mergeStmt->bindParam(6, $data, PDO::PARAM_LOB);
                $mergeStmt->bindValue(7, time() + $maxlifetime, PDO::PARAM_INT);
                $mergeStmt->bindValue(8, time(), PDO::PARAM_INT);
            } else {
                $mergeStmt->bindParam(':id', $sessionId, PDO::PARAM_STR);
                $mergeStmt->bindParam(':data', $data, PDO::PARAM_LOB);
                $mergeStmt->bindValue(':expiry', time() + $maxlifetime, PDO::PARAM_INT);
                $mergeStmt->bindValue(':time', time(), PDO::PARAM_INT);
            }

            return $mergeStmt;
        }
    }
}
