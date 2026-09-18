<?php
/**
 * EGroupware RAG: Test Embedding::embed()'s per-entry fulltext+RAG upsert, incl. excess-chunk
 * cleanup
 *
 * @link http://www.egroupware.org
 * @package rag
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Rag;

use EGroupware\Api;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use ReflectionProperty;

require_once realpath(__DIR__.'/../../api/tests/LoggedInTest.php');

/**
 * doc/ai/projects/rag-test-coverage.md's priority-6 entry: embed()'s full per-app upsert
 * (fulltext index write, RAG/embedding chunk write, excess-chunk cleanup) had no direct test -
 * only indirectly exercised via addressbook_bo::save()'s own notify-hook in earlier priorities.
 *
 * The test contact is inserted directly into egw_addressbook (bypassing addressbook_bo::save()
 * and its synchronous notify-hook entirely), so embed() is called explicitly, once, with full
 * control - and so this test's fake embeddings client is the only one ever invoked, never the
 * real one this dev instance actually has configured (see EmbeddingSearchEmbeddingsTest.php's
 * docblock for why that matters).
 */
class EmbeddingEmbedUpsertTest extends Api\LoggedInTest
{
	private ?int $contactId = null;

	protected function tearDown() : void
	{
		if ($this->contactId)
		{
			$db = $GLOBALS['egw']->db;
			$db->delete('egw_addressbook', ['contact_id' => $this->contactId], __LINE__, __FILE__, self::SCHEMA_APP);
			$db->delete(Embedding::TABLE, ['rag_app' => 'addressbook', 'rag_app_id' => $this->contactId],
				__LINE__, __FILE__, Embedding::APP);
			$db->delete(Embedding::FULLTEXT_TABLE, ['ft_app' => 'addressbook', 'ft_app_id' => $this->contactId],
				__LINE__, __FILE__, Embedding::APP);
			$this->contactId = null;
		}
		parent::tearDown();
	}

	// egw_addressbook's schema is owned by 'api', not 'addressbook' (see
	// EmbeddingPluginSchemaConsistencyTest.php's finding) - Db::insert()/update() need the
	// correct owning app to look up column definitions, otherwise they silently drop columns
	// they can't validate types for (a raw NOT NULL column ends up missing from the SQL
	// entirely, not just null)
	const SCHEMA_APP = 'api';

	private function insertRawContact(string $note) : int
	{
		$db = $GLOBALS['egw']->db;
		$db->insert('egw_addressbook', [
			'contact_owner' => $GLOBALS['egw_info']['user']['account_id'],
			'contact_creator' => $GLOBALS['egw_info']['user']['account_id'],
			'contact_modified' => time(),
			'n_family' => 'EmbedUpsertTest',
			'n_fileas' => 'EmbedUpsertTest '.uniqid(),
			'contact_note' => $note,
		], false, __LINE__, __FILE__, self::SCHEMA_APP);
		return (int)$db->get_last_insert_id('egw_addressbook', 'contact_id');
	}

	private function updateContactNote(int $id, string $note) : void
	{
		$db = $GLOBALS['egw']->db;
		$db->update('egw_addressbook', [
			'contact_note' => $note,
			'contact_modified' => time() + 1,   // must advance for getUpdated()'s staleness check
		], ['contact_id' => $id], __LINE__, __FILE__, self::SCHEMA_APP);
	}

	private function embeddingWithFakeClient() : Embedding
	{
		// construct Embedding first: its file's require_once '../vendor/autoload.php' is what
		// registers the \OpenAI autoloader in the first place - referencing \OpenAI::factory()
		// first (e.g. if this is the process's very first touch of anything rag-related) fails
		// with "Class OpenAI not found"
		$embedding = new Embedding();

		$httpClient = new class implements ClientInterface {
			public function sendRequest(RequestInterface $request) : ResponseInterface
			{
				$body = json_decode((string)$request->getBody(), true);
				$data = [];
				foreach ((array)($body['input'] ?? ['']) as $i => $text)
				{
					$data[] = ['object' => 'embedding', 'embedding' => array_fill(0, 1024, 0.0), 'index' => $i];
				}
				$response = (new Psr17Factory())->createResponse(200)
					->withHeader('Content-Type', 'application/json');
				$response->getBody()->write(json_encode([
					'object' => 'list', 'model' => 'bge-m3', 'data' => $data,
					'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1],
				]));
				return $response;
			}
		};
		$client = \OpenAI::factory()
			->withHttpClient($httpClient)
			->withBaseUri('http://fake-embeddings.invalid/v1')
			->withApiKey('unused')
			->make();

		$property = new ReflectionProperty(Embedding::class, 'client');
		$property->setAccessible(true);
		$property->setValue($embedding, $client);
		return $embedding;
	}

