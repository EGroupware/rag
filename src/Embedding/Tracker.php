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
	const CREATED = 'tr_created';
	const TITLE = 'tr_summary';
	const DESCRIPTION = 'tr_description';
	protected static $additional_cols = ['tr_cc', 'tr_edit_mode'];
	const NOT_DELETED = 'tr_status<>'.\tracker_so::STATUS_DELETED;
	const REPLIES_TABLE = 'egw_tracker_replies';
	const REPLY_ID = 'reply_id';
	const REPLY_MESSAGE = 'reply_message';
	const EXTRA_TABLE = 'egw_tracker_extra';
	const EXTRA_ID = 'tr_id';
	const EXTRA_NAME = 'tr_extra_name';
	const EXTRA_VALUE = 'tr_extra_value';

	/**
	 * Allows row-specific modifications without overwriting getUpdated()
	 * - add replies
	 * - transform html --> plaintext depending on tr_edit_mode
	 *
	 * @param array|null $row
	 * @param bool $fulltext
	 * @return void
	 */
	protected function processRow(array &$row=null, bool $fulltext=false)
	{
		if ($row['tr_edit_mode'] === 'html')
		{
			$row[self::DESCRIPTION] = trim(strip_tags($row[self::DESCRIPTION]));
		}
		foreach($this->db->select(self::REPLIES_TABLE, [self::REPLY_ID, self::REPLY_MESSAGE], [
			self::ID => $row[self::ID],
		], __LINE__, __FILE__, 0, 'ORDER BY '.self::REPLY_ID, self::APP) as $reply)
		{
			if ($row['tr_edit_mode'] === 'html')
			{
				$reply[self::REPLY_MESSAGE] = trim(strip_tags($reply[self::REPLY_MESSAGE]));
			}
			$row['r'.$reply[self::REPLY_ID]] = $reply[self::REPLY_MESSAGE];
		}
		unset($row['tr_edit_mode']);
		if (!$fulltext) unset($row['tr_cc']);   // only makes sense for fulltext index, not RAG/embeddings
	}
}