<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OC\Setup;

use Doctrine\DBAL\Platforms\MySQL80Platform;
use OC\DB\ConnectionAdapter;
use OC\DB\MySqlTools;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

class MySQL extends AbstractDatabase {
	public $dbprettyname = 'MySQL/MariaDB';

	public function setupDatabase() {
		//check if the database user has admin right
		$connection = $this->connect(['dbname' => null]);

		// detect mb4
		$tools = new MySqlTools();
		if ($tools->supports4ByteCharset(new ConnectionAdapter($connection))) {
			$this->config->setValue('mysql.utf8mb4', true);
			$connection = $this->connect(['dbname' => null]);
		}

		if ($this->tryCreateDbUser) {
			$this->createSpecificUser('oc_admin', new ConnectionAdapter($connection));
		}

		$this->config->setValues([
			'dbuser' => $this->dbUser,
			'dbpassword' => $this->dbPassword,
		]);

		//create the database
		$this->createDatabase($connection);

		//fill the database if needed
		$query = 'select count(*) from information_schema.tables where table_schema=? AND table_name = ?';
		$connection->executeQuery($query, [$this->dbName, $this->tablePrefix . 'users']);

		$connection->close();
		$connection = $this->connect();
		try {
			$connection->connect();
		} catch (\Exception $e) {
			$this->logger->error($e->getMessage(), [
				'exception' => $e,
			]);
			throw new \OC\DatabaseSetupException($this->trans->t('MySQL Login and/or password not valid'),
				$this->trans->t('You need to enter details of an existing account.'), 0, $e);
		}
	}

	/**
	 * @param \OC\DB\Connection $connection
	 */
	private function createDatabase($connection): void {
		try {
			$name = $this->dbName;
			$user = $this->dbUser;
			//we can't use OC_DB functions here because we need to connect as the administrative user.
			$characterSet = $this->config->getValue('mysql.utf8mb4', false) ? 'utf8mb4' : 'utf8';
			$query = "CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET $characterSet COLLATE {$characterSet}_bin;";
			$connection->executeUpdate($query);
		} catch (\Exception $ex) {
			$this->logger->error('Database creation failed.', [
				'exception' => $ex,
				'app' => 'mysql.setup',
			]);
			return;
		}

		try {
			//this query will fail if there aren't the right permissions, ignore the error
			$query = "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, EVENT, TRIGGER ON `$name` . * TO '$user'";
			$connection->executeUpdate($query);
		} catch (\Exception $ex) {
			$this->logger->debug('Could not automatically grant privileges, this can be ignored if database user already had privileges.', [
				'exception' => $ex,
				'app' => 'mysql.setup',
			]);
		}
	}

	/**
	 * @param IDBConnection $connection
	 * @throws \OC\DatabaseSetupException
	 */
	private function createDBUser($connection): void {
		try {
			$name = $this->dbUser;
			$password = $this->dbPassword;
			// we need to create 2 accounts, one for global use and one for local user. if we don't specify the local one,
			// the anonymous user would take precedence when there is one.

			if ($connection->getDatabasePlatform() instanceof Mysql80Platform) {
				$query = "CREATE USER ?@'localhost' IDENTIFIED WITH mysql_native_password BY ?";
				$connection->executeUpdate($query, [$name,$password]);
				$query = "CREATE USER ?@'%' IDENTIFIED WITH mysql_native_password BY ?";
				$connection->executeUpdate($query, [$name,$password]);
			} else {
				$query = "CREATE USER ?@'localhost' IDENTIFIED BY ?";
				$connection->executeUpdate($query, [$name,$password]);
				$query = "CREATE USER ?@'%' IDENTIFIED BY ?";
				$connection->executeUpdate($query, [$name,$password]);
			}
		} catch (\Exception $ex) {
			$this->logger->error('Database user creation failed.', [
				'exception' => $ex,
				'app' => 'mysql.setup',
			]);
			throw $ex;
		}
	}

	/**
	 * @param string $username
	 * @param IDBConnection $connection
	 */
	private function createSpecificUser($username, $connection): void {
		$rootUser = $this->dbUser;
		$rootPassword = $this->dbPassword;

		//create a random password so we don't need to store the admin password in the config file
		$saveSymbols = str_replace(['\"', '\\', '\'', '`'], '', ISecureRandom::CHAR_SYMBOLS);
		$password = $this->random->generate(22, ISecureRandom::CHAR_ALPHANUMERIC . $saveSymbols)
			. $this->random->generate(2, ISecureRandom::CHAR_UPPER)
			. $this->random->generate(2, ISecureRandom::CHAR_LOWER)
			. $this->random->generate(2, ISecureRandom::CHAR_DIGITS)
			. $this->random->generate(2, $saveSymbols);
		$this->dbPassword = str_shuffle($password);

		try {
			//user already specified in config
			$oldUser = $this->config->getValue('dbuser', false);

			//we don't have a dbuser specified in config
			if ($this->dbUser !== $oldUser) {
				//add prefix to the admin username to prevent collisions
				$adminUser = substr('oc_' . $username, 0, 16);

				$i = 1;
				while (true) {
					//this should be enough to check for admin rights in mysql
					$query = 'SELECT user FROM mysql.user WHERE user=?';
					$result = $connection->executeQuery($query, [$adminUser]);

					//current dbuser has admin rights
					$data = $result->fetchAll();
					$result->closeCursor();
					//new dbuser does not exist
					if (count($data) === 0) {
						//use the admin login data for the new database user
						$this->dbUser = $adminUser;
						$this->createDBUser($connection);

						break;
					} else {
						//repeat with different username
						$length = strlen((string)$i);
						$adminUser = substr('oc_' . $username, 0, 16 - $length) . $i;
						$i++;
					}
				}
			} else {
				// Reuse existing password if a database config is already present
				$this->dbPassword = $rootPassword;
			}
		} catch (\Exception $ex) {
			$this->logger->info('Can not create a new MySQL user, will continue with the provided user.', [
				'exception' => $ex,
				'app' => 'mysql.setup',
			]);
			// Restore the original credentials
			$this->dbUser = $rootUser;
			$this->dbPassword = $rootPassword;
		}
	}
}
