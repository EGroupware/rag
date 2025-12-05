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
class Timesheet extends Base
{
	const APP = 'timesheet';
	const TABLE = 'egw_timesheet';
	const ID = 'ts_id';
	const MODIFIED = 'ts_modified';
	const TITLE = 'ts_title';
	const DESCRIPTION = 'ts_description';
	protected static $additional_cols = [];
	const NOT_DELETED = 'ts_status<>-1';
	const EXTRA_TABLE = 'egw_timesheet_extra';
	const EXTRA_ID = 'ts_id';
	const EXTRA_NAME = 'ts_extra_name';
	const EXTRA_VALUE = 'ts_extra_value';
}