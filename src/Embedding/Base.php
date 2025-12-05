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

namespace EGroupware\Rag\Embedding;

use EGroupware\Api;
use JMS\Serializer\Exception\InvalidArgumentException;
use EGroupware\Rag\Embedding;

/**
 * Base class for app-specific RAG plugins
 *
 * In almost all cases, you only want to set the constants at the top, and maybe reimplement processRow().
 */
abstract class Base
{
	/**
	 * v-- need to be overwritten in plugin class
	 */
	/**
	 * App-name
	 */
	const APP = '';
	/**
	 * Main table
	 */
	const TABLE = '';
	/**
	 * Auto-ID column of main-table
	 */
	const ID = '';
	/**
	 * Modification time column
	 */
	const MODIFIED = '';
	/**
	 * Type of modified column: 'int' or 'timestamp'
	 */
	const MODIFIED_TYPE = 'int';
	/**
	 * Title column
	 */
	const TITLE = '';
	/**
	 * Description column
	 */
	const DESCRIPTION = '';
	/**
	 * @var string[] additional cols to index
	 */
	protected static $additional_cols = [];
	/**
	 * SQL fragment to exclude deleted entries e.g. 'deleted IS NOT NULL'
	 */
	const NOT_DELETED = '';
	/**
	 * SQL fragment to exclude entries from being indexed by the AG, e.g. based on their length
	 */
	const RAG_EXTRA_CONDITION = '';
	/**
	 * Custom fields table
	 */
	const EXTRA_TABLE = '';
	/**
	 * Should be identical to ID
	 */
	const EXTRA_ID = '';
	/**
	 * Name of CF
	 */
	const EXTRA_NAME = '';
	/**
	 * Value of CF
	 */
	const EXTRA_VALUE = '';
	/**
	 * ^-- need to be overwritten in plugin class
	 */

	/**
	 * Number of entries queried from the DB
	 */
	const CHUNK_SIZE = 10;

