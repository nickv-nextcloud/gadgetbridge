/**
 * @copyright (c) 2017 Joas Schilling <coding@schilljs.com>
 *
 * @author Joas Schilling <coding@schilljs.com>
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 */

(function(OC, OCA, _) {
	OCA = OCA || {};

	OCA.GadgetBridge = {
		databaseFileId: 0,
		selectedDevice: 0,

		_deviceTemplate: null,
		_deviceHTML: '' +
		'<li data-device-id="{{_id}}">' +
			'<a id="import-data" href="#">' +
				'<img alt="" src="<?php print_unescaped(image_path(\'core\', \'actions/upload.svg\')); ?>">' +
				'<span>{{NAME}}</span> <em>({{IDENTIFIER}})</em>' +
			'</a>' +
		'</li>',

		initialise: function() {
			$('#import-data').on('click', _.bind(this._importButtonOnClick, this));
			this.databaseFileId = $('#app-content').attr('data-database');
			this._deviceTemplate = Handlebars.compile(this._deviceHTML);
			console.log(this.databaseFileId);
			if (this.databaseFileId > 0) {
				this._loadDevices();
			}
		},

		_importButtonOnClick: function(e) {
			e.preventDefault();
			OCdialogs.filepicker(
				t('gadgetbridge', 'Choose a file to import'),
				_.bind(this._filePickerCallback, this)
			)
		},

		_filePickerCallback: function(path) {
			var self = this;

			$.ajax({
				url: OC.linkToOCS('apps/gadgetbridge/api/v1', 2) + 'database',
				type: 'POST',
				beforeSend: function (request) {
					request.setRequestHeader('Accept', 'application/json');
				},
				data: {
					path: path
				},
				success: function(result) {
					// TODO set title with file name
					self.databaseFileId = result.ocs.data.fileId;
					$('#app-content').attr('data-database', self.databaseFileId);
					self._loadDevices();
				},
				error: function() {
					OC.Notification. showTemporary(t('gadgetbridge', 'The selected file is not a readable Gadgetbridge database'));
				}
			});
		},

		_loadDevices: function() {
			var self = this;
			$.ajax({
				url: OC.linkToOCS('apps/gadgetbridge/api/v1', 2) + this.databaseFileId + '/devices',
				beforeSend: function (request) {
					request.setRequestHeader('Accept', 'application/json');
				},
				success: function(result) {
					var singleDeviceDatabase = result.ocs.data.length === 1;

					_.each(result.ocs.data, function(device) {
						var $device = $(self._deviceTemplate(device));
						$device.on('click', function() {
							self.selectedDevice = $(this).attr('data-device-id');
							if (self.selectedDevice !== $(this).attr('data-device-id')) {
								self._loadDevice();
							}
						});
						$('#app-navigation ul').append($device);

						if (singleDeviceDatabase) {
							self.selectedDevice = device._id;
							self._loadDevice();
						}
					});
				},
				error: function() {
					OC.Notification. showTemporary(t('gadgetbridge', 'The selected file is not a readable Gadgetbridge database'));
				}
			});
		},

		_loadDevice: function() {
			var self = this;
			$.ajax({
				url: OC.linkToOCS('apps/gadgetbridge/api/v1', 2) + this.databaseFileId + '/devices/' + self.selectedDevice,
				beforeSend: function (request) {
					request.setRequestHeader('Accept', 'application/json');
				},
				success: function(result) {
					console.log(result.ocs.data.length);

					var stepData = [],
						heartRate = [],
						labelData = [],
						i = 0,
						lastHeartRate = null;
					_.each(result.ocs.data, function(tick) {
						console.log(tick.TIMESTAMP);

						if (tick.STEPS > 0) {
							stepData.unshift(Math.min(tick.STEPS, 250));
						} else {
							stepData.unshift(0);
						}

						if (tick.HEART_RATE > 0 && tick.HEART_RATE < 255) {
							lastHeartRate = tick.HEART_RATE;
							heartRate.unshift(tick.HEART_RATE);
						} else if (tick.HEART_RATE > 0) {
							heartRate.unshift(lastHeartRate);
							lastHeartRate = null;
						} else {
							heartRate.unshift(null);
						}
						labelData.unshift(moment(tick.TIMESTAMP * 1000).calendar());
					});

					var ctx = $('#steps');
					var myChart = new Chart(ctx, {
						type: 'bar',
						data: {
							labels: labelData,
							datasets: [
								{
									label: 'Steps',
									data: stepData,
									backgroundColor: '#00CC00'
								},
								{
									label: 'Heart rate',
									data: heartRate,
									backgroundColor: '#ffa500',
									borderColor: '#ffa500',
									type: 'line',
									pointStyle: 'rect',
									pointRadius: 0,
									fill: false
								}
							]
						},
						options: {
							scales: {
								xAxes: [{
									gridLines: {
										offsetGridLines: false
									}
								}]
							}
						}
					});
				},
				error: function() {
					OC.Notification. showTemporary(t('gadgetbridge', 'Device data could not be loaded from the database'));
				}
			});
		}
	};
})(OC, OCA, _);

$(document).ready(function () {
	OCA.GadgetBridge.initialise();
});
