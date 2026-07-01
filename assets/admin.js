(function () {
	'use strict';

	const api = window.WSMSiteMigrator;
	const state = {
		exportJobId: '',
		importJobId: '',
		currentProgress: 0,
	};

	const els = {};

	document.addEventListener('DOMContentLoaded', function () {
		[
			'wsm-start-export',
			'wsm-download-export',
			'wsm-import-file',
			'wsm-upload-import',
			'wsm-confirmation',
			'wsm-start-import',
			'wsm-preflight',
			'wsm-progress-label',
			'wsm-progress-value',
			'wsm-progress-track',
			'wsm-progress-fill',
			'wsm-job-summary',
			'wsm-log',
		].forEach(function (id) {
			els[id] = document.getElementById(id);
		});

		els['wsm-start-export'].addEventListener('click', startExport);
		els['wsm-upload-import'].addEventListener('click', uploadAndValidateImport);
		els['wsm-start-import'].addEventListener('click', startImport);
		els['wsm-confirmation'].addEventListener('input', updateImportButton);

		setProgress(0, 'Ready');
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
		setProgress(12, 'Exporting package', true);

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
		setProgress(2, 'Preparing upload');
		state.importJobId = '';
		updateImportButton();

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
				setProgress(82, 'Validating package', true);
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
		const total = Math.max(1, Math.ceil(file.size / chunkSize));
		let chain = Promise.resolve();

		for (let index = 0; index < total; index++) {
			chain = chain.then(function () {
				const start = index * chunkSize;
				const end = Math.min(start + chunkSize, file.size);
				renderSummary('Uploading package chunk ' + (index + 1) + ' of ' + total + '...');
				setProgress(8 + (index / total) * 68, 'Uploading package');
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
		setProgress(88, 'Replacing site', true);

		request('/import/start', {
			method: 'POST',
			body: {
				job_id: state.importJobId,
				confirmation: els['wsm-confirmation'].value,
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
		const summary = [
			['Job', job.id],
			['Status', job.status || 'unknown'],
		];
		if (job.phase) {
			summary.push(['Phase', job.phase]);
		}
		if (job.package_size) {
			summary.push(['Package', formatBytes(job.package_size)]);
		}
		if (job.uploaded_bytes) {
			summary.push(['Uploaded', formatBytes(job.uploaded_bytes)]);
		}
		if (job.manifest_summary) {
			summary.push(['Source', job.manifest_summary.source_url || 'unknown']);
			summary.push(['Package contents', job.manifest_summary.table_count + ' tables, ' + job.manifest_summary.file_count + ' files']);
		}
		if (job.errors && job.errors.length) {
			summary.push(['Error', job.errors[0].message]);
		}

		els['wsm-job-summary'].classList.remove('is-plain');
		els['wsm-job-summary'].innerHTML = summary
			.map(function (item) {
				return '<div class="wsm-job-detail"><span>' + escapeHtml(item[0]) + '</span><strong>' + escapeHtml(item[1]) + '</strong></div>';
			})
			.join('');
		els['wsm-log'].textContent = (job.log || []).join('\n');
		updateProgressFromJob(job);
	}

	function renderSummary(message) {
		els['wsm-job-summary'].classList.add('is-plain');
		els['wsm-job-summary'].textContent = message;
	}

	function renderError(message) {
		els['wsm-job-summary'].classList.add('is-plain');
		els['wsm-job-summary'].textContent = 'Error: ' + message;
		setProgress(state.currentProgress || 100, 'Failed', false, true);
	}

	function setBusy(button, busy) {
		button.disabled = busy;
		button.classList.toggle('updating-message', busy);
	}

	function updateProgressFromJob(job) {
		if (!job || !job.status) {
			return;
		}

		if (job.status === 'failed') {
			setProgress(state.currentProgress || 100, 'Failed', false, true);
			return;
		}

		if (job.status === 'completed') {
			setProgress(100, 'Completed');
			return;
		}

		if (job.status === 'running') {
			setProgress(job.phase === 'files' ? 72 : 35, job.phase === 'files' ? 'Adding files' : 'Exporting database', true);
			return;
		}

		if (job.status === 'uploading') {
			const expected = Number(job.expected_size || 0);
			const uploaded = Number(job.uploaded_bytes || 0);
			const ratio = expected > 0 ? Math.min(uploaded / expected, 1) : 0;
			setProgress(8 + ratio * 68, 'Uploading package');
			return;
		}

		if (job.status === 'uploaded') {
			setProgress(78, 'Upload complete');
			return;
		}

		if (job.status === 'validated') {
			setProgress(85, 'Package validated');
			return;
		}

		if (job.status === 'importing') {
			const phaseProgress = {
				validating: 86,
				extracting: 90,
				database: 94,
				files: 98,
			};
			setProgress(phaseProgress[job.phase] || 88, job.phase ? 'Importing ' + job.phase : 'Importing site', true);
		}
	}

	function setProgress(percent, label, indeterminate, failed) {
		const normalized = Math.max(0, Math.min(100, Math.round(Number(percent) || 0)));
		state.currentProgress = normalized;
		els['wsm-progress-label'].textContent = label || 'Ready';
		els['wsm-progress-value'].textContent = normalized + '%';
		els['wsm-progress-fill'].style.transform = 'scaleX(' + normalized / 100 + ')';
		els['wsm-progress-track'].setAttribute('aria-valuenow', String(normalized));
		els['wsm-progress-track'].classList.toggle('is-indeterminate', Boolean(indeterminate));
		els['wsm-progress-track'].classList.toggle('is-failed', Boolean(failed));
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

	function escapeHtml(value) {
		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}
})();
