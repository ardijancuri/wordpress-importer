(function () {
	'use strict';

	const api = window.WSMSiteMigrator;
	const storageKeys = {
		exportJobId: 'wsmExportJobId',
		importJobId: 'wsmImportJobId',
	};
	const state = {
		exportJobId: '',
		importJobId: '',
		importReady: false,
		currentProgress: 0,
		isDriving: false,
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
		resumeStoredJobs();
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
				return response
					.json()
					.then(function (body) {
						const error = new Error(body.message || response.statusText);
						error.data = body.data || {};
						throw error;
					})
					.catch(function (error) {
						if (error instanceof SyntaxError) {
							throw new Error(response.statusText);
						}
						throw error;
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
		clearDownloads();
		renderSummary('Starting export job...');
		setProgress(1, 'Starting export');

		request('/export/start', {
			method: 'POST',
			body: {},
		})
			.then(function (job) {
				state.exportJobId = job.id;
				saveStoredJob(storageKeys.exportJobId, job.id);
				renderJob(job);
				return driveJob(job.id, ['completed']);
			})
			.then(function (job) {
				renderJob(job);
				renderDownloads(job);
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
		const files = Array.prototype.slice.call((fileInput.files || []));
		if (!files.length) {
			renderError('Choose the manifest and package part files first.');
			return;
		}

		setBusy(els['wsm-upload-import'], true);
		renderSummary('Preparing upload...');
		setProgress(1, 'Preparing upload');
		state.importJobId = '';
		state.importReady = false;
		updateImportButton();

		request('/import/upload/start', {
			method: 'POST',
			body: {
				files: files.map(function (file) {
					return {
						name: file.name,
						size: file.size,
					};
				}),
			},
		})
			.then(function (data) {
				state.importJobId = data.job_id;
				saveStoredJob(storageKeys.importJobId, data.job_id);
				if (data.job) {
					renderJob(data.job);
				}
				return uploadFiles(files, data.job_id, data.chunk_size || 1048576);
			})
			.then(function () {
				renderSummary('Validating package...');
				setProgress(60, 'Validating package', true);
				return request('/import/validate', {
					method: 'POST',
					body: {
						job_id: state.importJobId,
					},
				});
			})
			.then(function (job) {
				renderJob(job);
				if (job.status === 'validated') {
					return job;
				}
				return driveJob(job.id, ['validated']);
			})
			.then(function (job) {
				renderJob(job);
				state.importReady = job.status === 'validated';
				updateImportButton();
			})
			.catch(function (error) {
				renderError(error.message);
			})
			.finally(function () {
				setBusy(els['wsm-upload-import'], false);
			});
	}

	function uploadFiles(files, jobId, chunkSize) {
		let chain = Promise.resolve();

		files.forEach(function (file, fileIndex) {
			chain = chain.then(function () {
				return uploadOneFile(file, fileIndex, jobId, chunkSize);
			});
		});

		return chain;
	}

	function uploadOneFile(file, fileIndex, jobId, chunkSize) {
		let offset = 0;

		function next() {
			if (offset >= file.size) {
				return Promise.resolve();
			}

			const end = Math.min(offset + chunkSize, file.size);
			const form = new FormData();
			form.append('job_id', jobId);
			form.append('file_index', String(fileIndex));
			form.append('file_name', file.name);
			form.append('offset', String(offset));
			form.append('total_size', String(file.size));
			form.append('chunk', file.slice(offset, end), file.name + '.chunk');

			renderSummary('Uploading ' + file.name + ' (' + formatBytes(offset) + ' of ' + formatBytes(file.size) + ')...');

			return request('/import/upload/chunk', {
				method: 'POST',
				body: form,
			})
				.then(function (job) {
					renderJob(job);
					offset = end;
					return next();
				})
				.catch(function (error) {
					if (error.data && typeof error.data.expected_offset !== 'undefined') {
						offset = Number(error.data.expected_offset) || 0;
						return next();
					}
					throw error;
				});
		}

		return next();
	}

	function updateImportButton() {
		els['wsm-start-import'].disabled = !state.importJobId || !state.importReady || els['wsm-confirmation'].value.trim().toUpperCase() !== 'REPLACE SITE';
	}

	function startImport() {
		if (!state.importJobId) {
			renderError('Upload and validate a package first.');
			return;
		}

		setBusy(els['wsm-start-import'], true);
		renderSummary('Starting import job...');
		setProgress(70, 'Starting import', true);

		request('/import/start', {
			method: 'POST',
			body: {
				job_id: state.importJobId,
				confirmation: els['wsm-confirmation'].value,
			},
		})
			.then(function (job) {
				renderJob(job);
				return driveJob(job.id, ['completed']);
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

	function resumeStoredJobs() {
		const exportJobId = window.localStorage.getItem(storageKeys.exportJobId);
		if (exportJobId) {
			request('/job/' + encodeURIComponent(exportJobId))
				.then(function (job) {
					state.exportJobId = job.id;
					renderJob(job);
					if (job.status === 'completed') {
						renderDownloads(job);
					} else if (job.status === 'running') {
						setBusy(els['wsm-start-export'], true);
						driveJob(job.id, ['completed'])
							.then(function (completedJob) {
								renderJob(completedJob);
								renderDownloads(completedJob);
							})
							.finally(function () {
								setBusy(els['wsm-start-export'], false);
							});
					}
				})
				.catch(function () {});
		}

		const importJobId = window.localStorage.getItem(storageKeys.importJobId);
		if (importJobId) {
			request('/job/' + encodeURIComponent(importJobId))
				.then(function (job) {
					state.importJobId = job.id;
					state.importReady = job.status === 'validated';
					renderJob(job);
					updateImportButton();
					if (job.status === 'validating') {
						driveJob(job.id, ['validated']).then(function (validatedJob) {
							state.importReady = validatedJob.status === 'validated';
							renderJob(validatedJob);
							updateImportButton();
						});
					} else if (job.status === 'importing') {
						setBusy(els['wsm-start-import'], true);
						driveJob(job.id, ['completed'])
							.then(renderJob)
							.finally(function () {
								setBusy(els['wsm-start-import'], false);
								updateImportButton();
							});
					}
				})
				.catch(function () {});
		}
	}

	function saveStoredJob(key, jobId) {
		try {
			window.localStorage.setItem(key, jobId);
		} catch (error) {}
	}

	function driveJob(jobId, completeStatuses) {
		state.isDriving = true;

		function tick() {
			return request('/job/' + encodeURIComponent(jobId) + '/run', {
				method: 'POST',
				body: {},
			}).then(function (job) {
				renderJob(job);
				if (job.status === 'failed') {
					throw new Error(job.errors && job.errors.length ? job.errors[0].message : 'Migration job failed.');
				}
				if (completeStatuses.indexOf(job.status) !== -1) {
					state.isDriving = false;
					return job;
				}
				return delay(job.locked ? 1200 : 350).then(tick);
			});
		}

		return tick();
	}

	function renderJob(job) {
		const summary = [
			['Job', job.id],
			['Status', job.status || 'unknown'],
		];
		if (job.phase) {
			summary.push(['Phase', job.phase]);
		}
		if (typeof job.progress !== 'undefined') {
			summary.push(['Progress', Math.round(Number(job.progress) || 0) + '%']);
		}
		if (job.package_size) {
			summary.push(['Package', formatBytes(job.package_size)]);
		}
		if (job.expected_size) {
			summary.push(['Expected upload', formatBytes(job.expected_size)]);
		}
		if (job.uploaded_bytes) {
			summary.push(['Uploaded', formatBytes(job.uploaded_bytes)]);
		}
		if (job.total_bytes) {
			summary.push(['Estimated content', formatBytes(job.total_bytes)]);
		}
		if (job.downloads && job.downloads.length) {
			summary.push(['Package files', String(job.downloads.length)]);
		}
		if (job.package_parts && job.package_parts.length) {
			summary.push(['Parts', String(job.package_parts.length)]);
		}
		if (job.manifest_summary) {
			summary.push(['Source', job.manifest_summary.source_url || 'unknown']);
			summary.push([
				'Package contents',
				(job.manifest_summary.table_count || 0) +
					' tables, ' +
					(job.manifest_summary.file_count || 0) +
					' files, ' +
					(job.manifest_summary.part_count || 0) +
					' parts',
			]);
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

	function renderDownloads(job) {
		clearDownloads();
		const downloads = job.downloads || [];
		if (!downloads.length) {
			return;
		}

		const title = document.createElement('span');
		title.className = 'wsm-download-title';
		title.textContent = 'Download all package files:';
		els['wsm-download-export'].appendChild(title);

		downloads.forEach(function (download) {
			const link = document.createElement('a');
			link.className = 'button';
			link.href =
				api.downloadUrl +
				'?action=wsm_download_export&job_id=' +
				encodeURIComponent(job.id) +
				'&part=' +
				encodeURIComponent(download.name) +
				'&_wpnonce=' +
				encodeURIComponent(api.downloadNonce);
			link.download = download.name;
			link.textContent = download.name + ' (' + formatBytes(download.size) + ')';
			els['wsm-download-export'].appendChild(link);
		});

		els['wsm-download-export'].classList.remove('wsm-hidden');
	}

	function clearDownloads() {
		els['wsm-download-export'].innerHTML = '';
		els['wsm-download-export'].classList.add('wsm-hidden');
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

		if (typeof job.progress !== 'undefined') {
			setProgress(Number(job.progress) || 0, labelForJob(job), isActiveJob(job));
			return;
		}

		if (job.status === 'uploading') {
			const expected = Number(job.expected_size || 0);
			const uploaded = Number(job.uploaded_bytes || 0);
			const ratio = expected > 0 ? Math.min(uploaded / expected, 1) : 0;
			setProgress(1 + ratio * 59, 'Uploading package');
			return;
		}

		if (job.status === 'uploaded') {
			setProgress(60, 'Upload complete');
			return;
		}

		if (job.status === 'validated') {
			setProgress(70, 'Package validated');
		}
	}

	function labelForJob(job) {
		if (job.status === 'uploading') {
			return 'Uploading package';
		}
		if (job.status === 'validating') {
			return 'Validating package';
		}
		if (job.status === 'uploaded') {
			return 'Upload complete';
		}
		if (job.status === 'importing') {
			return job.phase ? 'Importing ' + job.phase : 'Importing site';
		}
		if (job.status === 'running') {
			return job.phase ? 'Exporting ' + job.phase : 'Exporting site';
		}
		if (job.status === 'validated') {
			return 'Package validated';
		}
		return job.phase || job.status || 'Ready';
	}

	function isActiveJob(job) {
		return ['running', 'validating', 'importing'].indexOf(job.status) !== -1;
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

	function delay(ms) {
		return new Promise(function (resolve) {
			window.setTimeout(resolve, ms);
		});
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
