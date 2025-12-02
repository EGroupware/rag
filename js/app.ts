/**
 * EGroupware RAG system
 *
 * @package rag
 * @link https://www.egroupware.org
 * @author Ralf Becker <rb@egroupware.org>
 * @copyright 2025 by Ralf Becker <rb@egroupware.org>
 * @license https://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

import {app} from "../../api/js/jsapi/egw_global";
import { EgwApp } from '../../api/js/jsapi/egw_app';

class RagApp extends EgwApp
{
	/**
	 * app js initialization stage
	 */
	constructor()
	{
		super('rag');
	}

	/**
	 * et2 object is ready to use
	 *
	 * @param {object} et2 object
	 * @param {string} name template name et2_ready is called for eg. "example.edit"
	 */
	et2_ready(et2,name)
	{
		// call parent
		super.et2_ready.apply(this, arguments);
	}

	/**
	 * View an entry
	 *
	 * @param {object} _action action object, attribute id contains the name of the action
	 * @param {array} _selected array with selected rows, attribute id containers the row-id
	 */
	view(_action, _selected)
	{
		this.egw.open(_selected[0].id.split('::')[1]);
	}

	/**
	 * Search button pressed
	 */
	search(_ev, _widget)
	{
		const header = this.et2.getWidgetById('rag.index.header');

		this.nm.applyFilters({
			search: header?.getWidgetById('search').value,
			col_filter: {
				type: header?.getWidgetById('col_filter[type]').parentNode.querySelector('input[type="radio"]:checked').value,
				apps: header?.getWidgetById('col_filter[apps]').value,
			}
		});

		// store state as implizit preference
		if (_widget.id !== 'search')
		{
			this.egw.set_preference(this.appname,
				_widget.id === 'col_filter[type]' ? 'searchType' : 'searchApps',
				_widget.get_value());	// can't use .value because of old radio-buttons :(
		}
	}
}

app.classes.rag = RagApp;