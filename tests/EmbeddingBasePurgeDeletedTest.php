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
 * Calendar plugin purging in chunks of just 2 entries
 */
class EmbeddingBasePurgeDeletedChunkCalendar extends Embedding\Calendar
{
	const PURGE_CHUNK_SIZE = 2;
}

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
	/** number of orphans seeded for the chunk tests: ids ORPHAN_CALENDAR_ID+1 ... */
	const CHUNK_ORPHANS = 5;

	private function clearPurgeCaches() : void
	{
		// clear any cached "already purged today" flag - both the pre-fix shared key
		// (Base::class,'purge-') and the correct per-app keys - so this test gets a
		// deterministic clean slate regardless of what a real cron run may have already
		// cached today on this shared dev instance, and regardless of which code is running
		Api\Cache::unsetInstance(Embedding\Base::class, 'purge-');
		Api\Cache::unsetInstance(Embedding\Addressbook::class, 'purge-addressbook');
		Api\Cache::unsetInstance(Embedding\Calendar::class, 'purge-calendar');
		Api\Cache::unsetInstance(EmbeddingBasePurgeDeletedChunkCalendar::class, 'purge-calendar');
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
		$db->delete(Embedding::FULLTEXT_TABLE, [
			'ft_app' => 'calendar', 'ft_app_id' => range(self::ORPHAN_CALENDAR_ID+1, self::ORPHAN_CALENDAR_ID+self::CHUNK_ORPHANS),
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

	private function seedChunkOrphans() : void
	{
		for($n = 1; $n <= self::CHUNK_ORPHANS; $n++)
		{
			$this->seedOrphan('calendar', self::ORPHAN_CALENDAR_ID+$n);
		}
	}

	private function chunkOrphansLeft() : int
	{
		return (int)$GLOBALS['egw']->db->select(Embedding::FULLTEXT_TABLE, 'COUNT(*)', [
			'ft_app' => 'calendar', 'ft_app_id' => range(self::ORPHAN_CALENDAR_ID+1, self::ORPHAN_CALENDAR_ID+self::CHUNK_ORPHANS),
		], __LINE__, __FILE__, false, '', Embedding::APP)->fetchColumn();
	}

	/**
	 * Purging used to be ONE statement per index: it could not finish on tables with millions of orphans
	 * (killed by max_statement_time, huge transaction on Galera), and a failed purge counted as done for a day.
	 * Now it deletes in chunks, until nothing is left.
	 */
	public function testPurgeDeletedPurgesMoreThanOneChunk()
	{
		$this->seedChunkOrphans();
		$this->assertSame(self::CHUNK_ORPHANS, $this->chunkOrphansLeft());

		// chunk size is 2, so we need 3 chunks for the 5 orphans (plus all other orphans of this dev instance)
		$this->assertTrue((new EmbeddingBasePurgeDeletedChunkCalendar())->purgeDeleted(300.0));

		$this->assertSame(0, $this->chunkOrphansLeft(), 'all orphans have to be purged, not just the first chunk');
	}

	public function testPurgeDeletedContinuesWhereItStoppedWhenTheTimeLimitIsReached()
	{
		$this->seedChunkOrphans();
		$purge = new EmbeddingBasePurgeDeletedChunkCalendar();

		// a time-limit in the past stops after the first chunk: it reports there is more to purge ...
		$this->assertFalse($purge->purgeDeleted(-1.0));
		$this->assertLessThan(self::CHUNK_ORPHANS, $this->chunkOrphansLeft(), 'the first chunk has to be purged');
		// ... and it must NOT count as purged for the day, as the next call has to continue
		$this->assertNull(Api\Cache::getInstance(EmbeddingBasePurgeDeletedChunkCalendar::class, 'purge-calendar'));

		$this->assertTrue($purge->purgeDeleted(300.0));
		$this->assertSame(0, $this->chunkOrphansLeft());
		// finished: now it is marked as done for the day and does not purge again
		$this->assertNotNull(Api\Cache::getInstance(EmbeddingBasePurgeDeletedChunkCalendar::class, 'purge-calendar'));
		$this->seedOrphan('calendar', self::ORPHAN_CALENDAR_ID+1);
		$this->assertTrue($purge->purgeDeleted(300.0));
		$this->assertSame(1, $this->chunkOrphansLeft(), 'already purged today, nothing to do');
	}
}
