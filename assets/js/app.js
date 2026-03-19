document.addEventListener('DOMContentLoaded', () => {
	const root = document.getElementById('dashboard-root');
	if (!root) {
		return;
	}

	if (typeof window.BOT_BASE_URL !== 'string' || !window.BOT_BASE_URL.trim()) {
		window.BOT_BASE_URL = '/public';
	}
	const API_BASE = `${window.BOT_BASE_URL.replace(/\/$/, '')}/api`;

	const endpoints = {
		stats: `${API_BASE}/stats.php`,
		botState: `${API_BASE}/bot_state.php`,
		trades: `${API_BASE}/trades.php`,
		logs: `${API_BASE}/logs.php`,
		engineStatus: `${API_BASE}/control.php?action=status`,
		engineStart: `${API_BASE}/control.php?action=start`,
		engineStop: `${API_BASE}/control.php?action=stop`,
		testFlow: `${API_BASE}/test_flow.php`,
	};

	const els = {
		alert: document.getElementById('dashboard-alert'),
		stats: {
			total: document.getElementById('stat-total-bots'),
			enabled: document.getElementById('stat-enabled-bots'),
			running: document.getElementById('stat-running-bots'),
			trades: document.getElementById('stat-total-trades'),
			lastTrade: document.getElementById('stat-last-trade'),
		},
		engine: {
			dot: document.getElementById('engine-status-dot'),
			text: document.getElementById('engine-status-text'),
			message: document.getElementById('engine-last-message'),
			btnStart: document.getElementById('btn-start-bot'),
			btnStop: document.getElementById('btn-stop-bot'),
			btnRefresh: document.getElementById('btn-refresh'),
		},
		test: {
			status: document.getElementById('test-status-text'),
			time: document.getElementById('test-status-time'),
			log: document.getElementById('test-log'),
			btnRun: document.getElementById('btn-run-test'),
		},
		tables: {
			botsBody: document.getElementById('bot-state-body'),
			botsUpdated: document.getElementById('bot-state-updated'),
			tradesBody: document.getElementById('trades-body'),
			logsList: document.getElementById('logs-list'),
		},
		positions: {
			container: document.getElementById('bot-position-container'),
			updated: document.getElementById('bot-position-updated'),
		},
		buttons: {
			tradesRefresh: document.getElementById('btn-trades-refresh'),
			logsRefresh: document.getElementById('btn-logs-refresh'),
		},
	};

	const POLL_INTERVAL_MS = 10000;
	let pollHandle = null;
	let latestBotState = [];

	const escapeHtml = (value) => {
		if (value === null || value === undefined) {
			return '';
		}
		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	};

	const formatNumber = (value, digits = 2, fallback = '--') => {
		if (value === null || value === undefined || value === '') {
			return fallback;
		}
		const num = Number(value);
		return Number.isNaN(num) ? fallback : num.toFixed(digits);
	};

	const formatDate = (value) => {
		if (!value) {
			return '--';
		}
		const date = value instanceof Date ? value : new Date(value);
		return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString();
	};

	const toNumber = (value) => {
		if (value === null || value === undefined || value === '') {
			return null;
		}
		const num = Number(value);
		return Number.isFinite(num) ? num : null;
	};

	const formatSignedNumber = (value, digits = 2, fallback = '--') => {
		if (value === null || value === undefined || Number.isNaN(value)) {
			return fallback;
		}
		const fixed = value.toFixed(digits);
		return value > 0 ? `+${fixed}` : fixed;
	};

	const showAlert = (message, isError = true) => {
		if (!els.alert) return;
		if (!message) {
			els.alert.style.display = 'none';
			els.alert.textContent = '';
			els.alert.classList.remove('alert-error', 'alert-success');
			return;
		}
		els.alert.style.display = 'block';
		els.alert.textContent = message;
		els.alert.classList.toggle('alert-error', isError);
		els.alert.classList.toggle('alert-success', !isError);
	};

	const clearAlert = () => showAlert('', false);

	const apiFetch = async (url, options = {}) => {
		const requestConfig = {
			method: options.method || 'GET',
			headers: {
				Accept: 'application/json',
				...(options.headers || {}),
			},
			credentials: 'same-origin',
			body: options.body,
		};

		let response;
		try {
			response = await fetch(url, requestConfig);
		} catch (networkError) {
			const error = new Error('Server unavailable');
			error.code = 'network';
			error.cause = networkError;
			throw error;
		}

		const raw = await response.text();
		const trimmed = raw.trim();
		let payload = null;
		if (trimmed !== '') {
			try {
				payload = JSON.parse(trimmed);
			} catch (parseErr) {
				const looksHtml = /^<!DOCTYPE/i.test(trimmed) || /^<html/i.test(trimmed);
				const error = new Error('Invalid JSON response');
				error.code = 'invalid-json';
				error.responseSnippet = trimmed.slice(0, 200);
				error.cause = parseErr;
				error.html = looksHtml;
				throw error;
			}
		}

		if (!response.ok) {
			const message = payload && typeof payload.message === 'string'
				? payload.message
				: (trimmed || `HTTP ${response.status}`);
			if (response.status === 401) {
				const loginUrl = `${window.BOT_BASE_URL.replace(/\/$/, '')}/login.php`;
				window.location.href = loginUrl;
			}
			const error = new Error(message);
			error.code = 'http';
			error.status = response.status;
			throw error;
		}

		if (!payload) {
			const error = new Error('Server response was empty.');
			error.code = 'empty';
			throw error;
		}

		if (payload.success === false) {
			const error = new Error(payload.message || 'Request failed.');
			error.code = 'api';
			error.meta = payload.meta;
			throw error;
		}

		if (Object.prototype.hasOwnProperty.call(payload, 'data')) {
			return payload.data;
		}

		return payload;
	};

	const normalizeApiError = (error) => {
		if (!error || typeof error !== 'object') {
			return 'Unexpected error encountered.';
		}
		if (error.code === 'network') {
			return 'Server unavailable';
		}
		if (error.code === 'invalid-json') {
			return 'Invalid JSON response';
		}
		if (error.code === 'empty') {
			return 'Server response was empty.';
		}
		if (error.code === 'http') {
			if (error.status === 404) {
				return 'Endpoint not found.';
			}
			if (error.status >= 500) {
				return 'Server error. Please try again.';
			}
		}
		if (error.code === 'api' && error.message) {
			return error.message;
		}
		return error.message || 'Unexpected error encountered.';
	};

	const fetchWithHandling = async (label, url, options = {}) => {
		try {
			return await apiFetch(url, options);
		} catch (error) {
			const message = normalizeApiError(error);
			console.error(`${label} request failed:`, message, error);
			const wrapped = new Error(message);
			wrapped.cause = error;
			wrapped.label = label;
			wrapped.code = error && error.code ? error.code : undefined;
			wrapped.status = error && error.status ? error.status : undefined;
			throw wrapped;
		}
	};

	const renderStats = (stats = {}) => {
		if (!els.stats.total) return;
		els.stats.total.textContent = stats.total_bots != null ? stats.total_bots : '--';
		els.stats.enabled.textContent = stats.enabled_bots != null ? stats.enabled_bots : '--';
		els.stats.running.textContent = stats.running_bots != null ? stats.running_bots : '--';
		els.stats.trades.textContent = stats.total_trades != null ? stats.total_trades : '--';
		if (els.stats.lastTrade) {
			const suffix = stats.last_trade_at ? formatDate(stats.last_trade_at) : '--';
			els.stats.lastTrade.textContent = `Last trade: ${suffix}`;
		}
	};

	const renderEngine = (engine = {}) => {
		const running = Boolean(engine.running);
		if (els.engine.dot) {
			els.engine.dot.classList.toggle('running', running);
			els.engine.dot.classList.toggle('stopped', !running);
		}
		if (els.engine.text) {
			const pidText = engine.pid ? ` (PID ${engine.pid})` : '';
			els.engine.text.textContent = running ? `RUNNING${pidText}` : 'STOPPED';
		}
		if (els.engine.message) {
			els.engine.message.textContent = engine.message || 'Waiting for engine update…';
		}
		if (els.engine.btnStart) {
			els.engine.btnStart.disabled = running;
		}
		if (els.engine.btnStop) {
			els.engine.btnStop.disabled = !running;
		}
	};

	const renderBots = (payload) => {
		if (!els.tables.botsBody) return;
		const rows = Array.isArray(payload)
			? payload
			: payload && Array.isArray(payload.bots)
				? payload.bots
				: payload && Array.isArray(payload.rows)
					? payload.rows
					: [];

		if (!rows.length) {
			els.tables.botsBody.innerHTML = '<tr><td colspan="7" class="help center">No bots yet.</td></tr>';
		} else {
			els.tables.botsBody.innerHTML = rows.map((bot) => `
				<tr>
					<td>
						<strong>${escapeHtml(bot.name ?? 'Bot')}</strong>
						<div class="help">${escapeHtml(bot.symbol ?? '--')}</div>
					</td>
					<td>${escapeHtml(bot.direction ?? '--')}</td>
					<td>${escapeHtml(bot.status ?? 'IDLE')}</td>
					<td>${formatNumber(bot.position_qty ?? 0, 4)}</td>
					<td>${formatNumber(bot.avg_entry_price ?? 0)}</td>
					<td>${formatNumber(bot.last_price ?? 0)}</td>
					<td>${formatDate(bot.updated_at)}</td>
				</tr>
			`).join('');
		}

		if (els.tables.botsUpdated) {
			els.tables.botsUpdated.textContent = `Last update: ${formatDate(new Date())}`;
		}
		latestBotState = rows;
		renderPositionStatus(rows);
	};

	const renderTrades = (payload) => {
		if (!els.tables.tradesBody) return;
		const rows = Array.isArray(payload)
			? payload
			: payload && Array.isArray(payload.trades)
				? payload.trades
				: payload && Array.isArray(payload.rows)
					? payload.rows
					: [];

		if (!rows.length) {
			els.tables.tradesBody.innerHTML = '<tr><td colspan="7" class="help center">No trades yet.</td></tr>';
			return;
		}

		els.tables.tradesBody.innerHTML = rows.map((trade) => `
			<tr>
				<td>${formatDate(trade.created_at)}</td>
				<td>${escapeHtml(trade.bot_name ?? '--')}</td>
				<td>${escapeHtml(trade.symbol ?? '--')}</td>
				<td>${escapeHtml(trade.side ?? trade.direction ?? trade.action ?? '--')}</td>
				<td>${formatNumber(trade.qty ?? trade.quantity ?? 0, 4)}</td>
				<td>${formatNumber(trade.price ?? 0)}</td>
				<td class="help">${escapeHtml(trade.order_id ?? trade.exchange_order_id ?? '')}</td>
			</tr>
		`).join('');
	};

	const renderLogs = (payload) => {
		if (!els.tables.logsList) return;
		const rows = Array.isArray(payload)
			? payload
			: payload && Array.isArray(payload.logs)
				? payload.logs
				: payload && Array.isArray(payload.rows)
					? payload.rows
					: [];

		if (!rows.length) {
			els.tables.logsList.innerHTML = '<p class="help">No logs recorded yet.</p>';
			return;
		}

		els.tables.logsList.innerHTML = rows.map((log) => `
			<div class="log-item ${log.level ? log.level.toLowerCase() : 'info'}">
				<div>${escapeHtml(log.message ?? '')}</div>
				<div class="log-meta">
					<span>${escapeHtml(log.bot_name ?? '')}</span>
					<span>${formatDate(log.created_at)}</span>
				</div>
			</div>
		`).join('');
	};

	const deriveStatusDescriptor = (bot, filledSteps, totalSteps, hasPosition) => {
		const base = (bot.status || '').toUpperCase();
		if (base === 'STOPPED' || base === 'ERROR') {
			return { label: 'STOPPED', className: 'status-stopped' };
		}
		if (!hasPosition) {
			if (base === 'CLOSED' || base === 'COMPLETED') {
				return { label: 'TAKE PROFIT HIT', className: 'status-holding' };
			}
			return { label: 'WAITING', className: 'status-waiting' };
		}
		if (base === 'CLOSED' || base === 'COMPLETED') {
			return { label: 'TAKE PROFIT HIT', className: 'status-holding' };
		}
		if (filledSteps >= totalSteps) {
			return { label: 'HOLDING', className: 'status-holding' };
		}
		if (filledSteps <= 1) {
			return { label: 'ENTRY TRIGGERED', className: 'status-entry' };
		}
		return { label: `STEP ${filledSteps} BOUGHT`, className: 'status-step' };
	};

	const derivePositionStats = (bot) => {
		const rawQty = toNumber(bot.position_qty);
		const qty = rawQty ?? 0;
		const lastPrice = toNumber(bot.last_price);
		const direction = (bot.direction || 'LONG').toUpperCase();
		const safetyCount = Math.max(0, Number(bot.safety_order_count ?? 0));
		const maxSafety = Math.max(0, Number(bot.max_safety_orders ?? 0));
		const totalSteps = Math.max(1, maxSafety + 1);
		const hasPosition = Boolean(rawQty && rawQty > 0);
		const rawAvg = toNumber(bot.avg_entry_price);
		const avgPrice = hasPosition ? rawAvg : null;
		const filledSteps = hasPosition ? Math.min(totalSteps, Math.max(1, safetyCount + 1)) : 0;
		const totalInvested = qty && avgPrice ? qty * avgPrice : null;
		let pnl = null;
		let pnlPct = null;
		if (qty && avgPrice && lastPrice) {
			const diff = direction === 'SHORT' ? (avgPrice - lastPrice) : (lastPrice - avgPrice);
			pnl = diff * qty;
			pnlPct = (diff / avgPrice) * 100;
		}
		const nextDropPct = toNumber(bot.next_dca_trigger_drop_pct);
		let nextDcaPrice = null;
		if (avgPrice && nextDropPct !== null) {
			const factor = nextDropPct / 100;
			nextDcaPrice = direction === 'SHORT'
				? avgPrice * (1 + factor)
				: avgPrice * (1 - factor);
		}
		const tpPct = toNumber(bot.trailing_activation_profit_pct);
		let takeProfitTarget = null;
		if (avgPrice && tpPct !== null) {
			const factor = tpPct / 100;
			takeProfitTarget = direction === 'SHORT'
				? avgPrice * (1 - factor)
				: avgPrice * (1 + factor);
		}
		const statusInfo = deriveStatusDescriptor(bot, filledSteps, totalSteps, hasPosition);
		return {
			totalSteps,
			filledSteps,
			hasPosition,
			qty,
			totalInvested,
			avgPrice,
			lastPrice,
			pnl,
			pnlPct,
			nextDcaPrice,
			takeProfitTarget,
			statusInfo,
			updatedAt: bot.updated_at,
			lastBuyPrice: hasPosition ? (avgPrice ?? lastPrice) : null,
			direction,
		};
	};

	const renderPositionStatus = (rows) => {
		if (!els.positions.container) return;
		const data = Array.isArray(rows) ? rows : [];
		if (!data.length) {
			els.positions.container.innerHTML = '<p class="help center full-width">No bots yet.</p>';
			if (els.positions.updated) {
				els.positions.updated.textContent = 'Last update: --';
			}
			return;
		}
		els.positions.container.innerHTML = data.map((bot) => {
			const stats = derivePositionStats(bot);
			const pnlClass = stats.pnl > 0 ? 'pnl-positive' : stats.pnl < 0 ? 'pnl-negative' : 'pnl-neutral';
			const pnlAmount = formatSignedNumber(stats.pnl, 2, '--');
			const pnlPercent = formatSignedNumber(stats.pnlPct, 2, '--');
			const pnlPercentText = pnlPercent === '--' ? '--' : `${pnlPercent}%`;
			const qtyDisplay = stats.qty != null ? formatNumber(stats.qty, 4) : '--';
			const investedDisplay = stats.totalInvested != null ? `${formatNumber(stats.totalInvested, 2)} USDT` : '--';
			const avgDisplay = stats.avgPrice != null ? formatNumber(stats.avgPrice, 4) : '--';
			const lastPriceDisplay = stats.lastPrice != null ? formatNumber(stats.lastPrice, 4) : '--';
			const lastBuyDisplay = stats.lastBuyPrice != null ? formatNumber(stats.lastBuyPrice, 4) : '--';
			const nextDcaDisplay = stats.nextDcaPrice != null ? formatNumber(stats.nextDcaPrice, 4) : '--';
			const takeProfitDisplay = stats.takeProfitTarget != null ? formatNumber(stats.takeProfitTarget, 4) : '--';
			return `
				<article class="position-card">
					<div class="position-head">
						<div>
							<h3>${escapeHtml(bot.name ?? 'Bot')}</h3>
							<p class="help">${escapeHtml(bot.symbol ?? '--')} • ${escapeHtml(bot.direction ?? '--')}</p>
						</div>
						<span class="status-pill ${stats.statusInfo.className}">${escapeHtml(stats.statusInfo.label)}</span>
					</div>
					<div class="position-body-grid">
						<div class="position-metric">
							<span class="label">Current Step</span>
							<span class="value"><span class="step-pill">${stats.filledSteps} of ${stats.totalSteps}</span></span>
						</div>
						<div class="position-metric">
							<span class="label">Total Quantity</span>
							<span class="value">${qtyDisplay}</span>
						</div>
						<div class="position-metric">
							<span class="label">Total Invested</span>
							<span class="value">${investedDisplay}</span>
						</div>
						<div class="position-metric">
							<span class="label">Avg Entry</span>
							<span class="value">${avgDisplay}</span>
						</div>
						<div class="position-metric">
							<span class="label">Current Price</span>
							<span class="value">${lastPriceDisplay}</span>
						</div>
						<div class="position-metric">
							<span class="label">Unrealized PnL</span>
							<span class="value ${pnlClass}">${pnlAmount} (${pnlPercentText})</span>
						</div>
						<div class="position-metric">
							<span class="label">Last Buy Price</span>
							<span class="value">${lastBuyDisplay}</span>
						</div>
						<div class="position-metric">
							<span class="label">Next DCA Price</span>
							<span class="value">${nextDcaDisplay}</span>
						</div>
						<div class="position-metric">
							<span class="label">Take Profit Target</span>
							<span class="value">${takeProfitDisplay}</span>
						</div>
						<div class="position-metric">
							<span class="label">Updated</span>
							<span class="value">${formatDate(stats.updatedAt)}</span>
						</div>
					</div>
				</article>
			`;
		}).join('');
		if (els.positions.updated) {
			els.positions.updated.textContent = `Last update: ${formatDate(new Date())}`;
		}
	};

	const renderTestStatus = (payload = {}) => {
		if (!els.test || !els.test.status) {
			return;
		}
		const result = (payload.result ?? payload.last_test_result ?? '').toString().toUpperCase();
		els.test.status.textContent = result || 'NOT RUN';
		const started = payload.started_at ?? payload.last_test_started_at ?? null;
		const completed = payload.completed_at ?? payload.last_test_completed_at ?? null;
		if (els.test.time) {
			if (completed) {
				els.test.time.textContent = `Completed: ${formatDate(completed)}`;
			} else if (started) {
				els.test.time.textContent = `Started: ${formatDate(started)}`;
			} else {
				els.test.time.textContent = 'Never run';
			}
		}
		if (!els.test.log) {
			return;
		}
		const steps = Array.isArray(payload.steps)
			? payload.steps
			: Array.isArray(payload.last_test_log)
				? payload.last_test_log
				: [];
		if (!steps.length) {
			els.test.log.innerHTML = '<p class="help">No verification history yet.</p>';
			return;
		}
		els.test.log.innerHTML = steps
			.map((step) => `
				<div class="test-step ${step.success ? 'pass' : 'fail'}">
					<div>
						<strong>${escapeHtml(step.stage ?? 'step')}</strong>
						<span class="help">${formatDate(step.timestamp)}</span>
					</div>
					<div>${escapeHtml(step.message ?? '')}</div>
				</div>
			`)
			.join('');
	};

	const loadStats = () => fetchWithHandling('stats', endpoints.stats).then((data) => renderStats(data || {}));
	const loadBots = () => fetchWithHandling('bot state', endpoints.botState).then((data) => {
		const list = data && Array.isArray(data.bots) ? data.bots : data;
		renderBots(list || []);
	});
	const loadTrades = () => fetchWithHandling('trades', endpoints.trades).then((data) => {
		const list = data && Array.isArray(data.trades) ? data.trades : data;
		renderTrades(list || []);
	});
	const loadLogs = (suppressErrors = false) => fetchWithHandling('logs', endpoints.logs)
		.then((data) => {
			const list = data && Array.isArray(data.logs) ? data.logs : data;
			renderLogs(list || []);
		})
		.catch((error) => {
			if (els.tables.logsList) {
				els.tables.logsList.innerHTML = `<p class="help">${escapeHtml(error.message || 'Unable to load logs.')}</p>`;
			}
			if (suppressErrors) {
				return null;
			}
			throw error;
		});
	const loadEngine = () => fetchWithHandling('engine status', endpoints.engineStatus).then((data) => renderEngine(data || {}));
	const loadTestStatus = (suppressErrors = false) => fetchWithHandling('test status', endpoints.testFlow)
		.then((data) => renderTestStatus(data || {}))
		.catch((error) => {
			if (els.test && els.test.log) {
				els.test.log.innerHTML = `<p class="help">${escapeHtml(error.message || 'Unable to load test status.')}</p>`;
			}
			if (els.test && els.test.status) {
				els.test.status.textContent = error.message || 'Unavailable';
			}
			if (suppressErrors) {
				return null;
			}
			throw error;
		});

	const refreshJobs = [
		{ label: 'engine status', fn: loadEngine },
		{ label: 'stats', fn: loadStats },
		{ label: 'bot state', fn: loadBots },
		{ label: 'trades', fn: loadTrades },
		{ label: 'logs', fn: () => loadLogs(true) },
		{ label: 'test status', fn: () => loadTestStatus(true) },
	];

	const refreshAll = () => {
		clearAlert();
		return Promise.allSettled(refreshJobs.map((job) => job.fn()))
			.then((results) => {
				const failures = results
					.map((result, idx) => ({ result, job: refreshJobs[idx] }))
					.filter(({ result }) => result.status === 'rejected');

				if (failures.length) {
					failures.forEach(({ job, result }) => {
						const reason = result.reason ? result.reason.message || result.reason : 'Unknown error';
						console.error(`Dashboard refresh failed for ${job.label}:`, reason);
					});

					const first = failures[0];
					const message = first.result.reason && first.result.reason.message
						? first.result.reason.message
						: `Unable to refresh ${first.job.label}.`;
					showAlert(message);
				}
			});
	};

	const refreshTradesOnly = () => {
		clearAlert();
		return loadTrades().catch((error) => showAlert(error.message || 'Unable to refresh trades.'));
	};

	const refreshLogsOnly = () => {
		clearAlert();
		return loadLogs().catch((error) => showAlert(error.message || 'Unable to refresh logs.'));
	};

	const runVerificationTest = () => {
		const btn = els.test.btnRun;
		if (!btn || btn.dataset.running === '1') {
			return;
		}
		btn.dataset.running = '1';
		btn.dataset.defaultLabel = btn.dataset.defaultLabel || btn.textContent;
		btn.disabled = true;
		btn.textContent = 'Running Test…';
		clearAlert();
		fetchWithHandling('verification test', endpoints.testFlow, { method: 'POST' })
			.then((data) => {
				renderTestStatus(data || {});
				showAlert('End-to-end verification completed.', false);
				return refreshAll();
			})
			.catch((error) => {
				showAlert(error.message || 'Unable to complete verification test.');
			})
			.finally(() => {
				btn.disabled = false;
				btn.dataset.running = '0';
				btn.textContent = btn.dataset.defaultLabel || 'Run End-to-End Test';
				loadTestStatus();
			});
	};

	const handleEngineAction = (button, url, successMessage) => {
		if (!button || button.disabled) return;
		button.disabled = true;
		clearAlert();
		fetchWithHandling('engine control', url, { method: 'POST' })
			.then(() => loadEngine())
			.then(() => showAlert(successMessage, false))
			.catch((error) => {
				showAlert(error.message || 'Engine request failed.');
				button.disabled = false;
			});
	};

	if (els.engine.btnStart) {
		els.engine.btnStart.addEventListener('click', () => {
			handleEngineAction(els.engine.btnStart, endpoints.engineStart, 'Engine start requested.');
		});
	}

	if (els.engine.btnStop) {
		els.engine.btnStop.addEventListener('click', () => {
			handleEngineAction(els.engine.btnStop, endpoints.engineStop, 'Engine stop requested.');
		});
	}

	if (els.engine.btnRefresh) {
		els.engine.btnRefresh.addEventListener('click', () => {
			refreshAll();
		});
	}

	if (els.test.btnRun) {
		els.test.btnRun.addEventListener('click', runVerificationTest);
	}

	if (els.buttons.tradesRefresh) {
		els.buttons.tradesRefresh.addEventListener('click', refreshTradesOnly);
	}

	if (els.buttons.logsRefresh) {
		els.buttons.logsRefresh.addEventListener('click', refreshLogsOnly);
	}

	refreshAll();
	pollHandle = setInterval(refreshAll, POLL_INTERVAL_MS);

	window.addEventListener('beforeunload', () => {
		if (pollHandle) {
			clearInterval(pollHandle);
		}
	});
});
