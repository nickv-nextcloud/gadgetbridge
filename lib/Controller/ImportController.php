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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IUserSession;

class ImportController extends Controller {

	/** @var IUserSession */
	protected $userSession;
	/** @var IRootFolder */
	protected $rootFolder;

	public function __construct($appName, IRequest $request, IUserSession $userSession, IRootFolder $rootFolder) {
		parent::__construct($appName, $request);
		$this->userSession = $userSession;
		$this->rootFolder = $rootFolder;
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
		} catch (NotFoundException $e) {
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

		$query = $connection->getQueryBuilder();
		$connection->close();

		return new DataResponse();
	}
}
