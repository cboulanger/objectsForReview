<?php

/**
 * @file plugins/generic/objectsForReview/classes/ActionLink.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ActionLink
 * @ingroup plugins_generic_objectsForReview
 *
 * @brief One-click links in emails, for people who should not have to log in
 *  (reviewers responding to a contract offer, bidders leaving the list, and in
 *  later phases publisher contacts).
 *
 *  Only a hash of the key is stored. A link expires, can be used once, and
 *  opening it never changes anything: the page it leads to shows what will
 *  happen and a confirmation button (mail scanners open links automatically).
 *
 *  OJS's invitations were considered and not used: Invitation::invite() deletes
 *  other pending invitations of the same type for the same person, its accept
 *  URL acts on GET, and it only knows accept/decline.
 */

namespace APP\plugins\generic\objectsForReview\classes;

use APP\core\Application;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;

class ActionLink {

	const TABLE = 'objects_for_review_links';

	/** Accept or decline a contract offer */
	const ACTION_CONTRACT = 'contract';
	/** Remove oneself from the list of bidders */
	const ACTION_LEAVE = 'leave';

	/** Days a link without its own deadline stays valid */
	const DEFAULT_VALIDITY_DAYS = 365;

	/**
	 * Create a link and return its key. Earlier links for the same assignment
	 * stay valid (e.g. the offer email's link after a reminder with a new one);
	 * the workflow invalidates all of them once the step is done.
	 */
	public static function create(int $assignmentId, string $action, string $expires): string {
		$key = bin2hex(random_bytes(24));
		DB::table(self::TABLE)->insert([
			'key_hash' => self::hash($key),
			'assignment_id' => $assignmentId,
			'action' => $action,
			'date_created' => Core::getCurrentDate(),
			'date_expires' => $expires,
		]);
		return $key;
	}

	/**
	 * The valid (unused, unexpired) link for a key, or null.
	 * @return ?object Row with link_id, assignment_id, action, date_expires
	 */
	public static function find(string $key): ?object {
		if (!preg_match('/^[0-9a-f]{48}$/', $key)) {
			return null;
		}
		return DB::table(self::TABLE)
			->where('key_hash', self::hash($key))
			->whereNull('date_used')
			->where('date_expires', '>', Core::getCurrentDate())
			->first();
	}

	public static function markUsed(int $linkId): void {
		DB::table(self::TABLE)->where('link_id', $linkId)->update(['date_used' => Core::getCurrentDate()]);
	}

	/**
	 * Invalidate unused links of an assignment (all actions, or one).
	 */
	public static function invalidate(int $assignmentId, ?string $action = null): void {
		$query = DB::table(self::TABLE)->where('assignment_id', $assignmentId)->whereNull('date_used');
		if ($action !== null) {
			$query->where('action', $action);
		}
		$query->update(['date_used' => Core::getCurrentDate()]);
	}

	/**
	 * The public URL of a link.
	 */
	public static function url($context, string $key): string {
		$request = Application::get()->getRequest();
		return $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $context->getPath(), 'for-review', 'link', [$key]);
	}

	protected static function hash(string $key): string {
		return hash('sha256', $key);
	}
}
