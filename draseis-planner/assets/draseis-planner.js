/* Πρόγραμμα Δράσεων – φίλτρο τάξης και εμφάνιση παλαιότερων */
(function () {
	'use strict';

	function countLabel(n) {
		return n === 1 ? '1 δράση' : n + ' δράσεις';
	}

	function init(root) {
		var state = {
			grade: 'all',
			past: root.classList.contains('g5d-show-past')
		};
		var filters = root.querySelectorAll('.g5d-filter');
		var pastBtn = root.querySelector('.g5d-toggle-past');

		function apply() {
			var nextDay = null;
			var any = false;

			root.classList.toggle('g5d-show-past', state.past);

			Array.prototype.forEach.call(root.querySelectorAll('.g5d-month'), function (month) {
				var n = 0;
				Array.prototype.forEach.call(month.querySelectorAll('.g5d-row'), function (row) {
					var grades = row.getAttribute('data-grades') || '';
					var isPast = row.getAttribute('data-past') === '1';
					var gradeOk = state.grade === 'all' || grades === '' ||
						grades.split(' ').indexOf(state.grade) !== -1;
					var show = gradeOk && (state.past || !isPast);

					row.hidden = !show;
					if (show) {
						n++;
						if (!isPast && nextDay === null) {
								nextDay = row.getAttribute('data-day');
						}
					}
				});
				month.hidden = n === 0;
				if (n > 0) {
					any = true;
				}
				var count = month.querySelector('.g5d-count');
				if (count) {
					count.textContent = countLabel(n);
				}
			});

			// Όλες οι ορατές, μη παρελθούσες δράσεις της πρώτης επερχόμενης ημέρας είναι «επόμενες».
			Array.prototype.forEach.call(root.querySelectorAll('.g5d-row'), function (row) {
				var isNext = !row.hidden && row.getAttribute('data-past') !== '1' && nextDay !== null && row.getAttribute('data-day') === nextDay;
				row.classList.toggle('is-next', isNext);
			});

			var empty = root.querySelector('.g5d-empty');
			if (empty) {
				empty.hidden = any;
			}

			Array.prototype.forEach.call(filters, function (btn) {
				btn.setAttribute('aria-pressed', btn.getAttribute('data-grade') === state.grade ? 'true' : 'false');
			});
			if (pastBtn) {
				pastBtn.setAttribute('aria-pressed', state.past ? 'true' : 'false');
				pastBtn.textContent = state.past ? 'Απόκρυψη παλαιότερων' : 'Εμφάνιση παλαιότερων';
			}
		}

		Array.prototype.forEach.call(filters, function (btn) {
			btn.addEventListener('click', function () {
				state.grade = btn.getAttribute('data-grade');
				apply();
			});
		});
		if (pastBtn) {
			pastBtn.addEventListener('click', function () {
				state.past = !state.past;
				apply();
			});
		}
		apply();
	}

	function start() {
		Array.prototype.forEach.call(document.querySelectorAll('.g5d'), init);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
