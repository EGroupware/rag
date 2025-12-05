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
	const FULLTEXT_TABLE = 'egw_rag_fulltext';
	const FULLTEXT_UPDATED = 'ft_updated';
	const FULLTEXT_APP = 'ft_app';
	const FULLTEXT_APP_ID = 'ft_app_id';
	const FULLTEXT_TITLE = 'ft_title';
	const FULLTEXT_DESCRIPTION = 'ft_description';
	const FULLTEXT_EXTRA = 'ft_extra';

	/**
	 * Name of config storing the last errors of RAG's async-job
	 */
	const RAG_LAST_ERRORS = 'rag-last-errors';

	/**
	 * Stop calculating embeddings after this time: 5min - 15sec
	 *
	 * So the async job stops before the next one starts.
	 */
	const MAX_RUNTIME = 285;

	/**
	 * @var ?OpenAI\Client
	 */
	protected ?OpenAI\Client $client=null;

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
	 * @var int log-level: 0: errors only, 1: result of search*() methods
	 */
	protected int $log_level = 0;

	/**
	 * @var string base-url of OpenAI compatible api (you can NOT use localhost!):
	 * - IONOS:  https://openai.inference.de-txl.ionos.com/v1
	 * - Ollama: http://172.17.0.1:11434/v1  requires Ollama to be bound on all interfaces / 0.0.0.0 (not just localhost!)
	 * - Ralf's Ollama: http://10.44.253.3:11434/v1
	 */
	protected static ?string $url = null;
	protected static ?string $api_key = null;

	/**
	 * Total number of rows of last search*() call
	 *
	 * @var int|null
	 */
	public ?int $total;

	public function __construct(int $log_level = 0)
	{
		if (self::$url)
		{
			$factory = Openai::factory();
			if (self::$url) $factory->withBaseUri(self::$url);
			if (self::$api_key) $factory->withApiKey(self::$api_key);
			$this->client = $factory->make();
		}
		$this->db = $GLOBALS['egw']->db;
		$this->log_level = $log_level;
	}

	/**
	 * Init our static variables from configuration
	 */
	public static function initStatic()
	{
		$config = Api\Config::read(self::APP);

		self::$url = $config['url'] ?? null;
		self::$api_key = $config['api_key'] ?? null;

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
	 * @param array $data
	 * @return void
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 */
	public static function notify($data)
	{
		// check if we're interested in the given app
		if (empty($data['app']) || !isset(self::plugins()[$data['app']]))
		{
			return;
		}
		// delete embeddings of deleted entries
		if ($data['type'] === 'delete' && !empty($data['hold_for_purge']) && !empty($data['id']))
		{
			/** @var Api\Db $db */
			$db = $GLOBALS['egw']->db;
			$db->delete(self::TABLE, [
				self::EMBEDDING_APP => $data['app'],
				self::EMBEDDING_APP_ID => $data['id'],
			], __LINE__, __FILE__, self::APP);
			$db->delete(self::FULLTEXT_TABLE, [
				self::FULLTEXT_APP => $data['app'],
				self::FULLTEXT_APP_ID => $data['id'],
			], __LINE__, __FILE__, self::APP);
		}
		// install the async job for added or updated entries, if directly adding them failed
		elseif ($data['type'] !== 'delete')
		{
			try {
				$rag = new self;
				$rag->embed($data);
			}
			catch (\Exception $e) {
				self::installAsyncJob();
			}
		}
	}

	/**
	 * Get all embedding plugins, searching installed apps for a class named:
	 * - EGroupware\Rag\Embedding\<App-name>
	 * - EGroupware\<App-name>\Rag
	 *
	 * @return array app-name => class-name pairs
	 */
	public static function plugins() : array
	{
		return Api\Cache::getTree(self::APP, 'app_plugins', static function()
		{
			$plugins = [];
			foreach(array_keys($GLOBALS['egw_info']['apps'] ?? []) as $app)
			{
				$app_class = ucfirst($app);
				if (class_exists($class="EGroupware\\Rag\\Embedding\\$app_class") ||
					class_exists($class="EGroupware\\$app_class\\Rag"))
				{
					$plugins[$app] = $class;
				}
			}
			return $plugins;
		}, [], 86400);
	}

	/**
	 * Run all embedding plugins and embed all not yet or updated entries
	 *
	 * @param ?array $hook_data null or data from notify-all hook, to just embed this entry
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 * @throws Api\Exception\WrongParameter
	 */
	public function embed(?array $hook_data=null)
	{
		$start = microtime(true);
		foreach(self::plugins() as $app => $class)
		{
			if ($hook_data && $hook_data['app'] !== $app) continue;

			try {
				/** @var Embedding\Base $plugin */
				$plugin = new $class();

				foreach([
			         'fulltext' => true,
		         ] + ($this->client ? [    // only add RAG/embeddings, if configured
					'rag' => false,
				] : []) as $fulltext)
				{
					$entry = null;
					foreach ($plugin->getUpdated($fulltext, $hook_data) as $entry)
					{
						if (microtime(true) - $start > self::MAX_RUNTIME)
						{
							break;
						}
						$extra = $entry;
						$id = array_shift($extra);
						$title = array_shift($extra);
						$description = array_shift($extra);
						try
						{
							// fulltext index or RAG/embeddings
							if ($fulltext)
							{
								$extra = $extra ? array_values(array_filter(array_map('trim', $extra), static function ($v) {
									return $v && strlen((string)$v) > 3;
								})) : null;
								if (!$extra || count($extra) <= 1)
								{
									$extra = $extra ? $extra[0] : null;
								}
								else
								{
									$extra = json_encode($extra,
										JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_IGNORE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
								}
								$this->db->insert(self::FULLTEXT_TABLE, [
									self::FULLTEXT_TITLE => $title ?: null,
									self::FULLTEXT_DESCRIPTION => $description ?: null,
									self::FULLTEXT_EXTRA => $extra,
								], [
									self::FULLTEXT_APP => $app,
									self::FULLTEXT_APP_ID => $id,
								], __LINE__, __FILE__, self::APP);
								continue;
							}
							if (self::$minimize_chunks)
							{
								$chunks = self::chunkSplit($title . "\n" . $description .
									($extra ? "\n" . implode("\n", $extra) : ""));
							}
							else
							{
								$chunks = self::chunkSplit($description, [$title]);
								// embed each reply or $cfs on its own
								foreach ($extra as $field)
								{
									$chunks = self::chunkSplit($field, $chunks);
								}
							}

							try
							{
								$response = $this->client->embeddings()->create([
									'model' => self::$model,
									'input' => $chunks,
								]);
							}
							catch (\Exception $e)   // JsonException of unsure namespace, therefore catch them all
							{
								// fix invalid utf-8 characters by replacing them BEFORE calculating the embeddings
								if (str_starts_with($e->getMessage(), 'Malformed UTF-8 characters, possibly incorrectly encoded'))
								{
									$chunks = json_decode(json_encode($chunks, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), true);
									$response = $this->client->embeddings()->create([
										'model' => self::$model,
										'input' => $chunks,
									]);
									unset($e);
								}
							}
						}
						catch (\Throwable $e)
						{
						}
						// handle all exceptions by logging them to RAG-config and PHP error-log, and then continuing with the next entry
						if (isset($e))
						{
							self::logError($e, $app, $fulltext, $entry);
							// if called in hook, don't continue
							if ($hook_data) return;
							unset($e);
							continue;
						}
						$n = -1;
						foreach ($response->embeddings as $n => $embedding)
						{
							$this->db->insert(self::TABLE, [
								self::EMBEDDING => $embedding->embedding,
							], [
								self::EMBEDDING_APP => $app,
								self::EMBEDDING_APP_ID => $id,
								self::EMBEDDING_CHUNK => $n,
							], __LINE__, __FILE__, self::APP);
						}
						// delete excess old chunks, if there are any
						$this->db->delete(self::TABLE, [
							self::EMBEDDING_APP => $app,
							self::EMBEDDING_APP_ID => $id,
							self::EMBEDDING_CHUNK . '>' . $n,
						], __LINE__, __FILE__, self::APP);
					}
				}
			}
			catch (\Throwable $e) {
				// catch and log all errors, also the ones in the app-plugins
				self::logError($e, $app, $fulltext??null, $entry??null);
				continue;   // with next plugin/app
			}
		}
		// if we finished, we can remove the job (gets readded for new entries via notify hook)
		if (!$hook_data && microtime(true) - $start < self::MAX_RUNTIME)
		{
			self::removeAsyncJob();
		}
	}

	/**
	 * Log an exception to RAG configuration to display
	 *
	 * @param \Throwable $e
	 * @param ?string $fulltext
	 * @param ?string $app
	 * @param ?array $entry
	 * @throws Api\Exception\WrongParameter
	 */
	public static function logError(\Throwable $e, $app=null, $fulltext=null, $entry=null)
	{
		error_log(__METHOD__ . "() app=$app, fulltext=$fulltext, entry=".json_encode($entry, JSON_INVALID_UTF8_IGNORE));
		_egw_log_exception($e);
		// store the last N errors to display in RAG config
		$errors = Api\Config::read(self::APP)[self::RAG_LAST_ERRORS] ?? [];
		array_unshift($errors, [
			'date' => new Api\DateTime(),
			'message' => $e->getMessage(),
			'app' => $app,
			'rag-or-fulltext' => $fulltext ? 'fulltext' : 'rag',
			'entry' => $entry,
			'code' => $e->getCode(),
			'class' => get_class($e),
			'trace' => $e->getTrace(),
		]);
		Api\Config::save_value(self::RAG_LAST_ERRORS, array_slice($errors, 0, 5), self::APP);
	}

	/**
	 * Hybrid search in RAG and fulltext index for given app and $pattern
	 *
	 * Returns found IDs and their distance ordered by the smallest distance / the best match first,
	 * then the fulltext search results with the highest relevance first.
	 * Same entries are only returned once with their embedding distance!.
	 *
	 * @ToDo: better way to merge fulltext and embedding searches
	 * @param string $pattern
	 * @param ?string|string[] $app app-name(s) or '' or NULL for searching all apps
	 * @param int $start default 0
	 * @param int $num_rows default 50
	 * @param float $max_distance default .4
	 * @return float[] int id => float distance pairs, for $app === '' we return string "$app:$id"
	 *  If there is no result, we return [0 => 1.0], to not generate an SQL error, but an empty result!
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 */
	public function search(string $pattern, $app=null, int $start=0, int $num_rows=50, float $max_distance=.4) : array
	{
		if (!$this->client)
		{
			return $this->searchFulltext($pattern, $app, $start, $num_rows, $max_distance) ?: [0=>1.0];
		}
		// quick/dump approach for merging: always query from start=0, $start+$num_rows rows, and then slice
		$embedding_matches = $this->searchEmbeddings($pattern, $app, 0, $start+$num_rows, $max_distance);
		$total_embeddings = $this->total ?? 0;

		$fulltext_matches = $this->searchFulltext($pattern, $app, 0, $start+$num_rows);

		// + makes sure to return every entry only once, with the embeddings first
		$both = $embedding_matches+$fulltext_matches;

		// we can only subtract the entries found in both returned sets, but there might be more in common ...
		$this->total += $total_embeddings - (count($embedding_matches)+count($fulltext_matches)-count($both));
		$both = array_slice($both, $start, $num_rows, true) ?: [0 => 1.0];
		if ($this->log_level)
		{
			error_log(__METHOD__."('$pattern', '$app', start=$start, num_rows=$num_rows, max_distance=$max_distance) total=$this->total returning ".
				json_encode($both));
		}
		return $both;
	}

	/**
	 * Semantic search in given app for $pattern
	 *
	 * Returns found IDs and their distance ordered by the smallest distance / the best match first.
	 *
	 * @param string $pattern
	 * @param ?string|string[] $app app-name(s) or '' or NULL for searching all apps
	 * @param int $start default 0
	 * @param int $num_rows default 50
	 * @param float $max_distance default .4
	 * @return float[] int id => float distance pairs, for $app === '' we return string "$app:$id"
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 */
	public function searchEmbeddings(string $pattern, $app=null, int $start=0, int $num_rows=50, float $max_distance=.4) : array
	{
		try {
			$response = $this->client->embeddings()->create([
				'model' => self::$model,
				'input' => [$pattern],
			]);
		}
		catch (\Exception $e) {
			_egw_log_exception($e);
			throw $e;
		}
		$cols = [
			self::EMBEDDING_APP,
			self::EMBEDDING_APP_ID,
			'VEC_DISTANCE_COSINE('.Embedding::EMBEDDING.', '.$this->db->quote($response->embeddings[0]->embedding, 'vector').') as distance',
		];
		$id_distance = [];
		foreach($this->db->select(self::TABLE, 'SQL_CALC_FOUND_ROWS '.implode(',', $cols), $app ? [
			self::EMBEDDING_APP => $app,
		] : false, __LINE__, __FILE__, $start, 'HAVING distance<'.$max_distance.' ORDER BY distance', self::APP, $num_rows) as $row)
		{
			$id = $app ? (int)$row[self::EMBEDDING_APP_ID] : $row[self::EMBEDDING_APP].':'.$row[self::EMBEDDING_APP_ID];
			// only insert the first / best match, as multiple chunks could match
			if (!isset($id_distance[$id]))
			{
				$id_distance[$id] = (float)$row['distance'];
			}
		}
		$this->total = $this->db->query('SELECT FOUND_ROWS()')->fetchColumn();
		if ($this->log_level)
		{
			error_log(__METHOD__."('$pattern', '$app', start=$start, num_rows=$num_rows, max_distance=$max_distance) total=$this->total returning ".
				json_encode($id_distance));
		}
		return $id_distance;
	}

	/**
	 * Run a fulltext search for $pattern
	 *
	 * @param string $pattern
	 * @param ?string|string[] $app app-name(s) or '' or NULL for searching all apps
	 * @param int $start default 0
	 * @param int $num_rows default 50
	 * @param float $min_relevance default 0
	 * @return float[] int id => float relevance pairs, for $app === '' we return string "$app:$id"
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 */
	public function searchFulltext(string $pattern, $app=null, int $start=0, int $num_rows=50, float $min_relevance=0) : array
	{
		$id_relevance = [];
		$match = 'MATCH('.self::FULLTEXT_TITLE.','.self::FULLTEXT_DESCRIPTION.','.self::FULLTEXT_EXTRA.') AGAINST('.$this->db->quote($pattern).')';
		$cols = [
			self::FULLTEXT_APP,
			self::FULLTEXT_APP_ID,
			$match.' AS relevance',
		];
		foreach($this->db->select(self::FULLTEXT_TABLE, 'SQL_CALC_FOUND_ROWS '.implode(',', $cols) ,
			($app ? [self::FULLTEXT_APP => $app] : [])+[$match],
			__LINE__, __FILE__, $start, 'ORDER BY relevance DESC', self::APP, $num_rows) as $row)
		{
			if ($row['relevance'] < $min_relevance)
			{
				break;
			}
			$id = $app ? (int)$row[self::FULLTEXT_APP_ID] : $row[self::FULLTEXT_APP].':'.$row[self::FULLTEXT_APP_ID];
			$id_relevance[$id] = (float)$row['relevance'];
		}
		$this->total = $this->db->query('SELECT FOUND_ROWS()')->fetchColumn();
		if ($this->log_level)
		{
			error_log(__METHOD__."('$pattern', '$app', start=$start, num_rows=$num_rows, min_relevance=$min_relevance) total=$this->total returning ".
				json_encode($id_relevance));
		}
		return $id_relevance;
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