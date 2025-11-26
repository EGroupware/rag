<?php
/**
 * EGroupware - Setup
 * https://www.egroupware.org
 * Created by eTemplates DB-Tools written by ralfbecker@outdoor-training.de
 *
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package rag
 * @subpackage setup
 */


function rag_upgrade0_1_001()
{
	$GLOBALS['egw_setup']->oProc->CreateTable('egw_rag_fulltext',array(
		'fd' => array(
			'ft_id' => array('type' => 'auto','nullable' => False),
			'ft_app' => array('type' => 'ascii','precision' => '16','nullable' => False),
			'ft_app_id' => array('type' => 'int','precision' => '4','nullable' => False),
			'ft_updated' => array('type' => 'timestamp','nullable' => False,'default' => 'current_timestamp'),
			'ft_title' => array('type' => 'varchar','precision' => '255'),
			'ft_description' => array('type' => 'longtext'),
			'ft_extra' => array('type' => 'longtext','meta' => 'json','comment' => 'all other textfields of the app as one JSON array')
		),
		'pk' => array('ft_id'),
		'fk' => array(),
		'ix' => array('ft_app_id',array('ft_title','ft_description','ft_extra', 'options' => array('mysql' => 'FULLTEXT'))),
		'uc' => array(array('ft_app','ft_app_id'))
	));

	return $GLOBALS['setup_info']['rag']['currentver'] = '0.1.002';
}