	/**
	 * @var Api\Db;
	 */
	protected $db;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		$this->db = $GLOBALS['egw']->db;
	}

	/**
	 * Allows row-specific modifications without overwriting getUpdated()
	 *
	 * @param array|null $row
	 * @param bool $fulltext
	 * @return void
	 */
	protected function processRow(array &$row=null, bool $fulltext=false)
	{

	}

	/**
	 * Get updated / not yet indexed app-entries
	 *
	 * Should only be overwritten in plugin classes if processRow is not sufficient!.
	 *
	 * @param bool $fulltext false: check the rag, true: check fulltext index
	 * @param ?array $hook_data null or data from notify-all hook, to just emit this entry
	 * @return \Generator<array>
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 */
	public function getUpdated(bool $fulltext=false, ?array $hook_data=null)
	{
		$where = [
			static::NOT_DELETED, // no need to embed deleted entries
		];
		$cols = array_merge([static::ID, static::TITLE, static::DESCRIPTION], static::$additional_cols);
		// check / process hook-data to not query entry again, if already contained
		if ($hook_data && $hook_data['app'] === static::APP && !empty($hook_data['id']))
		{
			$where[static::ID] = $hook_data['id'];
			$entries = self::getRowFromNotifyHookData($hook_data, $cols);
		}
		if (!$fulltext && static::RAG_EXTRA_CONDITION)
		{
			$where[] = static::RAG_EXTRA_CONDITION;
		}
		$join = $this->getJoin(static::MODIFIED_TYPE, $where, $fulltext);
		do
		{
			$r = 0;
			foreach ($entries ?? $this->db->select(static::TABLE, $cols,
				$where, __LINE__, __FILE__, 0, 'ORDER BY ' . static::MODIFIED . ' ASC', '',
				static::CHUNK_SIZE, $join) as $row)
			{
				$this->processRow($row, $fulltext);
				$row = $this->getExtraTexts($row[static::ID], $row, $hook_data['data']??null);
				++$r;
				yield $row;
			}
		} while ($r === static::CHUNK_SIZE);
	}

	/**
	 * Return SQL fragment to search entries similar to the given embedding
	 *
	 * @param array $embedding embedding for the pattern to search
	 * @return string SQL fragment
	 */
	public function searchColumnJoin(array $embedding, ?string &$join=null)
	{
		$join = ' JOIN '.Embedding::TABLE.' ON '.Embedding::EMBEDDING_APP.'='.$this->db->quote(static::APP).
			' AND '.Embedding::EMBEDDING_APP_ID.'='.static::TABLE.'.'.static::ID;
		return '(SELECT MIN(VEC_DISTANCE_COSINE('.Embedding::EMBEDDING.', '.$this->db->quote($embedding, 'vector').')))';
	}

	/**
	 * Get join for egw_rag(_fulltext) table to check of not yet updated/created embeddings/fulltext index
	 *
	 * @param string $timestamp
	 * @param array &$where
	 * @param bool $fulltext false: check the rag, true: check fulltext index
	 * @return string
	 */
	protected function getJoin(string $timestamp='timestamp', array &$where=[], bool $fulltext=false)
	{
		switch ($timestamp)
		{
			case 'timestamp':
				$modified = static::MODIFIED;
				break;
			case 'int':
				$modified = $this->db->from_unixtime(static::MODIFIED);
				break;
			default:
				throw new InvalidArgumentException("Invalid / not implemented timestamp type='$timestamp'!");
		}
		if (!$fulltext)
		{
			$where[] = '('.Embedding::EMBEDDING_UPDATED.' IS NULL OR '.Embedding::EMBEDDING_UPDATED.'<'.$modified.')';

			return 'LEFT JOIN '.Embedding::TABLE.' ON '.Embedding::EMBEDDING_APP.'='.$this->db->quote(static::APP).' AND '.
				Embedding::EMBEDDING_APP_ID.'='.static::ID.' AND '.Embedding::EMBEDDING_CHUNK.'=0';
		}
		$where[] = '('.Embedding::FULLTEXT_UPDATED.' IS NULL OR '.Embedding::FULLTEXT_UPDATED.'<'.$modified.')';

		return 'LEFT JOIN '.Embedding::FULLTEXT_TABLE.' ON '.Embedding::FULLTEXT_APP.'='.$this->db->quote(static::APP).' AND '.
			Embedding::FULLTEXT_APP_ID.'='.static::ID;
	}

	/**
	 * Get values from textual custom-fields
	 *
	 * @param int $id
	 * @param array $texts
	 * @param array|null $hook_data
	 * @return array
	 * @throws Api\Db\Exception
	 * @throws Api\Db\Exception\InvalidSql
	 */
	protected function getExtraTexts(int $id, array $texts=[], ?array $hook_data=null) : array
	{
		static $cfs=null;
		if (!isset($cfs))
		{
			$cfs = array_filter(Api\Storage\Customfields::get(static::APP), static function($cf)
			{
				return in_array($cf['type'], ['text', 'htmlarea']);
			});
		}
		if ($cfs)
		{
			// check if we hook-data already supplies ALL values, then and only then use it instead querying the DB
			if ($hook_data)
			{
				$rows = [];
				foreach(array_keys($cfs) as $cf)
				{
					if (!array_key_exists('#'.$cf, $hook_data))
					{
						$rows = null;
						break;
					}
					$rows[] = [
						static::EXTRA_ID    => $id,
						static::EXTRA_NAME  => $cf,
						static::EXTRA_VALUE => $hook_data['#'.$cf],
					];
				}
			}
			foreach($rows ?? $this->db->select(static::EXTRA_TABLE, [static::EXTRA_ID, static::EXTRA_NAME, static::EXTRA_VALUE], [
				static::EXTRA_ID => $id,
				static::EXTRA_NAME => array_keys($cfs),
			], __LINE__, __FILE__, false, 'ORDER BY '.static::EXTRA_NAME, static::APP) as $row)
			{
				if ($cfs[$row[static::EXTRA_NAME]]['type'] == 'htmlarea')
				{
					$row[static::EXTRA_VALUE] = trim(strip_tags($row[static::EXTRA_VALUE]));
				}
				$texts[$row[STATIC::EXTRA_NAME]] = $row[static::EXTRA_VALUE];
			}
		}
		return $texts;
	}

	/**
	 * Check if hook-data contains all required columns and returns them in order (!)
	 *
	 * @param array $data hook-data incl. optional $data['data'] containing (partial) entry
	 * @param array $cols columns to query
	 * @return array[]|null array with one row containing values from $data['data'] for all $cols
	 */
	protected function getRowFromNotifyHookData(array $data, array $cols) : ?array
	{
		$row = [];
		foreach($cols as $col)
		{
			if (!array_key_exists($col, $data['data']??[]))
			{
				return null;
			}
			$row[$col] = $data['data'][$col];
		}
		return [$row];
	}
}