<?php
/**
 * @copyright Copyright (c) 2021 Julien Veyssier <eneiluj@posteo.net>
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Approval\Activity;

use Exception;
use OC\Files\Node\Node;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\IUserManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;

use OCA\Approval\AppInfo\Application;

class ActivityManager {

	public const APPROVAL_OBJECT_NODE = 'files';

	public const SUBJECT_APPROVED = 'object_approved';
	public const SUBJECT_REJECTED = 'object_rejected';
	public const SUBJECT_REQUESTED = 'approval_requested';
	public const SUBJECT_MANUALLY_REQUESTED = 'approval_manually_requested';
	public const SUBJECT_REQUESTED_ORIGIN = 'approval_requested_origin';
	/**
	 * @var IManager
	 */
	private $manager;
	/**
	 * @var IL10N
	 */
	private $l10n;
	/**
	 * @var IRootFolder
	 */
	private $root;
	/**
	 * @var IUserManager
	 */
	private $userManager;
	/**
	 * @var LoggerInterface
	 */
	private $logger;
	/**
	 * @var string|null
	 */
	private $userId;

	public function __construct(IManager $manager,
								IL10N $l10n,
								IRootFolder $root,
								IUserManager $userManager,
								LoggerInterface $logger,
								?string $userId) {
		$this->manager = $manager;
		$this->l10n = $l10n;
		$this->root = $root;
		$this->userManager = $userManager;
		$this->logger = $logger;
		$this->userId = $userId;
	}

	/**
	 * @param string $subjectIdentifier
	 * @param array $subjectParams
	 * @param bool $ownActivity
	 * @return string
	 */
	public function getActivityFormat(string $subjectIdentifier, array $subjectParams = [], bool $ownActivity = false): string {
		$subject = '';
		switch ($subjectIdentifier) {
			case self::SUBJECT_APPROVED:
				$subject = $ownActivity ? $this->l10n->t('You approved {file}'): $this->l10n->t('{user} approved {file}');
				break;
			case self::SUBJECT_REJECTED:
				$subject = $ownActivity ? $this->l10n->t('You rejected {file}'): $this->l10n->t('{user} rejected {file}');
				break;
			case self::SUBJECT_REQUESTED:
				$subject = $this->l10n->t('Your approval was requested on {file}');
				break;
			case self::SUBJECT_MANUALLY_REQUESTED:
				$subject = $this->l10n->t('Your approval was requested on {file} by {who}');
				break;
			case self::SUBJECT_REQUESTED_ORIGIN:
				$subject = $this->l10n->t('You requested approval on {file}');
				break;
			default:
				break;
		}
		return $subject;
	}

	public function triggerEvent($objectType, $entity, $subject, $additionalParams = [], $author = null) {
		try {
			$event = $this->createEvent($objectType, $entity, $subject, $additionalParams, $author);
			if ($event !== null) {
				$this->sendToUsers($event, $entity, $subject, $additionalParams);
			}
		} catch (Exception $e) {
			// Ignore exception for undefined activities on update events
		}
	}

	/**
	 * @param $objectType
	 * @param $entity
	 * @param $subject
	 * @param array $additionalParams
	 * @param string|null $author
	 * @return IEvent|null
	 * @throws Exception
	 */
	private function createEvent($objectType, $entity, $subject, array $additionalParams = [], ?string $author = null): ?IEvent {
		$found = $this->root->getById($entity);
		if (count($found) === 0) {
			$this->logger->error('Could not create activity entry for ' . $entity . '. Node not found.', ['app' => Application::APP_ID]);
			return null;
		} else {
			$node = $found[0];
		}

		/**
		 * Automatically fetch related details for subject parameters
		 * depending on the subject
		 */
		$eventType = Application::APP_ID;
		$subjectParams = [];
		$message = null;
		$objectName = null;
		switch ($subject) {
			// No need to enhance parameters since entity already contains the required data
			case self::SUBJECT_APPROVED:
			case self::SUBJECT_REJECTED:
			case self::SUBJECT_REQUESTED:
			case self::SUBJECT_MANUALLY_REQUESTED:
			case self::SUBJECT_REQUESTED_ORIGIN:
				$subjectParams = $this->findDetailsForNode($node);
				$objectName = $node->getName();
				break;
			default:
				throw new Exception('Unknown subject for activity.');
		}
		$subjectParams['author'] = $this->l10n->t('A guest user');

		$event = $this->manager->generateEvent();
		$event->setApp(Application::APP_ID)
			->setType($eventType)
			->setAuthor($author === null ? $this->userId ?? '' : $author)
			->setObject($objectType, (int)$entity, $objectName)
			->setSubject($subject, array_merge($subjectParams, $additionalParams))
			->setTimestamp(time());

		if ($message !== null) {
			$event->setMessage($message);
		}
		return $event;
	}

	/**
	 * Publish activity to all users that are part of the project of a given object
	 *
	 * @param IEvent $event
	 * @param $entity
	 * @param string $subject
	 * @param array $additionalParams
	 * @throws \OCP\Files\NotPermittedException
	 * @throws \OC\User\NoUserException
	 */
	private function sendToUsers(IEvent $event, $entity, string $subject, array $additionalParams): void {
		/*
		switch ($event->getObjectType()) {
			case self::APPROVAL_OBJECT_NODE:
		*/

		$userIds = [];
		$root = $this->root;
		if ($subject === self::SUBJECT_REQUESTED || $subject === self::SUBJECT_MANUALLY_REQUESTED) {
			$ruleUserIds = $additionalParams['users'];
			foreach ($ruleUserIds as $userId) {
				$userFolder = $root->getUserFolder($userId);
				$found = $userFolder->getById($entity);
				if (count($found) > 0) {
					$userIds[] = $userId;
				}
			}
		} elseif ($subject === self::SUBJECT_REQUESTED_ORIGIN) {
			$userIds[] = $additionalParams['origin_user_id'];
		} else {
			// publish for eveyone having access
			$this->userManager->callForSeenUsers(function (IUser $user) use ($event, $root, $entity, &$userIds) {
				$userId = $user->getUID();
				$userFolder = $root->getUserFolder($userId);
				$found = $userFolder->getById($entity);
				if (count($found) > 0) {
					$userIds[] = $userId;
				}
			});
		}

		foreach ($userIds as $userId) {
			$event->setAffectedUser($userId);
			/** @noinspection DisconnectedForeachInstructionInspection */
			$this->manager->publish($event);
		}
	}

	/**
	 * @param Node $node
	 * @return array[]
	 */
	private function findDetailsForNode(Node $node): array {
		$nodeInfo = [
			'id' => $node->getId(),
			'name' => $node->getName(),
		];
		return [
			'node' => $nodeInfo,
		];
	}
}
