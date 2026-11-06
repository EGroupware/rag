<?php
/**
 * EGroupware RAG: Test Embedding::searchFulltext()'s real MariaDB fulltext relevance scoring
 *
 * @link http://www.egroupware.org
 * @package rag
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Rag;

use EGroupware\Api;

require_once realpath(__DIR__.'/../../api/tests/LoggedInTest.php');

/**
 * doc/ai/projects/rag-test-coverage.md's priority-5 entry (the still-open DB-integration half):
 * searchFulltext()'s own SQL (real MATCH...AGAINST relevance scoring, min_relevance filtering,
 * ordering, pagination) had no test at all.
 *
 * Uses real addressbook contacts (their save() hook synchronously fulltext-indexes them, see
 * EmbeddingReadTest.php/priority 4's finding) with a unique nonsense keyword, so relevance
 * ranking is deterministic without needing to isolate from this shared dev DB's real content -
 * a made-up keyword simply won't appear in any real row.
 */
class EmbeddingSearchFulltextTest extends Api\LoggedInTest
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

	private function createContact(string $family, string $note) : int
	{
		$bo = new \addressbook_bo();
		$contact = [
			'n_family' => $family,
			'note' => $note,
			'owner' => $GLOBALS['egw_info']['user']['account_id'],
		];
		$id = $bo->save($contact);
		$this->assertIsInt($id, 'failed to create the addressbook test fixture');
		$this->contactIds[] = $id;
		return $id;
	}

	public function testRanksHigherKeywordDensityFirstAndExcludesNonMatches()
	{
		$keyword = 'zzztestfulltextkeyword'.uniqid();
		$strong = $this->createContact('FulltextStrong', "$keyword $keyword $keyword mentioned repeatedly");
		$weak = $this->createContact('FulltextWeak', "$keyword mentioned only once here, with lots of other unrelated filler text padding this note out");
		$noMatch = $this->createContact('FulltextNoMatch', 'completely unrelated content with no keyword at all');

		$embedding = new Embedding();
		$result = $embedding->searchFulltext($keyword, 'addressbook', 0, 50, true);

		$this->assertSame([$strong, $weak], array_keys($result),
			'higher keyword density ranks first; a contact without the keyword must not appear at all');
		$this->assertArrayNotHasKey($noMatch, $result);
		$this->assertGreaterThan($result[$weak]['relevance'], $result[$strong]['relevance']);
	}

	public function testMinRelevanceFiltersOutWeakerMatch()
	{
		$keyword = 'zzztestfulltextkeyword'.uniqid();
		$strong = $this->createContact('FulltextStrong2', "$keyword $keyword $keyword $keyword $keyword repeated many times over");
		$weak = $this->createContact('FulltextWeak2', "$keyword appears just the single time in this otherwise long and unrelated note text");

		$embedding = new Embedding();
		// 60% of the best match's relevance - tuned to exclude the much weaker single-mention match
		$result = $embedding->searchFulltext($keyword, 'addressbook', 0, 50, true, 'default', 0.6);

		$this->assertArrayHasKey($strong, $result);
		$this->assertArrayNotHasKey($weak, $result,
			'a match well below min_relevance of the top hit must be filtered out');
	}

	public function testPaginationReturnsOnlyRequestedPageSize()
	{
		$keyword = 'zzztestfulltextkeyword'.uniqid();
		$this->createContact('FulltextPageA', "$keyword $keyword $keyword strongest match");
		$this->createContact('FulltextPageB', "$keyword $keyword weaker match");
		$this->createContact('FulltextPageC', "$keyword single mention");

		$embedding = new Embedding();
		$page = $embedding->searchFulltext($keyword, 'addressbook', 0, 1, false);

		$this->assertCount(1, $page, 'num_rows=1 must return exactly one result');
	}
}
