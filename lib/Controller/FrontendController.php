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

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

class FrontendController extends Controller {

	/** @var IUserSession */
	protected $userSession;
	/** @var IRootFolder */
	protected $rootFolder;
	/** @var IConfig */
	protected $config;

	public function __construct($appName, IRequest $request, IUserSession $userSession, IRootFolder $rootFolder, IConfig $config) {
		parent::__construct($appName, $request);

		$this->userSession = $userSession;
		$this->rootFolder = $rootFolder;
		$this->config = $config;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return TemplateResponse
	 */
	public function show() {
		$user = $this->userSession->getUser();
		$databaseId = (int) $this->config->getUserValue($user->getUID(), 'gadgetbridge', 'database_file', 0);
		$databasePath = '';

		if ($databaseId > 0) {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());

			$files = $userFolder->getById($databaseId);
			if (!empty($files)) {
				$tmpPath = $files[0]->getPath();
				$tmpPath = explode('/', $tmpPath, 4);
				if (isset($tmpPath[3])) {
					$databasePath = $tmpPath[3];
				}
			}
		}

		return new TemplateResponse('gadgetbridge', 'index', [
			'databaseId' => $databaseId,
			'databasePath' => $databasePath,
		]);
	}
}
