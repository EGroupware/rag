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

// give only Admins group rights for RAG app
$defaultgroup = $GLOBALS['egw_setup']->add_account('Admins', 'Admins', 'Group', false, false);
$GLOBALS['egw_setup']->add_acl('rag', 'run', $defaultgroup);