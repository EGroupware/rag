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

namespace EGroupware\Rag;

use EGroupware\Api;
use EGroupware\Api\Link;
use EGroupware\Api\Framework;
use EGroupware\Api\Egw;
use EGroupware\Api\Acl;
use EGroupware\Timesheet\Events;


/**
 * diverse hooks as static methods
 *
 */
class Hooks
{
	const APP = 'rag';

	/**
	 * Hooks to build RAGs sidebox-menu plus the admin and Api\Preferences sections
	 *
	 * @param string|array $args hook args
	 */
	static function allHooks($args)
	{
		$appname = self::APP;
		$location = is_array($args) ? $args['location'] : $args;

		if ($location == 'sidebox_menu')
		{

		}

		if ($GLOBALS['egw_info']['user']['apps']['admin'])
		{
			$file = Array(
				'Site Configuration' => Egw::link('/index.php','menuaction=admin.admin_config.index&appname=' . $appname,'&ajax=true'),
			);
			if ($location == 'admin')
			{
				display_section($appname,$file);
			}
			else
			{
				//$GLOBALS['egw']->framework->sidebox($appname, lang('Configuration'), $file);
			}
		}
	}
}