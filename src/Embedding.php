<?php
/**
 * EGroupware RAG system
 *
 * @package rag
 * @link https://www.egroupware.org
 * @author Ralf Becker <rb@egroupware.org>
 * @copyright 2025 by Ralf Becker <rb@egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Rag;

use EGroupware\Api;
use ArdaGnsrn\Ollama\Ollama;
use GuzzleHttp\Exception\InvalidArgumentException;

require_once __DIR__.'/../vendor/autoload.php';

class Embedding
{
	const APP = 'rag';
	const TABLE = 'egw_rag';
	const EMBEDDING_UPDATED = 'rag_updated';
	const EMBEDDING_APP = 'rag_app';
	const EMBEDDING_APP_ID = 'rag_app_id';
	const EMBEDDING_CHUNK = 'rag_chunk';
	const EMBEDDING = 'rag_embedding';

	/**
	 * Stop calculating embeddings after this time: 5min - 15sec
	 *
	 * So the async job stops before the next one starts.
	 */
	const MAX_RUNTIME = 285;


	/**
	 * @var Ollama
	 */
	protected $ollama;

	/**
	 * @var Api\Db;
	 */
	protected $db;

	/**
	 * @var int max size of chunk
	 */
	protected static int $chunk_size = 500;
	/**
	 * @var int overlap of chunks
	 */
	protected static int $chunk_overlap = 50;
	/**
	 * @var string embedding model to use
	 */
	protected static string $model = 'bge-m3';

	protected static string $url = 'http://10.44.253.3:11434';
	protected static ?string $api_key = null;

	public function __construct()
	{
		$this->ollama = Ollama::client(self::$url, self::$api_key);
		$this->db = $GLOBALS['egw']->db;
	}

	/**
	 * run an async job to update the RAG
	 *
	 * @return void
	 */
	public static function asyncJob()
	{
		$self = new self();
		$self->embed();
	}

	public function embed()
	{
		$start = microtime(true);
		foreach(scandir(__DIR__.'/Embedding'.self::APP) as $class)
		{
			if (in_array($app, ['.', '..', 'Base'])) continue;
			$app = strtolower($class);
			$class = __CLASS__ . '\\' . $class;
			$plugin = new $class();

			foreach ($plugin->getUpdated() as $entry)
			{
				if (microtime(true) - $start > self::MAX_RUNTIME)
				{
					break;
				}
				[$id, $title, $description] = array_values($entry);
				$chunks = self::chunkSplit($description, [$title]);
				// makes no sense to calculate embeddings from encrypted entries

				try
				{
					$response = $this->ollama->embed()->create([
						'model' => self::$model,
						'input' => $chunks,
					]);
				} catch (InvalidArgumentException $e)
				{
					// fix invalid utf-8 characters by replacing them BEFORE calculating the embeddings
					if ($e->getMessage() === 'json_encode error: Malformed UTF-8 characters, possibly incorrectly encoded')
					{
						try
						{
							$chunks = json_decode(json_encode($chunks, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), true);
							$response = $this->ollama->embed()->create([
								'model' => self::$model,
								'input' => $chunks,
							]);
							unset($e);
						} catch (\Exception $e)
						{
						}
					}
				} catch (\Exception $e)
				{
				}
				// handle all exceptions by logging them to PHP error-log and continuing with the next entry
				if (isset($e))
				{
					error_log(__METHOD__ . "() row=" . json_encode($entry, JSON_INVALID_UTF8_IGNORE));
					_egw_log_exception($e);
					unset($e);
					continue;
				}

				$this->db->delete(self::TABLE, [
					self::EMBEDDING_APP => $app,
					self::EMBEDDING_APP_ID => $id,
				], __LINE__, __FILE__, self::APP);

				foreach ($response->embeddings as $n => $embedding)
				{
					$this->db->insert(self::TABLE, [
						self::EMBEDDING_APP => $app,
						self::EMBEDDING_APP_ID => $id,
						self::EMBEDDING_CHUNK => $n,
						self::EMBEDDING => $embedding,
					], false, __LINE__, __FILE__, self::APP);
				}
			}
		}
	}

	/**
	 * Semantic search in given app for $pattern
	 *
	 * Returns an SQL fragment to be AND-ed in to query
	 *
	 * @param string $pattern search query
	 * @param string $app app-name
	 * @return string SQL fragment
	 */
	public function search(string $pattern, string $app, ?string &$join=null) : string
	{
		$response = $this->ollama->embed()->create([
			'model' => self::$model,
			'input' => [$pattern],
		]);
		$plugin = ucfirst(__CLASS__.'\\'.ucfirst($app));
		$plugin = new $plugin();
		return $plugin->search($response->embeddings[0], $join);
	}

	/**
	 * Split description into chunks
	 *
	 * Using self::$chunk_size and an overlap of self::$chunk_overlap.
	 *
	 * @param ?string $description
	 * @param array $chunks additional chunks, e.g. title
	 * @return array of chunks
	 */
	protected static function chunkSplit(?string $description, array $chunks=[])
	{
		if (!$description || !trim($description)) return $chunks;    // nothing to do

		$n = 0;
		while(strlen($chunk = substr($description, $n*(self::$chunk_size-self::$chunk_overlap),
			self::$chunk_size)) > self::$chunk_overlap || !$n)
		{
			$chunks[] = $chunk;
			$n++;
		}
		return $chunks;
	}
}