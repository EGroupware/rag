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

$setup_info['rag']['name']      = 'rag';
$setup_info['rag']['version']   = '0.1.001';
$setup_info['rag']['app_order'] = 5;
$setup_info['rag']['tables']    = ['egw_rag'];
$setup_info['rag']['enable']    = 1;
$setup_info['rag']['index']     = 'rag.EGroupware\\Rag\\Ui.index&ajax=true';

$setup_info['rag']['author'] =
$setup_info['rag']['maintainer'] = array(
	'name'  => 'Ralf Becker',
	'email' => 'rb@egroupware.org',
);
$setup_info['rag']['description'] = 'A RAG system for EGroupware';
$setup_info['rag']['note'] = '';

/* The hooks this app includes, needed for hooks registration */
$setup_info['rag']['hooks'] = array();

/* Dependencies for this app to work */
$setup_info['rag']['depends'][] = array(
	 'appname' => 'api',
	 'versions' => Array('23.1')
);