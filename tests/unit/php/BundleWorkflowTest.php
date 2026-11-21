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
use APP\plugins\generic\objectsForReview\classes\AssignmentWorkflow;
use APP\plugins\generic\objectsForReview\classes\BundleWorkflow;
use APP\plugins\generic\objectsForReview\classes\InvitationWorkflow;
use APP\plugins\generic\objectsForReview\classes\ObjectForReview;
use APP\plugins\generic\objectsForReview\classes\ObjectForReviewDAO;
use APP\plugins\generic\objectsForReview\classes\ReservationLog;
use APP\plugins\generic\objectsForReview\classes\ReservationReminder;
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

	/**
	 * A review essay with its works.
	 * @return array [container, members]
	 */
	protected function newEssay(array $memberTitles): array {
		// Reload: after insertObject() date_created is not set on the object, and updateObject() would fail
		$container = (new ObjectForReviewDAO())->getById($this->newWork('Essay')->getId());
		$container->setResourceType(ObjectForReview::RESOURCE_TYPE_REVIEW_ESSAY);
		(new ObjectForReviewDAO())->updateObject($container);
		$members = [];
		foreach (array_values($memberTitles) as $i => $title) {
			$members[] = $this->newWork($title, ['bundleId' => $container->getId(), 'bundlePosition' => $i + 1]);
		}
		return [$container, $members];
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

	public function testAcceptedReviewerIsMirroredWithPerWorkCopyPreference(): void {
		[$container, [$a, $b]] = $this->newEssay(['A', 'B']);
		$reviewer = $this->newAssignment($container, Assignment::STATUS_ACCEPTED, 1, [
			'dueDate' => '2030-01-31', 'shippingAddress' => 'Somewhere 1', 'phone' => '+49 30 123456',
			'copyPreferences' => [$a->getId() => 'physical', $b->getId() => 'electronic'],
		]);
		BundleWorkflow::syncMembers($container);
		BundleWorkflow::syncMembers($container); // idempotent

		$mirrorsA = (new AssignmentDAO())->getByObjectId($a->getId());
		$this->assertCount(1, $mirrorsA);
		$this->assertSame($reviewer->getId(), $mirrorsA[0]->getBundleAssignmentId());
		$this->assertSame('physical', $mirrorsA[0]->getData('copyPreference'));
		$this->assertSame('pending', $mirrorsA[0]->getData('copyStatus'));
		$this->assertSame('Somewhere 1', $mirrorsA[0]->getData('shippingAddress'));
		$mirrorB = (new AssignmentDAO())->getByObjectId($b->getId())[0];
		$this->assertSame('electronic', $mirrorB->getData('copyPreference'));
		$this->assertNull($mirrorB->getData('shippingAddress'));
		$this->assertSame(1, (int) (new ObjectForReviewDAO())->getById($a->getId())->getUserId());
		$containerAssignment = (new AssignmentDAO())->getById($reviewer->getId());
		$this->assertNull($containerAssignment->getData('shippingAddress'));
		$this->assertNull($containerAssignment->getData('copyPreferences'));
		// Logged once on the work, naming the essay
		$labels = array_column(ReservationLog::getHistory($a), 'label');
		$this->assertCount(1, $labels);
		$this->assertStringContainsString(': Essay ', $labels[0]);
	}

	public function testRetractEndsMirrorsAndKeepsSentCopiesReturnable(): void {
		[$container, [$a, $b]] = $this->newEssay(['A', 'B']);
		$reviewer = $this->newAssignment($container, Assignment::STATUS_ACCEPTED, 1, [
			'dueDate' => '2030-01-31', 'copyPreferences' => [$a->getId() => 'physical', $b->getId() => 'electronic'],
			'shippingAddress' => 'X', 'phone' => '+49 30 123456',
		]);
		BundleWorkflow::syncMembers($container);
		$mirrorA = (new AssignmentDAO())->getByObjectId($a->getId())[0];
		$mirrorA->setData('copyStatus', 'sent');
		(new AssignmentDAO())->updateObject($mirrorA);

		AssignmentWorkflow::retract($this->plugin(), $container, (new AssignmentDAO())->getById($reviewer->getId()), \APP\facades\Repo::user()->get(1, true));

		$mirrorA = (new AssignmentDAO())->getById($mirrorA->getId());
		$this->assertSame(Assignment::STATUS_RETRACTED, $mirrorA->getStatus());
		$this->assertSame('sent', $mirrorA->getData('copyStatus'));
		$mirrorB = (new AssignmentDAO())->getByObjectId($b->getId())[0];
		$this->assertSame(Assignment::STATUS_RETRACTED, $mirrorB->getStatus());
		$this->assertSame('notNeeded', $mirrorB->getData('copyStatus'));
		$this->assertNull((new ObjectForReviewDAO())->getById($a->getId())->getUserId());
	}

	public function testAttachDetachCompleteMoveMembersAlong(): void {
		[$container, [$a]] = $this->newEssay(['A']);
		$reviewer = $this->newAssignment($container, Assignment::STATUS_ACCEPTED, 1, ['dueDate' => '2030-01-31', 'copyPreferences' => [$a->getId() => 'have']]);
		BundleWorkflow::syncMembers($container);
		$user = \APP\facades\Repo::user()->get(1, true);

		AssignmentWorkflow::attach($container, (new AssignmentDAO())->getById($reviewer->getId()), $user, 424242);
		$this->assertSame(424242, (int) (new ObjectForReviewDAO())->getById($a->getId())->getSubmissionId());
		$this->assertSame(Assignment::STATUS_SUBMITTED, (new AssignmentDAO())->getByObjectId($a->getId())[0]->getStatus());

		AssignmentWorkflow::detach((new ObjectForReviewDAO())->getById($container->getId()));
		$this->assertNull((new ObjectForReviewDAO())->getById($a->getId())->getSubmissionId());
		$this->assertSame(Assignment::STATUS_ACCEPTED, (new AssignmentDAO())->getByObjectId($a->getId())[0]->getStatus());

		AssignmentWorkflow::attach($container, (new AssignmentDAO())->getById($reviewer->getId()), $user, 424242);
		AssignmentWorkflow::complete((new ObjectForReviewDAO())->getById($container->getId()));
		$this->assertSame(Assignment::STATUS_COMPLETED, (new AssignmentDAO())->getByObjectId($a->getId())[0]->getStatus());
	}

	public function testMemberStateFollowsContainer(): void {
		[$container, [$a]] = $this->newEssay(['A']);
		$member = (new ObjectForReviewDAO())->getById($a->getId());
		$this->assertFalse(AssignmentWorkflow::isReservable($member, []));
		$this->newAssignment($container, Assignment::STATUS_ACCEPTED, 1, ['dueDate' => '2030-01-31']);
		$this->assertSame(AssignmentWorkflow::STATE_ASSIGNED, AssignmentWorkflow::getState($member, []));
		$this->assertFalse(InvitationWorkflow::canInvite($member));
	}

	public function testMirrorsGetNoDueDateReminder(): void {
		[$container, [$a, $b]] = $this->newEssay(['A', 'B']);
		$tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
		$mirror = $this->newAssignment($a, Assignment::STATUS_ACCEPTED, 1, ['dueDate' => $tomorrow, 'bundleAssignmentId' => 999999]);
		// Not a mirror, but its user does not exist, so no email is sent
		$other = $this->newAssignment($b, Assignment::STATUS_ACCEPTED, 999999999, ['dueDate' => $tomorrow]);
		// Only these two assignments, not the other ones in the dev journal
		$task = new class ([[$a, $mirror], [$b, $other]]) extends ReservationReminder {
			public function __construct(private array $pairs) {
				parent::__construct();
			}

			protected function assignments($context, array $statuses): array {
				return $this->pairs;
			}
		};
		$context = \APP\core\Application::getContextDAO()->getById(self::CONTEXT_ID);

		$this->assertSame(0, $this->invoke($task, 'sendDueDateReminders', $this->plugin(['ofrReminderDays' => 14]), $context));
		$this->assertNull((new AssignmentDAO())->getById($mirror->getId())->getData('reminderSent'));
	}
}