	private function chunkCount(int $contactId) : int
	{
		return (int)$GLOBALS['egw']->db->select(Embedding::TABLE, 'count(*)', [
			'rag_app' => 'addressbook', 'rag_app_id' => $contactId,
		], __LINE__, __FILE__, false, '', Embedding::APP)->fetchColumn();
	}

	public function testEmbedWritesBothFulltextAndRagRowsForANewEntry()
	{
		$note = str_repeat('a distinctive '.uniqid().' sentence used to pad this note out. ', 3);
		$this->contactId = $this->insertRawContact($note);

		$this->embeddingWithFakeClient()->embed(['app' => 'addressbook', 'id' => $this->contactId]);

		$fulltext = $GLOBALS['egw']->db->select(Embedding::FULLTEXT_TABLE, ['ft_title', 'ft_description'], [
			'ft_app' => 'addressbook', 'ft_app_id' => $this->contactId,
		], __LINE__, __FILE__, false, '', Embedding::APP)->fetch();
		$this->assertIsArray($fulltext, 'embed() must have written a fulltext-index row');
		$this->assertSame($note, $fulltext['ft_description']);

		$this->assertGreaterThan(0, $this->chunkCount($this->contactId),
			'embed() must have written at least one RAG/embedding chunk row');
	}

	public function testReembeddingAnUnchangedEntryIsANoOp()
	{
		$note = str_repeat('unchanged content '.uniqid().' repeated. ', 3);
		$this->contactId = $this->insertRawContact($note);

		$this->embeddingWithFakeClient()->embed(['app' => 'addressbook', 'id' => $this->contactId]);
		$firstCount = $this->chunkCount($this->contactId);

		// re-running embed() on the SAME, now up-to-date entry must not add/duplicate chunks
		$this->embeddingWithFakeClient()->embed(['app' => 'addressbook', 'id' => $this->contactId]);

		$this->assertSame($firstCount, $this->chunkCount($this->contactId));
	}

	public function testShrinkingContentDeletesExcessOldChunks()
	{
		$chunkSizeProp = new ReflectionProperty(Embedding::class, 'chunk_size');
		$chunkSizeProp->setAccessible(true);
		$chunkOverlapProp = new ReflectionProperty(Embedding::class, 'chunk_overlap');
		$chunkOverlapProp->setAccessible(true);
		$chunkSize = $chunkSizeProp->getValue();
		$chunkOverlap = $chunkOverlapProp->getValue();

		// long enough to force multiple chunks with the currently configured chunk_size/overlap
		$longNote = str_repeat('word'.uniqid().' ', (int)ceil(($chunkSize * 3) / 9));
		$this->contactId = $this->insertRawContact($longNote);

		$this->embeddingWithFakeClient()->embed(['app' => 'addressbook', 'id' => $this->contactId]);
		$chunksBefore = $this->chunkCount($this->contactId);
		$this->assertGreaterThan(1, $chunksBefore,
			'test setup should have produced multiple chunks - adjust the padding length if this fails');

		// shrink well below one chunk's worth, forcing fewer chunks on re-embed
		$this->updateContactNote($this->contactId, str_repeat('x', max(60, $chunkOverlap + 10)));
		$this->embeddingWithFakeClient()->embed(['app' => 'addressbook', 'id' => $this->contactId]);

		$this->assertLessThan($chunksBefore, $this->chunkCount($this->contactId),
			'excess chunk rows from the longer version must be deleted, not left behind');
	}
}
