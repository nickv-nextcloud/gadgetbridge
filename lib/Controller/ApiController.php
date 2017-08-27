<?php
/**
 * @copyright Copyright (c) 2017 Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\GadgetBridge\Controller;

use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OC\DB\ConnectionFactory;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\File;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;

class ApiController extends OCSController {

	/** @var IDBConnection */
	protected $connection;
	/** @var IUserSession */
	protected $userSession;
	/** @var IRootFolder */
	protected $rootFolder;
	/** @var IConfig */
	protected $config;

	public function __construct($appName, IRequest $request, IDBConnection $connection, IUserSession $userSession, IRootFolder $rootFolder, IConfig $config) {
		parent::__construct($appName, $request);
		$this->connection = $connection;
		$this->userSession = $userSession;
		$this->rootFolder = $rootFolder;
		$this->config = $config;
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $path
	 * @return DataResponse
	 */
	public function selectDatabase($path) {
		$user = $this->userSession->getUser();
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());

		try {
			$dataToImport = $userFolder->get($path);
			$fileId = $dataToImport->getId();
		} catch (NotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		} catch (InvalidPathException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}

		if (!$dataToImport instanceof File) {
			return new DataResponse([], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		/** @var File $dataToImport */
		$storage = $dataToImport->getStorage();
		$tmpPath = $storage->getLocalFile($dataToImport->getInternalPath());

		$factory = new \OC\DB\ConnectionFactory(\OC::$server->getSystemConfig());
		try {
			$connection = $factory->getConnection('sqlite3', [
				'user' => '',
				'password' => '',
				'path' => $tmpPath,
				'sqlite.journal_mode' => 'WAL',
				'tablePrefix' => '',
			]);
		} catch (DBALException $e) {
			return new DataResponse([], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$connection->close();

		$this->config->setUserValue($user->getUID(), 'gadgetbridge', 'database_file', $fileId);

		return new DataResponse(['fileId' => $fileId]);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param int $databaseId
	 * @return DataResponse
	 */
	public function getDevices($databaseId) {
		try {
			$connection = $this->getConnection($databaseId);
		} catch (NotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}

		$query = $connection->getQueryBuilder();
		$query->automaticTablePrefix(false);
		$query->select('*')
			->from('DEVICE');

		$result = $query->execute();
		$devices = $result->fetchAll();
		$result->closeCursor();

		return new DataResponse($devices);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param int $databaseId
	 * @param int $deviceId
	 * @return DataResponse
	 */
	public function getDeviceData($databaseId, $deviceId) {
		try {
			$connection = $this->getConnection($databaseId);
		} catch (NotFoundException $e) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse([], Http::STATUS_BAD_REQUEST);
		}


		$query = $connection->getQueryBuilder();
		$query->automaticTablePrefix(false);
		$query->select('*')
			->from('DEVICE')
			->where($query->expr()->eq('_id', $query->createNamedParameter($deviceId)));

		$result = $query->execute();
		$device = $result->fetch();
		$result->closeCursor();

		if ($device['TYPE'] === '11') {
			return $this->getMiBandData($connection, $device);
		}

		return new DataResponse([], Http::STATUS_UNPROCESSABLE_ENTITY);
	}

	/**
	 * @param IDBConnection $connection
	 * @param array $device
	 * @return DataResponse
	 */
	protected function getMiBandData(IDBConnection $connection, array $device) {
		$query = $connection->getQueryBuilder();
		$query->automaticTablePrefix(false);
		$query->select('*')
			->from('MI_BAND_ACTIVITY_SAMPLE')
			->where($query->expr()->eq('DEVICE_ID', $query->createNamedParameter($device['_id'])));

		//FIXME proper pagination
		$query->setMaxResults(1000)
			->orderBy('TIMESTAMP', 'DESC');

		$result = $query->execute();
		$data = $result->fetchAll();
		$result->closeCursor();

		/**
		 * (int) $row['DEVICE_ID']
		 * (int) $row['USER_ID']
		 * \DateTime::createFromFormat('U', (int) $row['TIMESTAMP'])
		 * (int) $row['RAW_INTENSITY']
		 * (int) $row['STEPS']
		 * (int) $row['RAW_KIND']
		 * (int) $row['HEART_RATE']
		 */
		return new DataResponse($data);
	}

	protected function temp() {
		$result = $query->execute();
		while ($row = $result->fetch()) {
			$insert->setParameter('user', $user->getUID())
				->setParameter('device_id', (int) $row['DEVICE_ID'])
				->setParameter('user_id', (int) $row['USER_ID'])
				->setParameter('datetime', \DateTime::createFromFormat('U', (int) $row['TIMESTAMP']), 'datetime')
				->setParameter('raw_intensity', (int) $row['RAW_INTENSITY'])
				->setParameter('steps', (int) $row['STEPS'])
				->setParameter('raw_kind', (int) $row['RAW_KIND'])
				->setParameter('heart_rate', (int) $row['HEART_RATE']);
			try {
				$insert->execute();
			} catch (UniqueConstraintViolationException $e) {
				// FIXME use offset on select
			}
		}
		$result->closeCursor();

		$connection->close();
	}

	/**
	 * @param int $database
	 * @return IDBConnection
	 * @throws NotFoundException
	 * @throws \InvalidArgumentException
	 */
	protected function getConnection($database) {
		$user = $this->userSession->getUser();
		$userFolder = $this->rootFolder->getUserFolder($user->getUID());
		$databaseFile = $userFolder->getById($database);

		if (count($databaseFile) !== 1 && !$databaseFile[0] instanceof File) {
			throw new \InvalidArgumentException('Unprocessable entity', Http::STATUS_UNPROCESSABLE_ENTITY);
		}
		$databaseFile = $databaseFile[0];

		/** @var File $databaseFile */
		$storage = $databaseFile->getStorage();
		$tmpPath = $storage->getLocalFile($databaseFile->getInternalPath());

		$factory = new ConnectionFactory(\OC::$server->getSystemConfig());
		try {
			$connection = $factory->getConnection('sqlite3', [
				'user' => '',
				'password' => '',
				'path' => $tmpPath,
				'sqlite.journal_mode' => 'WAL',
				'tablePrefix' => '',
			]);
		} catch (DBALException $e) {
			throw new \InvalidArgumentException('Unprocessable entity', Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return $connection;
	}
}
