(function () {
	const root = document.querySelector('[data-chatbot]');
	if (!root) return;

	const panel = root.querySelector('[data-chatbot-panel]');
	const toggle = root.querySelector('[data-chatbot-toggle]');
	const close = root.querySelector('[data-chatbot-close]');
	const messages = root.querySelector('[data-chatbot-messages]');
	const faqs = root.querySelector('[data-chatbot-faqs]');
	const faqToggle = root.querySelector('[data-chatbot-faq-toggle]');
	const form = root.querySelector('[data-chatbot-form]');
	const input = root.querySelector('[data-chatbot-input]');
	const send = root.querySelector('[data-chatbot-send]');
	const endpoint = root.dataset.endpoint;
	const faqItems = [
		'How do I update my profile information?',
		'How do I request a costume or inventory item?',
		'How can I view my borrowing requests?',
		'How do I return a borrowed item?',
		'How can I view my participation history?',
		'Why is my account access restricted?',
		'Which campuses are supported?',
		'Who should I contact for technical assistance?'
	];
	let history = [];
	let busy = false;

	function addMessage(role, text, temporary) {
		const item = document.createElement('div');
		item.className = 'oca-chatbot-message ' + role + (temporary ? ' oca-chatbot-typing' : '');
		item.textContent = text;
		messages.appendChild(item);
		messages.scrollTop = messages.scrollHeight;
		return item;
	}

	function setFaqVisibility(visible) {
		faqs.hidden = !visible;
		faqToggle.hidden = visible;
		faqToggle.setAttribute('aria-expanded', visible ? 'true' : 'false');
	}

	function renderFaqs() {
		if (faqs.children.length) return;
		faqItems.forEach(function (question) {
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'oca-chatbot-faq';
			button.textContent = question;
			button.addEventListener('click', function () {
				input.value = question;
				form.requestSubmit();
			});
			faqs.appendChild(button);
		});
	}

	function open() {
		panel.hidden = false;
		toggle.setAttribute('aria-expanded', 'true');
		if (!messages.children.length) {
			addMessage('assistant', 'Hello! I can help students with OCA programs, announcements, events, participation, profile information, borrowing, returns, and other Culture and Arts services. What would you like to know?');
			renderFaqs();
			setFaqVisibility(true);
		}
		input.focus();
	}

	function closePanel() {
		panel.hidden = true;
		toggle.setAttribute('aria-expanded', 'false');
	}

	function setBusy(value) {
		busy = value;
		send.disabled = value;
		input.disabled = value;
		faqToggle.disabled = value;
	}

	toggle.addEventListener('click', function () { panel.hidden ? open() : closePanel(); });
	close.addEventListener('click', closePanel);
	faqToggle.addEventListener('click', function () {
		setFaqVisibility(faqs.hidden);
	});
	input.addEventListener('keydown', function (event) {
		if (event.key === 'Enter' && !event.shiftKey) {
			event.preventDefault();
			form.requestSubmit();
		}
	});
	form.addEventListener('submit', async function (event) {
		event.preventDefault();
		const question = input.value.trim();
		if (!question || busy) return;

		input.value = '';
		setFaqVisibility(false);
		addMessage('user', question);
		history.push({ role: 'user', content: question });
		const typing = addMessage('assistant', 'Checking current OCA information...', true);
		setBusy(true);
		try {
			const response = await fetch(endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ message: question, history: history.slice(-8) })
			});
			const data = await response.json().catch(function () { return {}; });
			typing.remove();
			const answer = data.answer || 'Please ask about OCA student services, events, announcements, profile updates, participation, borrowing, returns, account access, campuses, or technical assistance.';
			addMessage('assistant', answer);
			history.push({ role: 'assistant', content: answer });
		} catch (error) {
			typing.remove();
			addMessage('assistant', 'I can help with OCA student services, events, announcements, profile updates, participation, borrowing, returns, account access, campuses, and technical assistance. Please ask about one of these topics.');
		} finally {
			setBusy(false);
			input.focus();
		}
	});
}());