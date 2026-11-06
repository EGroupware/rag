<?php
/**
 * EGroupware RAG: Test Embedding::cacheQueryEmbedding()'s retry-on-duplicate-key behaviour
 *
 * @link http://www.egroupware.org
 * @package rag
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Rag;

use EGroupware\Api;
use EGroupware\Api\Db\Exception\InvalidSql;
use ReflectionMethod;
use ReflectionProperty;

require_once realpath(__DIR__.'/../../api/tests/LoggedInTest.php');

/**
 * doc/ai/projects/rag-test-coverage.md's priority-1 entry.
 *
 * Embedding::searchEmbeddings()'s *cache* query-embedding insert used to be a plain INSERT
 * after a non-atomic MAX(rag_chunk)+1 read: two concurrent searches caching two different,
 * not yet seen patterns could compute the same next rag_chunk and collide on the
 * (rag_app,rag_app_id,rag_chunk) unique key, throwing an uncaught duplicate-key InvalidSql
 * straight to the user - a real, live-reported bug. Fixed by extracting the insert into
 * Embedding::cacheQueryEmbedding(), which retries with a freshly read MAX(rag_chunk) on a
 * 1062 collision (up to 3 times) instead of failing.
 *
 * These tests exercise cacheQueryEmbedding() directly (via reflection, it's protected) with
 * a `$db` stand-in that simulates the collision deterministically - real concurrent processes
 * racing the actual MAX()+1 read would be flaky and slow to reproduce on demand, and the
 * simulated 1062 is indistinguishable to the code under test from a real one (both take the
 * exact same `catch (InvalidSql $e) { if ($e->getCode() != 1062 ...` branch).
 */
class EmbeddingCacheRaceTest extends Api\LoggedInTest
{
	/**
	 * @var string[] binary sha256 hashes inserted by this test, cleaned up in tearDown()
	 */
	private array $hashes = [];

	protected function tearDown() : void
	{
		if ($this->hashes)
		{
			$GLOBALS['egw']->db->delete(Embedding::TABLE, [
				'rag_app'   => Embedding::EMBEDDING_CACHE,
				'rag_app_id' => 0,
				'rag_hash'  => $this->hashes,
			], __LINE__, __FILE__, Embedding::APP);
			$this->hashes = [];
		}
		parent::tearDown();
	}

	/**
	 * Build a fake create()-response object for a chunk we never actually send to an embeddings API
	 */
	private function fakeResponse(string $seed) : object
	{
		$hash = hash('sha256', self::class.'::'.$seed.'::'.microtime(true), true);
		$this->hashes[] = $hash;
		return (object)[
			'sha256'    => $hash,
			'embedding' => array_fill(0, 1024, 0.0),  // egw_rag.rag_embedding is VECTOR(1024)
		];
	}

	private function callCacheQueryEmbedding(Embedding $embedding, object $response) : void
	{
		$method = new ReflectionMethod(Embedding::class, 'cacheQueryEmbedding');
		$method->setAccessible(true);
		$method->invoke($embedding, $response);
	}

	private function setDb(Embedding $embedding, $db) : void
	{
		$property = new ReflectionProperty(Embedding::class, 'db');
		$property->setAccessible(true);
		$property->setValue($embedding, $db);
	}

	/**
	 * A $db stand-in whose insert() throws a simulated duplicate-key (or other) InvalidSql the
	 * first $failures times it's called, then forwards to the real $db - select()/fetchColumn()
	 * (used to read MAX(rag_chunk)) and everything else always forwards to the real $db.
	 */
	private function racyDb($failures, int $code = 1062)
	{
		return new class($GLOBALS['egw']->db, $failures, $code)
		{
			public int $insertCalls = 0;

			public function __construct(private $real, private $failures, private int $code)
			{
			}

			public function __call($name, $args)
			{
				if ($name === 'insert')
				{
					$this->insertCalls++;
					if ($this->failures === true || $this->insertCalls <= $this->failures)
					{
						throw new InvalidSql('simulated duplicate entry', $this->code);
					}
				}
				return $this->real->$name(...$args);
			}
		};
	}

	public function testRetriesOnceOnDuplicateKeyCollisionThenSucceeds()
	{
		$embedding = new Embedding();
		$racy = $this->racyDb(1);
		$this->setDb($embedding, $racy);

		$response = $this->fakeResponse('retry-once');
		$this->callCacheQueryEmbedding($embedding, $response);

		$this->assertSame(2, $racy->insertCalls,
			'expected exactly one retry after the simulated collision');

		$stored = $GLOBALS['egw']->db->select(Embedding::TABLE, 'rag_hash', [
			'rag_hash' => $response->sha256,
		], __LINE__, __FILE__, false, '', Embedding::APP)->fetchColumn();
		$this->assertSame($response->sha256, $stored,
			'the embedding should have been persisted by the retried insert');
	}

	public function testGivesUpAfterExhaustingRetries()
	{
		$embedding = new Embedding();
		$racy = $this->racyDb(true);   // always fails
		$this->setDb($embedding, $racy);

		$response = $this->fakeResponse('exhausted');
		try
		{
			$this->callCacheQueryEmbedding($embedding, $response);
			$this->fail('expected an InvalidSql exception after exhausting retries');
		}
		catch (InvalidSql $e)
		{
			$this->assertSame(1062, $e->getCode());
		}
		$this->assertSame(4, $racy->insertCalls,
			'expected the initial attempt plus 3 retries, then giving up');
	}

	public function testNonDuplicateKeyErrorPropagatesWithoutRetrying()
	{
		$embedding = new Embedding();
		$racy = $this->racyDb(true, 1064);   // "syntax error", not a duplicate-key
		$this->setDb($embedding, $racy);

		$response = $this->fakeResponse('non-duplicate');
		try
		{
			$this->callCacheQueryEmbedding($embedding, $response);
			$this->fail('expected an InvalidSql exception');
		}
		catch (InvalidSql $e)
		{
			$this->assertSame(1064, $e->getCode());
		}
		$this->assertSame(1, $racy->insertCalls,
			'a non-duplicate-key error must not be retried');
	}
}
