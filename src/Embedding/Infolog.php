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

/**
 * Plugin for InfoLog
 */
class Infolog extends Base
{
	const APP = 'infolog';
	const TABLE = 'egw_infolog';
	const ID = 'info_id';
	const MODIFIED = 'info_datemodified';
	const TITLE = 'info_subject';
	const DESCRIPTION = 'info_des';
	const NOT_DELETED = "info_status<>'deleted'";

	/**
	 * Get updated entries
	 *
	 * @return Generator|\Generator
	 * @throws \EGroupware\Api\Db\Exception
	 * @throws \EGroupware\Api\Db\Exception\InvalidSql
	 */
	public function getUpdated()
	{
		$where = [
			self::NOT_DELETED, // no need to embed deleted entries
		];
		$join = $this->getJoin('int', $where);
		do
		{
			$r = 0;
			foreach ($this->db->select(self::TABLE, [self::ID, self::TITLE, self::DESCRIPTION, self::MODIFIED],
				$where, __LINE__, __FILE__, 0, 'ORDER BY ' . self::MODIFIED . ' ASC', '',
				self::CHUNK_SIZE, $join) as $row)
			{
				// makes no sense to calculate embeddings for PGP encrypted descriptions
				if (!str_starts_with($row[self::DESCRIPTION], '-----BEGIN PGP MESSAGE-----'))
				{
					unset($row[self::DESCRIPTION]);
				}
				++$r;
				yield $row;
			}
		} while ($r === self::CHUNK_SIZE);
	}
}