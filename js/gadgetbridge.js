/**
 * @copyright (c) 2017 Joas Schilling <coding@schilljs.com>
 *
 * @author Joas Schilling <coding@schilljs.com>
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 */

(function(OC, OCA, _) {

	if (!OCA.GadgetBridge) {
		OCA.GadgetBridge = {};
	}

	OCA.GadgetBridge = {
		initialise: function() {
			$('#import-data').on('click', _.bind(this._importButtonOnClick, this));
		},

		_importButtonOnClick: function(e) {
			e.preventDefault();
			OCdialogs.filepicker(
				t('gadgetbridge', 'Choose a file to import'),
				this._filePickerCallback
			)
		},

		_filePickerCallback: function(path) {
			$.ajax({
				url: OC.generateUrl('apps/gadgetbridge/import'),
				type: 'POST',
				data: {
					path: path
				},
				success: function() {
					console.log('good');
				},
				failure: function() {
					console.log('bad');
					console.log(arguments);
				}
			});
		}
	};
})(OC, OCA, _);

$(document).ready(function () {
	OCA.GadgetBridge.initialise();
});
