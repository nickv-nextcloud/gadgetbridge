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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;

class ImportController extends Controller {

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
	public function localFile($path) {
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

		$query = $connection->getQueryBuilder();
		$query->automaticTablePrefix(false);
		$query->select('*')
			->from('DEVICE');

		$insert = $this->connection->getQueryBuilder();
		$insert->insert('gadgetbridge_devices')
			->values([
				'user' => $insert->createParameter('user'),
				'device_id' => $insert->createParameter('device_id'),
				'name' => $insert->createParameter('name'),
				'manufacturer' => $insert->createParameter('manufacturer'),
				'identifier' => $insert->createParameter('identifier'),
				'type' => $insert->createParameter('type'),
				'model' => $insert->createParameter('model'),
			]);

		$result = $query->execute();
		while ($row = $result->fetch()) {
			$insert->setParameter('user', $user->getUID())
				->setParameter('device_id', (int) $row['_id'])
				->setParameter('name', $row['NAME'])
				->setParameter('manufacturer', $row['MANUFACTURER'])
				->setParameter('identifier', $row['IDENTIFIER'])
				->setParameter('type', (int) $row['TYPE'])
				->setParameter('model', $row['MODEL']);
			try {
				$insert->execute();
			} catch (UniqueConstraintViolationException $e) {
				// Ignore
			}
		}
		$result->closeCursor();

		$query = $connection->getQueryBuilder();
		$query->automaticTablePrefix(false);
		$query->select('*')
			->from('MI_BAND_ACTIVITY_SAMPLE');

		$insert = $this->connection->getQueryBuilder();
		$insert->insert('gadgetbridge_miband')
			->values([
				'user' => $insert->createParameter('user'),
				'device_id' => $insert->createParameter('device_id'),
				'user_id' => $insert->createParameter('user_id'),
				'datetime' => $insert->createParameter('datetime'),
				'raw_intensity' => $insert->createParameter('raw_intensity'),
				'steps' => $insert->createParameter('steps'),
				'raw_kind' => $insert->createParameter('raw_kind'),
				'heart_rate' => $insert->createParameter('heart_rate'),
			]);

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

		return new DataResponse();
	}
}
