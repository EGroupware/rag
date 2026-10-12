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
 * Plugin for Timesheet
 */
class Calendar extends Base
{
	const APP = 'calendar';
	const TABLE = 'egw_cal';
	const ID = 'cal_id';
	const MODIFIED = 'cal_modified';
	const CREATED = 'cal_created';
	const MODIFIED_TYPE = 'int';
	const TITLE = 'cal_title';
	const DESCRIPTION = 'cal_description';
	protected static $additional_cols = ['cal_location'];
	const NOT_DELETED = 'cal_deleted IS NULL';
	/**
	 * Do NOT order by the indexed cal_modified: calendars with millions of soft-deleted events (eg. a daily synced timetable)
	 * made the "not yet indexed" query walk the whole cal_modified index (6M rows, 14s per query, run back-to-back),
	 * instead of starting with the few non-deleted events (35k rows, 0.4s)
	 */
	const ORDER_BY = 'cal_modified+0';
	const EXTRA_TABLE = 'egw_cal_extra';
	const EXTRA_ID = 'cal_id';
	const EXTRA_NAME = 'cal_extra_name';
	const EXTRA_VALUE = 'cal_extra_value';
}