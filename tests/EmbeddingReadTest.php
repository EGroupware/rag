<?php
/**
 * EGroupware RAG: Test Embedding::read()'s id-parsing guards and ACL enforcement
 *
 * @link http://www.egroupware.org
 * @package rag
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Rag;

use EGroupware\Api;

require_once realpath(__DIR__.'/../../api/tests/LoggedInTest.php');

/**
 * doc/ai/projects/rag-test-coverage.md's priority-4 entry: read() is REST-API surface added in
 * PR #4, and its docblock claims a three-way null/false/array contract ("null if not found,
 * false if user has no access"). A regression here is an information-disclosure risk, not just
 * a wrong-answer bug.
 *
 * Only covers the "no real access-control setup needed" cases: malformed ids, unknown apps, a
 * real accessible entry, and a genuinely nonexistent id of a known app. Does NOT cover the
 * "exists but a *different* user has no ACL to it" case, which needs a second real account and
 * cross-user session switching - out of scope here given the known risk of a user-switching
 * test polluting process-wide static caches (see feedback_static_acl_cache_cross_test_leak
 * memory) and the added fixture complexity; flagged as a follow-up, not silently skipped.
 */
class EmbeddingReadTest extends Api\LoggedInTest
{
	private array $contactIds = [];

	protected function tearDown() : void
	{
		if ($this->contactIds)
		{
			$bo = new \addressbook_bo();
			foreach ($this->contactIds as $id)
			{
				$bo->delete($id);
			}
			$this->contactIds = [];
		}
		parent::tearDown();
	}

	public function testMalformedIdReturnsNull()
	{
		$embedding = new Embedding();

		$this->assertNull($embedding->read('no-colon-at-all'));
		$this->assertNull($embedding->read('addressbook:'));   // empty app_id part
		$this->assertNull($embedding->read(':123'));            // empty app part
	}

	public function testUnknownAppReturnsNull()
	{
		$embedding = new Embedding();

		$this->assertNull($embedding->read('this-app-does-not-exist:123'));
	}

	public function testExistingAccessibleContactReturnsTitleAndDescription()
	{
		$bo = new \addressbook_bo();
		$contact = [
			'n_family' => 'RagReadTest',
			'n_given'  => 'Priority4',
			'note'     => 'a note long enough to be indexed by rag',
			'owner'    => $GLOBALS['egw_info']['user']['account_id'],
		];
		$id = $bo->save($contact);
		$this->assertIsInt($id, 'failed to create the addressbook test fixture');
		$this->contactIds[] = $id;

		$embedding = new Embedding();
		$entry = $embedding->read("addressbook:$id");

		$this->assertIsArray($entry);
		$this->assertSame('addressbook', $entry['app']);
		$this->assertEquals($id, $entry['app_id']);
		$this->assertNotEmpty($entry['title']);
		$this->assertSame('a note long enough to be indexed by rag', $entry['description']);
	}

	public function testNonExistentIdOfKnownAppReturnsFalseNotNull()
	{
		// read()'s own docblock claims null=not-found, false=no-access. In practice
		// Api\Link::title() returns false (never null) whenever the underlying app's
		// title-hook returns null for a non-empty id - most apps deliberately don't
		// distinguish "doesn't exist" from "exists but no access" there, to avoid leaking
		// existence to an unauthorized caller. So a plain nonexistent id ALSO comes back as
		// false, not null - the null branch is only reachable via read()'s earlier
		// app/id/plugin-class guard, not via this second check. Documented here, not changed:
		// changing it would mean either leaking existence (returning null) or living with the
		// current, safer-by-default conflation - a real product decision, not a test's call.
		$embedding = new Embedding();
		$result = $embedding->read('addressbook:999999999');

		$this->assertFalse($result,
			'a nonexistent id currently comes back as false (not null) - see comment above');
	}
}
