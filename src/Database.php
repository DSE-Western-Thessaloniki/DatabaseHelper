<?php

namespace Dsewth\DatabaseHelper;

use Dsewth\DatabaseHelper\Exceptions\DatabaseException;
use mysqli_stmt;

class Database {
	private \mysqli $connection = null;
	private \mysqli_stmt $statement = null;
	
	/**
	 * Create a new Database object and connect to the database
	 * using the given config.
	 * @param array $config An array of config options.
	 * Use the keys 'host', 'user', 'password', 'database', 'port' for the appropriate
	 * connection settings. All settings are optional.
	 * @link https://www.php.net/manual/en/mysqli.construct.php
	 * @return Database 
	 */
	public static function fromConfig(array $config): Database {
		$obj = new self();
		$obj->connection = new \mysqli(
			$config['host'], 
			$config['user'], 
			$config['password'],
			$config['database'],
			$config['port']
		);

		return $obj;
	}

	/**
	 * Create a new Database object and set it to use the already created mysqli
	 * connection.
	 * @param mysqli $connection An already created mysqli connection.
	 * @return Database 
	 */
	public static function fromConnection(\mysqli $connection): Database {
		$obj = new self();
		$obj->connection = $connection;
		
		return $obj;
	}

	/**
	 * Return the database connection.
	 * @return mysqli 
	 */
	public function connection(): \mysqli {
		return $this->connection;
	}

	/**
	 * Return the latest statement that was executed.
	 * @return mysqli_stmt 
	 */
	public function statement(): \mysqli_stmt {
		return $this->statement;
	}

	private function logError(DatabaseException $e) {
		syslog(LOG_ERR, $e->getMessage());
		syslog(LOG_ERR, "Trace:");
		syslog(LOG_ERR, $e->getTraceAsString());
	}

	/**
	 * Run a query and return the result. DON'T use this method if the query 
	 * includes user input. Use the query() method instead.
	 * @param string $query 
	 * @return mysqli_result|true 
	 * @throws DatabaseException 
	 */
	public function fastQuery(string $query)
	{
		// Use a private method for the actual work.
		// Watch for an exception to log.
		try {
			return $this->fastQueryPriv($query);
		} catch (DatabaseException $e) {
			$this->logError($e);
			throw $e;
		}
	}

	private function fastQueryPriv(string $query) {
		$result = $this->connection->query($query);

		if (false === $result) {
			throw new DatabaseException(
				"Error running query: '$query'. Mysql error: " . $this->connection->error,
				$this->connection->errno
			);
		}

		return $result;
	}

	/**
	 * Execute the query using the parameters passed. It returns a statement
	 * for chaining functions.
	 * @param string $query The query to be executed.
	 * @param array $params An array of parameters to be bound to the query
	 * @return mysqli_stmt 
	 * @throws DatabaseException If query preparation fails
	 */
    public function query(string $query, array $params = [])
	{
		// Use a private method for the actual work.
		// Watch for an exception to log.
		try {
			return $this->queryPriv($query, $params);
		} catch (DatabaseException $e) {
			$this->logError($e);
			throw $e;
		}
	}

	private function queryPriv(string $query, array $params = []) {
		$result = $this->connection->prepare($query);

		if (false === $result) {
			throw new DatabaseException(
				"Error preparing query: '$query'. Mysql error: " . $this->connection->error, 
				$this->connection->errno
			);
		}

		$this->statement = $result;

		// No need to check for errors as we have already done that in execute
		$this->execute($params);

		// We return a statement to achieve chaining
		return $this->statement;
	}

	/**
	 * Helper function for adding types to a query.
	 * @param array $params 
	 * @return string 
	 */
	private function getType(array $params)
	{
		return str_repeat('s', count($params));
	}

	/**
	 * Execute a prepared statement.
	 * @param array $params The parameters to be bound to the query.
	 * @return void 
	 * @throws DatabaseException 
	 */
	public function execute(array $params = [])
	{
		// Use a private method for the actual work.
		// Watch for an exception to log.
		try {
			$this->executePriv($params);
		} catch (DatabaseException $e) {
			$this->logError($e);
			throw $e;
		}
	}

	private function executePriv(array $params = []) {
		if (PHP_VERSION > "8.1") {
			$result = $this->statement->execute($params);
		} else { // For older PHP versions
			if (count($params)) {
				$result = $this->statement->bind_param($this->getType($params), ...$params);
	
				if ($result === false) {
					throw new DatabaseException(
						"Failed to bind parameters: " . print_r($params, true) . ". Mysql error: " . $this->connection->error,
						$this->connection->errno
					);
				}
			}

			
			$result = $this->statement->execute();
		}

		if ($result === false) {
			throw new DatabaseException(
				"Failed execution of query with params: '" . print_r($params, true) . "'. Mysql error: " . $this->connection->error,
				$this->connection->errno
			);
		}
	}

	/**
	 * Prepare the statement for execution.
	 * @param string $query The query to be executed.
	 * @return mysqli_stmt The prepared statement.
	 * @throws DatabaseException If the query preparation fails
	 */
	public function prepare(string $query)
	{
		// Use a private method for the actual work.
		// Watch for an exception to log.
		try {
			return $this->preparePriv($query);
		} catch (DatabaseException $e) {
			$this->logError($e);
			throw $e;
		}
	}

	private function preparePriv(string $query) {
		$this->statement = $this->connection->prepare($query);
		if (false === $this->statement) {
			throw new DatabaseException(
				"Error preparing query: '$query'. Mysql error: " . $this->connection->error, 
				$this->connection->errno
			);
		}

		return $this->statement;
	}

	/**
	 * Close the previously executed statement. Necessary if you want to create
	 * a new query before consuming all results from the previous query.
	 * @return bool true on success, false on failure.
	 */
	public function close_statement()
	{
		$result = $this->statement->close();

		return $result;
	}

	/**
	 * Start a new transaction to the database.
	 * @param int $flags 
	 * @param null|string $name 
	 * @link https://php.net/manual/en/mysqli.begin-transaction.php
	 * @return bool 
	 */
	public function begin_transaction(int $flags = 0, ?string $name = null): bool
	{
		if (isset($name)) {
			return $this->connection->begin_transaction($flags, $name);
		} else {
			return $this->connection->begin_transaction($flags);
		}
	}

	/**
	 * Commit transaction to the database.
	 * @param int $flags 
	 * @param null|string $name 
	 * @link https://php.net/manual/en/mysqli.commit.php
	 * @return bool 
	 */
	public function commit(int $flags = 0, ?string $name = null): bool
	{
		if (isset($name)) {
			return $this->connection->commit($flags, $name);
		} else {
			return $this->connection->commit($flags);
		}
	}

	/**
	 * Rollback transaction to the database.
	 * @param int $flags 
	 * @param null|string $name 
	 * @link https://php.net/manual/en/mysqli.rollback.php
	 * @return bool 
	 */
	public function rollback(int $flags = 0, ?string $name = null): bool
	{
		if (isset($name)) {
			return $this->connection->rollback($flags, $name);
		} else {
			return $this->connection->rollback($flags);
		}
	}

	/**
	 * Close the connection to the database.
	 * @return bool true on success, false on failure.
	 */
	public function close(): bool {
		return $this->connection->close();
	}
}