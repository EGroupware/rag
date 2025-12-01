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

abstract class Base
{
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
	 * Get updated / not yet indexed app-entries
	 *
	 * @param bool $fulltext false: check the rag, true: check fulltext index
	 * @param ?array $hook_data null or data from notify-all hook, to just emit this entry
	 * @return Generator<array>
	 */
	abstract public function getUpdated(bool $fulltext=false, ?array $hook_data=null);

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

		return static::TABLE.'.'.static::ID.' IN (SELECT DISTINCT '.Embedding::EMBEDDING_APP_ID.
			' FROM '.Embedding::TABLE.
			' WHERE '.Embedding::EMBEDDING_APP.'='.$this->db->quote(static::APP).
			' ORDER BY VEC_DISTANCE_COSINE('.Embedding::EMBEDDING.', '.$this->db->quote($embedding, 'vector').'))';
			// MariaDB 11.8 does NOT support LIMIT in subquery :( ' LIMIT 10)';
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