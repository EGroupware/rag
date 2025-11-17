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
	 * @return Generator<array>
	 */
	abstract public function getUpdated();

	/**
	 * Return SQL fragment to search entries similar to the given embedding
	 *
	 * @param array $embedding embedding for the pattern to search
	 * @return string SQL fragment
	 */
	public function search(array $embedding, ?string &$join=null)
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

	protected function getJoin(string $timestamp='timestamp', array &$where=[])
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
		$where[] = '('.Embedding::EMBEDDING_UPDATED.' IS NULL OR '.Embedding::EMBEDDING_UPDATED.'<'.$modified.')';

		return 'LEFT JOIN '.Embedding::TABLE.' ON '.Embedding::EMBEDDING_APP.'='.$this->db->quote(static::APP).' AND '.
			Embedding::EMBEDDING_APP_ID.'='.static::ID.' AND '.Embedding::EMBEDDING_CHUNK.'=0';
	}
}