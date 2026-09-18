<?php
/**
 * EGroupware RAG: Test Embedding\Base::purgeDeleted()'s per-app daily-cache key
 *
 * @link http://www.egroupware.org
 * @package rag
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Rag;

use EGroupware\Api;

require_once realpath(__DIR__.'/../../api/tests/LoggedInTest.php');

/**
 * doc/ai/projects/rag-test-coverage.md's priority-6 entry.
 *
 * Base::purgeDeleted() wraps its cleanup in `Api\Cache::getInstance(__CLASS__,
 * 'purge-'.self::APP, ..., 86400)` to run at most once/day. `self::APP` inside a method defined
 * in the abstract Base class is NOT late-static-bound for constant lookups (unlike `static::`)
 * - it always resolves to Base's OWN `const APP = ''`, regardless of which subclass instance
 * calls it. So every plugin's purgeDeleted() shared the exact same cache key
 * (`Base::class`, `'purge-'`) - once ANY one app's purge ran on a given day, every OTHER app's
 * purge silently no-opped for the rest of that day, never actually cleaning their orphaned
 * egw_rag/egw_rag_fulltext rows.
 */
class EmbeddingBasePurgeDeletedTest extends Api\LoggedInTest
{
	const ORPHAN_ADDRESSBOOK_ID = 999901;
	const ORPHAN_CALENDAR_ID = 999902;

	private function clearPurgeCaches() : void
	{
		// clear any cached "already purged today" flag - both the pre-fix shared key
		// (Base::class,'purge-') and the correct per-app keys - so this test gets a
		// deterministic clean slate regardless of what a real cron run may have already
		// cached today on this shared dev instance, and regardless of which code is running
		Api\Cache::unsetInstance(Embedding\Base::class, 'purge-');
		Api\Cache::unsetInstance(Embedding\Addressbook::class, 'purge-addressbook');
		Api\Cache::unsetInstance(Embedding\Calendar::class, 'purge-calendar');
	}

	protected function setUp() : void
	{
		parent::setUp();
		$this->clearPurgeCaches();
	}

	protected function tearDown() : void
	{
		$db = $GLOBALS['egw']->db;
		$db->delete(Embedding::FULLTEXT_TABLE, [
			'ft_app' => 'addressbook', 'ft_app_id' => self::ORPHAN_ADDRESSBOOK_ID,
		], __LINE__, __FILE__, Embedding::APP);
		$db->delete(Embedding::FULLTEXT_TABLE, [
			'ft_app' => 'calendar', 'ft_app_id' => self::ORPHAN_CALENDAR_ID,
		], __LINE__, __FILE__, Embedding::APP);
		$this->clearPurgeCaches();
		parent::tearDown();
	}

	private function seedOrphan(string $app, int $appId) : void
	{
		$GLOBALS['egw']->db->insert(Embedding::FULLTEXT_TABLE, [
			'ft_app' => $app,
			'ft_app_id' => $appId,
			'ft_title' => 'orphaned test row',
			'ft_updated' => new Api\DateTime(),
		], false, __LINE__, __FILE__, Embedding::APP);
	}

	private function orphanExists(string $app, int $appId) : bool
	{
		return (bool)$GLOBALS['egw']->db->select(Embedding::FULLTEXT_TABLE, 'ft_app_id', [
			'ft_app' => $app, 'ft_app_id' => $appId,
		], __LINE__, __FILE__, false, '', Embedding::APP)->fetchColumn();
	}

	public function testPurgeDeletedForOneAppDoesNotSkipAnotherApp()
	{
		$this->seedOrphan('addressbook', self::ORPHAN_ADDRESSBOOK_ID);
		$this->seedOrphan('calendar', self::ORPHAN_CALENDAR_ID);

		(new Embedding\Addressbook())->purgeDeleted();
		(new Embedding\Calendar())->purgeDeleted();

		$this->assertFalse($this->orphanExists('addressbook', self::ORPHAN_ADDRESSBOOK_ID),
			"addressbook's own orphaned fulltext row should have been purged");
		$this->assertFalse($this->orphanExists('calendar', self::ORPHAN_CALENDAR_ID),
			"calendar's purgeDeleted() must NOT be skipped just because addressbook's already ".
			'ran today - each app needs its own once-daily cache key');
	}
}
