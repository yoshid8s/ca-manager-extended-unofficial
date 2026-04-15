document.addEventListener('DOMContentLoaded', function () {
	const tabs = document.querySelectorAll('.camui-tab-button');
	const panels = document.querySelectorAll('.camui-tab-panel');

	if (!tabs.length) return;

	tabs.forEach(tab => {
		tab.addEventListener('click', function () {
			const target = tab.getAttribute('data-camui-tab');

			// タブ切替
			tabs.forEach(t => {
				t.classList.remove('is-active', 'button-primary');
			});
			tab.classList.add('is-active', 'button-primary');

			// パネル切替
			panels.forEach(p => {
				p.classList.remove('is-active');
				p.hidden = true;
			});

			const activePanel = document.querySelector('[data-camui-panel="' + target + '"]');
			if (activePanel) {
				activePanel.classList.add('is-active');
				activePanel.hidden = false;
			}
		});
	});
});