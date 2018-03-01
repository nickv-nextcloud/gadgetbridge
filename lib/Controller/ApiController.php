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
use OC\DB\ConnectionFactory;
use OCA\GadgetBridge\ActivityKind;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;

class ApiController extends OCSController {
	const TYPE_UNSET = -1;
	const TYPE_NO_CHANGE = 0;
	const TYPE_ACTIVITY = 1;
	const TYPE_RUNNING = 2;
	const TYPE_NONWEAR = 3;
	const TYPE_CHARGING = 6;
	const TYPE_LIGHT_SLEEP = 9;
	const TYPE_IGNORE = 10;
	const TYPE_DEEP_SLEEP = 11;
	const TYPE_WAKE_UP = 12;

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
	 * @param int $year
	 * @param int $month
	 * @param int $day
	 * @param int $hours
	 * @param int $minutes
	 * @return DataResponse
	 */
	public function getDeviceData($databaseId, $deviceId, $year, $month, $day, $hours, $minutes) {
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

		$end = \DateTime::createFromFormat(
			'Y.n.j G:i:s',
			$year . '.' . $month . '.' . $day . ' ' .
				$hours . ':' . (($minutes < 10) ? '0': '') . $minutes . ':00');
		$start = clone $end;
		$start->sub(new \DateInterval('P1D'));

		$b = $start->getTimestamp();
		$a = $end->getTimestamp();

		if ($device['TYPE'] === '11') {
			return $this->getMiBandData($connection, $device, $start, $end);
		}

		return new DataResponse([], Http::STATUS_UNPROCESSABLE_ENTITY);
	}

	/**
	 * @param IDBConnection $connection
	 * @param array $device
	 * @param \DateTime $start
	 * @param \DateTime $end
	 * @return DataResponse
	 */
	protected function getMiBandData(IDBConnection $connection, array $device, \DateTime $start, \DateTime $end) {
		$query = $connection->getQueryBuilder();
		$query->automaticTablePrefix(false);
		$query
			->select('*')
			->from('MI_BAND_ACTIVITY_SAMPLE')
			->where($query->expr()->eq('DEVICE_ID', $query->createNamedParameter($device['_id'])))
			->andWhere($query->expr()->gte('TIMESTAMP', $query->createNamedParameter($start->getTimestamp() - 60000)))
			->andWhere($query->expr()->lte('TIMESTAMP', $query->createNamedParameter($end->getTimestamp())))
			->orderBy('TIMESTAMP', 'ASC')
		;

		$result = $query->execute();
		$data = $result->fetchAll();
		$result->closeCursor();

		$this->lastValidKind = $this->getLastMiBandActivity($connection, $device, $start->getTimestamp());
		$data = array_map([$this, 'postProcessing'], $data);

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

	protected $lastValidKind = self::TYPE_UNSET;

	protected function postProcessing($data) {
		if (empty($data)) {
			return $data;
		}

		$rawKind = $data['RAW_KIND'];
		if ($rawKind !== self::TYPE_UNSET) {
			$rawKind &= 0xf;
			$data['RAW_KIND'] = $rawKind;
		}

		switch ($rawKind) {
			case self::TYPE_IGNORE:
			case self::TYPE_NO_CHANGE:
				if ($this->lastValidKind !== self::TYPE_UNSET) {
					$data['RAW_KIND'] = $this->lastValidKind;
				}
				break;
			default:
				$this->lastValidKind = $data['RAW_KIND'];
				break;
		}

		$data['RAW_KIND'] = $this->normalizeType($data['RAW_KIND']);
		return $data;
	}

	protected function getLastMiBandActivity(IDBConnection $connection, array $device, $beforeTimestamp) {
		$query = $connection->getQueryBuilder();
		$query->automaticTablePrefix(false);
		$query
			->select('RAW_KIND')
			->from('MI_BAND_ACTIVITY_SAMPLE')
			->where($query->expr()->eq('DEVICE_ID', $query->createNamedParameter($device['_id'])))
			->andWhere($query->expr()->lte('TIMESTAMP', $query->createNamedParameter($beforeTimestamp)))
			->andWhere($query->expr()->notIn('RAW_KIND', $query->createNamedParameter([
				self::TYPE_NO_CHANGE,
				self::TYPE_IGNORE,
				self::TYPE_UNSET,
				16,
				80,
				96,
				112,
			], IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('TIMESTAMP', 'DESC')
			->setMaxResults(1)
		;

		$result = $query->execute();
		$step = $result->fetch();
		$result->closeCursor();

		if (!$step) {
			// No data before
			return self::TYPE_UNSET;
		}

		return $step['RAW_KIND'] & 0xf;
	}

	protected function normalizeType($rawType) {
		switch ($rawType) {
			case self::TYPE_DEEP_SLEEP:
				return ActivityKind::TYPE_DEEP_SLEEP;
			case self::TYPE_LIGHT_SLEEP:
				return ActivityKind::TYPE_LIGHT_SLEEP;
			case self::TYPE_ACTIVITY:
			case self::TYPE_RUNNING:
			case self::TYPE_WAKE_UP:
				return ActivityKind::TYPE_ACTIVITY;
			case self::TYPE_NONWEAR:
				return ActivityKind::TYPE_NOT_WORN;
			case self::TYPE_CHARGING:
				return ActivityKind::TYPE_NOT_WORN; //I believe it's a safe assumption
			default:
			case self::TYPE_UNSET: // fall through
				return ActivityKind::TYPE_UNKNOWN;
		}
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
