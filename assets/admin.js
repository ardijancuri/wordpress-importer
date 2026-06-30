(function () {
	'use strict';

	const api = window.WSMSiteMigrator;
	const state = {
		exportJobId: '',
		importJobId: '',
	};

	const els = {};

	document.addEventListener('DOMContentLoaded', function () {
		[
			'wsm-start-export',
			'wsm-download-export',
			'wsm-import-file',
			'wsm-upload-import',
			'wsm-target-url',
			'wsm-confirmation',
			'wsm-start-import',
			'wsm-preflight',
			'wsm-job-summary',
			'wsm-log',
		].forEach(function (id) {
			els[id] = document.getElementById(id);
		});

		els['wsm-start-export'].addEventListener('click', startExport);
		els['wsm-upload-import'].addEventListener('click', uploadAndValidateImport);
		els['wsm-start-import'].addEventListener('click', startImport);
		els['wsm-confirmation'].addEventListener('input', updateImportButton);

		loadPreflight();
	});

	function request(path, options) {
		options = options || {};
		options.headers = Object.assign(
			{
				'X-WP-Nonce': api.nonce,
			},
			options.headers || {}
		);

		if (options.body && !(options.body instanceof FormData)) {
			options.headers['Content-Type'] = 'application/json';
			options.body = JSON.stringify(options.body);
		}

		return fetch(api.restUrl + path, options).then(function (response) {
			if (!response.ok) {
				return response.json().then(function (body) {
					throw new Error(body.message || response.statusText);
				});
			}

			const contentType = response.headers.get('content-type') || '';
			if (contentType.indexOf('application/json') !== -1) {
				return response.json();
			}

			return response;
		});
	}

	function loadPreflight() {
		request('/preflight')
			.then(renderPreflight)
			.catch(function (error) {
				renderError(error.message);
			});
	}

	function renderPreflight(data) {
		els['wsm-preflight'].innerHTML = '';
		(data.checks || []).forEach(function (check) {
			const item = document.createElement('div');
			item.className = 'wsm-check ' + (check.ok ? 'wsm-check-ok' : check.fatal ? 'wsm-check-fail' : 'wsm-check-warn');
			item.innerHTML = '<strong></strong><span></span>';
			item.querySelector('strong').textContent = check.label;
			item.querySelector('span').textContent = check.message || '';
			els['wsm-preflight'].appendChild(item);
		});
	}

	function startExport() {
		setBusy(els['wsm-start-export'], true);
		els['wsm-download-export'].classList.add('wsm-hidden');
		renderSummary('Creating export package...');

		request('/export/start', {
			method: 'POST',
			body: {},
		})
			.then(function (job) {
				state.exportJobId = job.id;
				renderJob(job);
				els['wsm-download-export'].href =
					api.downloadUrl +
					'?action=wsm_download_export&job_id=' +
					encodeURIComponent(job.id) +
					'&_wpnonce=' +
					encodeURIComponent(api.downloadNonce);
				els['wsm-download-export'].classList.remove('wsm-hidden');
			})
			.catch(function (error) {
				renderError(error.message);
			})
			.finally(function () {
				setBusy(els['wsm-start-export'], false);
			});
	}

	function uploadAndValidateImport() {
		const fileInput = els['wsm-import-file'];
		const file = fileInput.files && fileInput.files[0];
		if (!file) {
			renderError('Choose a migration package first.');
			return;
		}

		setBusy(els['wsm-upload-import'], true);
		renderSummary('Preparing upload...');
		state.importJobId = '';

		request('/import/upload/start', {
			method: 'POST',
			body: {
				file_name: file.name,
				file_size: file.size,
			},
		})
			.then(function (data) {
				state.importJobId = data.job_id;
				return uploadChunks(file, data.job_id, data.chunk_size || 1048576);
			})
			.then(function () {
				renderSummary('Validating package checksums...');
				return request('/import/validate', {
					method: 'POST',
					body: {
						job_id: state.importJobId,
					},
				});
			})
			.then(function (job) {
				renderJob(job);
				updateImportButton();
			})
			.catch(function (error) {
				renderError(error.message);
			})
			.finally(function () {
				setBusy(els['wsm-upload-import'], false);
			});
	}

	function uploadChunks(file, jobId, chunkSize) {
		const total = Math.ceil(file.size / chunkSize);
		let chain = Promise.resolve();

		for (let index = 0; index < total; index++) {
			chain = chain.then(function () {
				const start = index * chunkSize;
				const end = Math.min(start + chunkSize, file.size);
				renderSummary('Uploading package chunk ' + (index + 1) + ' of ' + total + '...');
				return readAsBase64(file.slice(start, end)).then(function (chunk) {
					return request('/import/upload/chunk', {
						method: 'POST',
						body: {
							job_id: jobId,
							index: index,
							total: total,
							chunk: chunk,
						},
					}).then(renderJob);
				});
			});
		}

		return chain;
	}

	function readAsBase64(blob) {
		return new Promise(function (resolve, reject) {
			const reader = new FileReader();
			reader.onload = function () {
				const result = String(reader.result || '');
				resolve(result.split(',').pop());
			};
			reader.onerror = function () {
				reject(new Error('Could not read upload chunk.'));
			};
			reader.readAsDataURL(blob);
		});
	}

	function updateImportButton() {
		els['wsm-start-import'].disabled = !state.importJobId || els['wsm-confirmation'].value.trim().toUpperCase() !== 'REPLACE SITE';
	}

	function startImport() {
		if (!state.importJobId) {
			renderError('Upload and validate a package first.');
			return;
		}

		setBusy(els['wsm-start-import'], true);
		renderSummary('Replacing destination site...');

		request('/import/start', {
			method: 'POST',
			body: {
				job_id: state.importJobId,
				confirmation: els['wsm-confirmation'].value,
				target_url: els['wsm-target-url'].value || api.homeUrl,
			},
		})
			.then(renderJob)
			.catch(function (error) {
				renderError(error.message);
			})
			.finally(function () {
				setBusy(els['wsm-start-import'], false);
				updateImportButton();
			});
	}

	function renderJob(job) {
		const summary = [];
		summary.push('Job: ' + job.id);
		summary.push('Status: ' + (job.status || 'unknown'));
		if (job.phase) {
			summary.push('Phase: ' + job.phase);
		}
		if (job.package_size) {
			summary.push('Package: ' + formatBytes(job.package_size));
		}
		if (job.uploaded_bytes) {
			summary.push('Uploaded: ' + formatBytes(job.uploaded_bytes));
		}
		if (job.manifest_summary) {
			summary.push('Source: ' + (job.manifest_summary.source_url || 'unknown'));
			summary.push('Tables: ' + job.manifest_summary.table_count + ', files: ' + job.manifest_summary.file_count);
		}
		if (job.errors && job.errors.length) {
			summary.push('Error: ' + job.errors[0].message);
		}

		els['wsm-job-summary'].textContent = summary.join('\n');
		els['wsm-log'].textContent = (job.log || []).join('\n');
	}

	function renderSummary(message) {
		els['wsm-job-summary'].textContent = message;
	}

	function renderError(message) {
		els['wsm-job-summary'].textContent = 'Error: ' + message;
	}

	function setBusy(button, busy) {
		button.disabled = busy;
		button.classList.toggle('updating-message', busy);
	}

	function formatBytes(bytes) {
		const units = ['B', 'KB', 'MB', 'GB', 'TB'];
		let value = Number(bytes) || 0;
		let unit = 0;
		while (value >= 1024 && unit < units.length - 1) {
			value = value / 1024;
			unit++;
		}
		return value.toFixed(unit ? 1 : 0) + ' ' + units[unit];
	}
})();
