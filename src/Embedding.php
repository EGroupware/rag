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
use GuzzleHttp\Exception\InvalidArgumentException;
use OpenAI;

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
	 * @var OpenAI\Client
	 */
	protected $client;

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
	/**
	 * @var bool minimize number of chunks e.g. by concatenating title and describtion
	 */
	public static bool $minimize_chunks = true;

	/**
	 * @var string base-url of OpenAI compatible api:
	 * - Ollama: http://172.17.0.1:11434/v1/v1
	 * - IONOS:  ...
	 */
	protected static string $url = 'http://172.17.0.1:11434/v1';
	protected static ?string $api_key = null;

	public function __construct()
	{
		$factory = Openai::factory();
		if (self::$url) $factory->withBaseUri(self::$url);
		if (self::$api_key) $factory->withApiKey(self::$api_key);
		$this->client = $factory->make();

		$this->db = $GLOBALS['egw']->db;
	}

	/**
	 * Init our static variables from configuration
	 */
	public static function initStatic()
	{
		$config = Api\Config::read(self::APP);

		self::$url = $config['url'] ?? 'http://172.17.0.1:11434/v1';
		self::$api_key = $config['url'] ?? null;

		self::$chunk_size = $config['chunk_size'] ?? 500;
		self::$chunk_overlap = $config['chunk_overlap'] ?? 50;
		self::$model = $config['embedding_model'] ?? 'bge-m3';
		self::$minimize_chunks = ($config['minimize_chunks'] ?? 'yes') !== 'no';
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

	const ASYNC_JOB = 'rag:embed';

	public static function installAsyncJob()
	{
		$async = new Api\Asyncservice();
		if (!$async->read(self::ASYNC_JOB))
		{
			$async->set_timer(['min'=>'*/5'], self::ASYNC_JOB, self::class.'::asyncJob');
		}
	}

	public static function removeAsyncJob()
	{
		$async = new Api\Asyncservice();
		$async->cancel_timer(self::ASYNC_JOB);
	}

	/**
	 * Callback for hook "notify-all" to enable embedding (on new/updated entries) and remove embeddings of deleted entries
	 *
	 * @param array $location
	 * @return void
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 */
	public static function notify($location)
	{
		// check if we're interested in the given app
		if (empty($location['app']) || !isset(self::plugins()[$location['app']]))
		{
			return;
		}
		// delete embeddings of deleted entries
		if ($location['type'] === 'delete' && !empty($location['hold_for_purge']) && !empty($location['id']))
		{
			/** @var Api\Db $db */
			$db = $GLOBALS['egw']->db;
			$db->delete(self::TABLE, [
				self::EMBEDDING_APP => $location['app'],
				self::EMBEDDING_APP_ID => $location['id'],
			], __LINE__, __FILE__, self::APP);
		}
		// install the async job for added or updated entries to embed
		elseif ($location['type'] !== 'delete')
		{
			self::installAsyncJob();
		}
	}

	/**
	 * Get all embedding plugins
	 *
	 * @return array app-name => class-name pairs
	 */
	public static function plugins() : array
	{
		return Api\Cache::getTree(self::APP, 'plugins', static function()
		{
			$plugins = [];
			foreach(scandir(__DIR__.'/Embedding') as $class)
			{
				if (in_array($class, ['.', '..', 'Base.php'])) continue;
				$app = strtolower($class = basename($class, '.php'));
				$class = __CLASS__ . '\\' . $class;
				$plugins[$app] = $class;
			}
			return $plugins;
		}, [], 86400);
	}

	/**
	 * Run all embedding plugins and embed all not yet or updated entries
	 *
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 * @throws Api\Exception\WrongParameter
	 */
	public function embed()
	{
		$start = microtime(true);
		foreach(self::plugins() as $app => $class)
		{
			$plugin = new $class();

			foreach ($plugin->getUpdated() as $entry)
			{
				if (microtime(true) - $start > self::MAX_RUNTIME)
				{
					break;
				}
				[$id, $title, $description, $modified, $replies] = array_values($entry)+[null, null, null, null, []];
				if (self::$minimize_chunks)
				{
					$chunks = self::chunkSplit($title."\n".$description.
						($replies ? "\n".implode("\n", $replies) : ""));
				}
				else
				{
					$chunks = self::chunkSplit($description, [$title]);
					// embed each reply on its own
					foreach($replies as $reply)
					{
						$chunks = self::chunkSplit($reply, $chunks);
					}
				}

				try
				{
					$response = $this->client->embeddings()->create([
						'model' => self::$model,
						'input' => $chunks,
					]);
				}
				catch (InvalidArgumentException $e)
				{
					// fix invalid utf-8 characters by replacing them BEFORE calculating the embeddings
					if ($e->getMessage() === 'json_encode error: Malformed UTF-8 characters, possibly incorrectly encoded')
					{
						try
						{
							$chunks = json_decode(json_encode($chunks, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), true);
							$response = $this->client->embeddings()->create([
								'model' => self::$model,
								'input' => $chunks,
							]);
							unset($e);
						}
						catch (\Exception $e)
						{
						}
					}
				}
				catch (\Exception $e)
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
						self::EMBEDDING => $embedding->embedding,
					], false, __LINE__, __FILE__, self::APP);
				}
			}
		}
		// if we finished, we can remove the job (gets readded for new entries via notify hook)
		if (microtime(true) - $start < self::MAX_RUNTIME)
		{
			self::removeAsyncJob();
		}
	}

	/**
	 * Semantic search in given app for $pattern
	 *
	 * Returns found IDs and their distance ordered by the smallest distance / the best match first.
	 *
	 * @param string $pattern
	 * @param string $app app-name of '' for searching all apps
	 * @param int $offset default 0
	 * @param int $num_rows default 50
	 * @param float $max_distance default .4
	 * @return float[] int id => float distance pairs, for $app === '' we return string "$app:$id"
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 */
	public function search(string $pattern, string $app, int $offset=0, int $num_rows=50, float $max_distance=.4) : array
	{
		$response = $this->client->embeddings()->create([
			'model' => self::$model,
			'input' => [$pattern],
		]);
		$id_distance = [];
		foreach($this->db->select(self::TABLE, [
			self::EMBEDDING_APP,
			self::EMBEDDING_APP_ID,
			'VEC_DISTANCE_COSINE('.Embedding::EMBEDDING.', '.$this->db->quote($response->embeddings[0]->embedding, 'vector').') as distance',
		], [
			self::EMBEDDING_APP => $app,
		], __LINE__, __FILE__, $offset, 'HAVING distance<'.$max_distance.' ORDER BY distance', self::APP, $num_rows) as $row)
		{
			$id = $app ? (int)$row[self::EMBEDDING_APP_ID] : $row[self::EMBEDDING_APP].':'.$row[self::EMBEDDING_APP_ID];
			// only insert the first / best match, as multiple chunks could match
			if (!isset($id_distance[$id]))
			{
				$id_distance[$id] = (float)$row['distance'];
			}
		}
		return $id_distance;
	}

	/**
	 * Create SQL fragment to return distance by id-column
	 *
	 * @param array $id_distance id => distance pairs
	 * @param string $id_column
	 * @return string SQL "CASE $id_column WHEN $id1 THEN $distance1 WHEN ... END"
	 */
	public static function distanceById(array $id_distance, string $id_column) : string
	{
		$sql = "CASE $id_column ";
		foreach ($id_distance as $id => $distance)
		{
			$sql .= " WHEN ".(int)$id." THEN ".(float)$distance;
		}
		$sql .= " END";
		return $sql;
	}

	/**
	 * Semantic search in given app for $pattern
	 *
	 * Returns an SQL fragment for a column and a join.
	 *
	 * @param string $pattern search query
	 * @param string $app app-name
	 * @return string SQL fragment
	 */
	public function searchColumnJoin(string $pattern, string $app, ?string &$join=null) : string
	{
		$response = $this->client->embeddings()->create([
			'model' => self::$model,
			'input' => [$pattern],
		]);
		$plugin = ucfirst(__CLASS__.'\\'.ucfirst($app));
		$plugin = new $plugin();
		return $plugin->searchColumnJoin($response->embeddings[0]->embedding, $join);
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
Embedding::initStatic();