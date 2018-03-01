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
		lastRawKind: 0,

		_deviceTemplate: null,
		_deviceHTML: '' +
		'<li class="device" data-device-id="{{_id}}">' +
			'<a id="import-data" href="#">' +
				'<img alt="" src="<?php print_unescaped(image_path(\'core\', \'actions/upload.svg\')); ?>">' +
				'<span>{{NAME}}</span> <em>({{IDENTIFIER}})</em>' +
			'</a>' +
		'</li>',

		initialise: function() {
			$('#import-data').on('click', _.bind(this._importButtonOnClick, this));
			this._deviceTemplate = Handlebars.compile(this._deviceHTML);
			this._selectedDatabase($('#app-content').attr('data-database-id'), $('#app-content').attr('data-database-path'));
		},

		_importButtonOnClick: function(e) {
			e.preventDefault();
			OCdialogs.filepicker(
				t('gadgetbridge', 'Choose a file to import'),
				_.bind(this._filePickerCallback, this)
			)
		},

		_selectedDatabase: function(id, path) {
			this.databaseFileId = id;
			this.databaseFilePath = path;
			$('.settings-caption').text(this.databaseFilePath);
			$('#app-content').attr('data-database-id', this.databaseFileId);
			$('#app-content').attr('data-database-path', this.databaseFilePath);

			if (this.databaseFileId > 0) {
				this._loadDevices();
			}
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
					self._selectedDatabase(
						result.ocs.data.fileId,
						path.substring(1) // Remove leading slash
					);
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
					// TODO Remove previous devices


					var singleDeviceDatabase = result.ocs.data.length === 1;

					_.each(result.ocs.data, function(device) {
						var $device = $(self._deviceTemplate(device));
						$device.on('click', function() {
							self.selectedDevice = $(this).attr('data-device-id');
							$(this).addClass('active');
							if (self.selectedDevice !== $(this).attr('data-device-id')) {
								self._loadDevice(moment().format('YYYY/MM/DD/HH/mm'));
							}
						});
						$('#app-navigation ul').append($device);

						if (singleDeviceDatabase) {
							self.selectedDevice = device._id;
							self._loadDevice(moment().format('YYYY/MM/DD/HH/mm'));
							$device.addClass('active');
						}
					});
				},
				error: function() {
					OC.Notification. showTemporary(t('gadgetbridge', 'The selected file is not a readable Gadgetbridge database'));
				}
			});
		},

		_loadDevice: function(date) {
			var self = this;
			$.ajax({
				url: OC.linkToOCS('apps/gadgetbridge/api/v1', 2) + this.databaseFileId + '/devices/' + self.selectedDevice + '/samples/' + date,
				beforeSend: function (request) {
					request.setRequestHeader('Accept', 'application/json');
				},
				success: function(result) {
					var labelData = [],
						kindData = [],
						stepData = [],
						activityColor = [],
						heartRate = [],
						kind = 0,
						lastHeartRate = null;
					_.each(result.ocs.data, function(tick) {
						labelData.push(moment(tick.TIMESTAMP * 1000).calendar());
						kind = parseInt(tick.RAW_KIND, 10);
						kindData.push(kind * 10);
						activityColor.push(self._getActivityColor(kind));
						stepData.push(self._getSteps(kind, tick.STEPS));

						if (tick.HEART_RATE > 0 && tick.HEART_RATE < 255) {
							lastHeartRate = tick.HEART_RATE;
							heartRate.push(tick.HEART_RATE);
						} else if (tick.HEART_RATE > 0) {
							heartRate.push(lastHeartRate);
							lastHeartRate = null;
						} else {
							heartRate.push(null);
						}
					});

					var ctx = $('#steps');
					var myChart = new Chart(ctx, {
						type: 'bar',
						data: {
							labels: labelData,
							datasets: [
								{
									label: 'Activity',
									data: stepData,
									backgroundColor: activityColor,
									barThickness: 100
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
							legend: {
								display: false
							},
							scales: {
								xAxes: [{
									gridLines: {
										offsetGridLines: true
									},
									stacked: true
								}],
								yAxes: [{
									stacked: true
								}]
							}
						}
					});
				},
				error: function() {
					OC.Notification. showTemporary(t('gadgetbridge', 'Device data could not be loaded from the database'));
				}
			});
		},

		_getActivityColor: function(current) {
			switch (current) {
				case 1: // Activity
					return '#3ADF00';
				case 2: // Light sleep
					return '#2ECCFA';
				case 4: // Deep sleep
					return '#0040FF';
				case 8: // Not worn
					return '#AAAAAA';
				default:
					return '#AAAAAA';
			}
		},

		_getSteps: function(current, steps) {
			switch (current) {
				case 1: // Activity
				case 2: // Light sleep
				case 4: // Deep sleep
				case 8: // Not worn
					return Math.min(250, Math.max(10, steps));
				default:
					return 2;
			}
		}
	};
})(OC, OCA, _);

$(document).ready(function () {
	OCA.GadgetBridge.initialise();
});
