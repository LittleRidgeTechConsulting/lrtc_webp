(function ($) {
	'use strict';

	var cfg = window.lrtcWebpLibrary || {};
	var $start;
	var $progress;
	var $fill;
	var $status;
	var $log;

	function setStatus(text) {
		$status.text(text);
	}

	function setProgress(processed, total) {
		var pct = 0;
		if (total > 0) {
			pct = Math.min(100, Math.round((processed / total) * 100));
		}
		$fill.css('width', pct + '%');
		$fill.attr('aria-valuenow', pct);
	}

	function renderJob(job) {
		setProgress(job.processed || 0, job.total || 0);
		var parts = [];
		parts.push((job.processed || 0) + ' / ' + (job.total || 0));
		parts.push('converted ' + (job.converted || 0));
		parts.push('skipped ' + (job.skipped || 0));
		if (job.errors) {
			parts.push('errors ' + job.errors);
		}
		if (job.rewritten) {
			parts.push('rewrote ' + job.rewritten);
		}
		setStatus(parts.join(' · '));

		$log.empty();
		$.each(job.log || [], function (i, line) {
			$('<li>').text(line).appendTo($log);
		});

		if (job.done && job.leftovers && job.leftovers.length) {
			$('<li>')
				.text('Leftover URLs still found:')
				.appendTo($log);
			$.each(job.leftovers, function (i, item) {
				$('<li>')
					.text(
						item.needle +
							' (posts ' +
							item.posts +
							', postmeta ' +
							item.postmeta +
							', options ' +
							item.options +
							')'
					)
					.appendTo($log);
			});
		}
	}

	function fail(message) {
		$start.prop('disabled', false);
		setStatus(message || cfg.i18n.failed);
	}

	function post(action) {
		return $.ajax({
			url: cfg.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: action,
				nonce: cfg.nonce
			}
		});
	}

	function step() {
		post('lrtc_webp_library_step')
			.done(function (res) {
				if (!res || !res.success || !res.data) {
					fail(cfg.i18n.failed);
					return;
				}
				renderJob(res.data);
				if (res.data.done) {
					$start.prop('disabled', false);
					if ((res.data.total || 0) === 0) {
						setStatus(cfg.i18n.none);
					} else {
						setStatus(cfg.i18n.done + ' ' + $status.text());
					}
					return;
				}
				step();
			})
			.fail(function (xhr) {
				var msg = cfg.i18n.failed;
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				fail(msg);
			});
	}

	function start() {
		if (!cfg.canWrite) {
			fail(cfg.i18n.noEngine);
			return;
		}

		$start.prop('disabled', true);
		$progress.removeAttr('hidden');
		$log.empty();
		setProgress(0, 1);
		setStatus(cfg.i18n.starting);

		post('lrtc_webp_library_start')
			.done(function (res) {
				if (!res || !res.success || !res.data) {
					fail(cfg.i18n.failed);
					return;
				}
				renderJob(res.data.job || res.data);
				if ((res.data.total || 0) === 0) {
					$start.prop('disabled', false);
					setStatus(cfg.i18n.none);
					return;
				}
				setStatus(cfg.i18n.running);
				step();
			})
			.fail(function (xhr) {
				var msg = cfg.i18n.failed;
				if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
					msg = xhr.responseJSON.data.message;
				}
				fail(msg);
			});
	}

	$(function () {
		$start = $('#lrtc-webp-library-start');
		$progress = $('#lrtc-webp-progress');
		$fill = $('#lrtc-webp-progress-fill');
		$status = $('#lrtc-webp-progress-status');
		$log = $('#lrtc-webp-progress-log');
		if (!$start.length) {
			return;
		}
		$start.on('click', start);
	});
})(jQuery);
