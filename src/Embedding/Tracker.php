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
 * Plugin for Tracker
 */
class Tracker extends Base
{
	const APP = 'tracker';
	const TABLE = 'egw_tracker';
	const ID = 'tr_id';
	const MODIFIED = 'tr_modified';
	const TITLE = 'tr_summary';
	const DESCRIPTION = 'tr_description';
	const NOT_DELETED = "tr_status<>".\tracker_so::STATUS_DELETED;
	const REPLIES_TABLE = 'egw_tracker_replies';
	const REPLY_ID = 'reply_id';
	const REPLY_MESSAGE = 'reply_message';
	const EXTRA_TABLE = 'egw_tracker_extra';
	const EXTRA_ID = 'tr_id';
	const EXTRA_NAME = 'tr_extra_name';
	const EXTRA_VALUE = 'tr_extra_value';

	/**
	 * Get updated entries
	 *
	 * @param bool $fulltext false: check the rag, true: check fulltext index
	 * @return \Generator<array>
	 * @throws \EGroupware\Api\Db\Exception
	 * @throws \EGroupware\Api\Db\Exception\InvalidSql
	 */
	public function getUpdated(bool $fulltext=false)
	{
		$where = [
			self::NOT_DELETED, // no need to embed deleted entries
		];
		$join = $this->getJoin('int', $where, $fulltext);
		do
		{
			$r = 0;
			foreach ($this->db->select(self::TABLE, [self::ID, self::TITLE, self::DESCRIPTION, 'tr_edit_mode'],
				$where, __LINE__, __FILE__, 0, 'ORDER BY ' . self::MODIFIED . ' ASC', '',
				self::CHUNK_SIZE, $join) as $row)
			{
				if ($row['tr_edit_mode'] === 'html')
				{
					$row[self::DESCRIPTION] = trim(strip_tags($row[self::DESCRIPTION]));
				}
				$row['replies'] = [];
				foreach($this->db->select(self::REPLIES_TABLE, [self::REPLY_ID, self::REPLY_MESSAGE], [
					self::ID => $row[self::ID],
				], __LINE__, __FILE__, 0, 'ORDER BY '.self::REPLY_ID, self::APP) as $reply)
				{
					if ($row['tr_edit_mode'] === 'html')
					{
						$reply[self::REPLY_MESSAGE] = trim(strip_tags($reply[self::REPLY_MESSAGE]));
					}
					$row['r'.$row[self::REPLY_ID]] = $reply[self::REPLY_MESSAGE];
				}
				unset($row['tr_edit_mode']);
				$row = $this->getExtraTexts($row[self::ID], $row);
				++$r;
				yield $row;
			}
		} while ($r === self::CHUNK_SIZE);
	}
}