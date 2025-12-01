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
	const EXTRA_TABLE = 'egw_infolog_extra';
	const EXTRA_ID = 'info_id';
	const EXTRA_NAME = 'info_extra_name';
	const EXTRA_VALUE = 'info_extra_value';

	/**
	 * Get updated entries
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
			self::NOT_DELETED, // no need to embed deleted entries
		];
		$cols = [self::ID, self::TITLE, self::DESCRIPTION, 'info_location', 'info_from'];
		// check / process hook-data to not query entry again, if already contained
		if ($hook_data && $hook_data['app'] === self::APP && !empty($hook_data['id']))
		{
			$where[self::ID] = $hook_data['id'];
			$entries = self::getRowFromNotifyHookData($hook_data, $cols);
		}
		$join = $this->getJoin('int', $where, $fulltext);
		do
		{
			$r = 0;
			foreach ($entries ?? $this->db->select(self::TABLE, $cols,
				$where, __LINE__, __FILE__, 0, 'ORDER BY ' . self::MODIFIED . ' ASC', '',
				self::CHUNK_SIZE, $join) as $row)
			{
				// makes no sense to calculate embeddings for PGP encrypted descriptions
				if (str_starts_with($row[self::DESCRIPTION], '-----BEGIN PGP MESSAGE-----'))
				{
					$row[self::DESCRIPTION]='';
				}
				$row = $this->getExtraTexts($row[self::ID], $row, $hook_data['data']??null);
				++$r;
				yield $row;
			}
		} while ($r === self::CHUNK_SIZE);
	}
}