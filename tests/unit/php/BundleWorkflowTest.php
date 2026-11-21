<?php

/**
 * @file tests/unit/php/BundleWorkflowTest.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Review essays: containers, members and mirror assignments (writes to the database).
 */

namespace APP\plugins\generic\objectsForReview\tests\unit;

use APP\plugins\generic\objectsForReview\classes\Assignment;
use APP\plugins\generic\objectsForReview\classes\AssignmentDAO;
use APP\plugins\generic\objectsForReview\classes\ObjectForReview;
use APP\plugins\generic\objectsForReview\classes\ObjectForReviewDAO;
use PHPUnit\Framework\Attributes\Group;

#[Group('db')]
class BundleWorkflowTest extends TestCase {

	/** @var int[] Object ids to delete in tearDown */
	protected array $created = [];

	protected function tearDown(): void {
		$dao = new ObjectForReviewDAO();
		foreach ($this->created as $id) {
			$dao->deleteById($id);
		}
		$this->created = [];
	}

	protected function newWork(string $title, array $data = []): ObjectForReview {
		$dao = new ObjectForReviewDAO();
		$work = $dao->newDataObject();
		$work->setContextId(self::CONTEXT_ID);
		$work->setIdentifier('978' . random_int(1000000000, 9999999999));
		$work->setIdentifierType('isbn');
		$work->setResourceType('http://purl.org/coar/resource_type/c_2f33');
		$work->setCreator('manager');
		$work->setTitle($title);
		$work->setAuthors('Author ' . $title);
		$work->setOfferSetting('hidden', true);
		foreach ($data as $name => $value) {
			$work->setOfferSetting($name, $value);
		}
		$dao->insertObject($work);
		$this->created[] = $work->getId();
		return $work;
	}

	protected function newAssignment(ObjectForReview $object, string $status, int $userId = 1, array $data = []): Assignment {
		$dao = new AssignmentDAO();
		$assignment = $dao->newDataObject();
		$assignment->setObjectId($object->getId());
		$assignment->setContextId(self::CONTEXT_ID);
		$assignment->setUserId($userId);
		$assignment->setStatus($status);
		foreach ($data as $name => $value) {
			$assignment->setData($name, $value);
		}
		$dao->insertObject($assignment);
		return $assignment;
	}

	public function testMembersInOrderAndContainerCitation(): void {
		$container = (new ObjectForReviewDAO())->getById($this->newWork('Essay')->getId());
		$container->setResourceType(ObjectForReview::RESOURCE_TYPE_REVIEW_ESSAY);
		$container->setIdentifier('');
		$container->setIdentifierType('');
		(new ObjectForReviewDAO())->updateObject($container);
		$b = $this->newWork('B', ['bundleId' => $container->getId(), 'bundlePosition' => 2]);
		$a = $this->newWork('A', ['bundleId' => $container->getId(), 'bundlePosition' => 1]);

		$members = (new ObjectForReviewDAO())->getMembers($container->getId());
		$this->assertSame([$a->getId(), $b->getId()], array_map(fn ($m) => $m->getId(), $members));
		$this->assertSame('Essay (Author A: A; Author B: B)', $container->getCitation());
	}

	public function testMirrorsAreNotCountedAsReviews(): void {
		$work = $this->newWork('W');
		$this->newAssignment($work, Assignment::STATUS_COMPLETED, 1, ['bundleAssignmentId' => 999999]);
		$before = (new AssignmentDAO())->countReviewsByUser(1, self::CONTEXT_ID);
		$this->newAssignment($work, Assignment::STATUS_COMPLETED, 1);
		$this->assertSame($before + 1, (new AssignmentDAO())->countReviewsByUser(1, self::CONTEXT_ID));
	}
}
