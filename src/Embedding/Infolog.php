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

class Infolog extends Base
{
	const APP = 'infolog';
	const TABLE = 'egw_infolog';
	const ID = 'info_id';
	const MODIFIED = 'info_datemodified';
	const TITLE = 'info_subject';
	const DESCRIPTION = 'info_des';


	public function getUpdated()
	{
		$where = [];
		$join = $this->getJoin('int', $where);
		do
		{
			$r = 0;
			foreach ($this->db->select(self::TABLE, [self::ID, self::TITLE, self::DESCRIPTION, self::MODIFIED],
				$where, __LINE__, __FILE__, 0, 'ORDER BY ' . self::MODIFIED . ' ASC', '',
				self::CHUNK_SIZE, $join) as $row)
			{
				// makes no sense to calculate embeddings for PGP encrypted descriptions
				if (!str_starts_with($row['info_des'], '-----BEGIN PGP MESSAGE-----'))
				{
					unset($row['info_des']);
				}
				++$r;
				yield $row;
			}
		} while ($r === self::CHUNK_SIZE);
	}
}