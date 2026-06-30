(function () {
    'use strict';

    const FIELD_LABELS = {
        name: 'Имя',
        phone: 'Телефон',
        email: 'Email',
        messenger: 'Мессенджер',
        comment: 'Комментарий'
    };

    const INPUT_TYPES = ['text', 'textarea', 'phone', 'email'];
    const OPTION_TYPES = ['radio', 'select'];
    const TEMPLATE_NAMES = ['image_cards', 'cards', 'list'];

    const clear = (node) => {
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
    };

    const create = (tagName, className, text) => {
        const element = document.createElement(tagName);
        if (className) {
            element.className = className;
        }
        if (text !== undefined && text !== null && String(text) !== '') {
            element.textContent = String(text);
        }
        return element;
    };

    const toArray = (value) => Array.isArray(value) ? value : [];
    const toId = (value) => Number.parseInt(value, 10) || null;

    const getQuestionType = (question) => {
        const type = String(question.question_type || 'radio').toLowerCase();
        return [...OPTION_TYPES, 'checkbox', ...INPUT_TYPES].includes(type) ? type : 'radio';
    };

    const getDisplayTemplate = (question) => {
        const template = String(question.display_template || 'list').toLowerCase();
        return TEMPLATE_NAMES.includes(template) ? template : 'list';
    };

    const findById = (items, id) => toArray(items).find((item) => toId(item.id) === toId(id)) || null;

    const getUtm = () => {
        const params = new URLSearchParams(window.location.search);
        return ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].reduce((result, key) => {
            result[key] = params.get(key) || '';
            return result;
        }, {});
    };

    const getAjaxUrl = (root) => {
        const sessid = root.getAttribute('data-kk-quiz-sessid') || (window.BX && BX.bitrix_sessid ? BX.bitrix_sessid() : '');
        const params = new URLSearchParams({ action: 'kk:quiz.api.submitLead' });
        if (sessid) {
            params.set('sessid', sessid);
        }
        return '/bitrix/services/main/ajax.php?' + params.toString();
    };

    const getErrorMessage = (error) => {
        if (typeof error === 'string') {
            return error;
        }

        if (error && typeof error === 'object') {
            if (typeof error.message === 'string' && error.message !== '') {
                return error.message;
            }

            if (typeof error.title === 'string' && error.title !== '') {
                return error.title;
            }

            if (typeof error.text === 'string' && error.text !== '') {
                return error.text;
            }
        }

        return 'Не удалось отправить заявку. Попробуйте позже.';
    };

    const normalizeAjaxResponse = (data) => {
        if (!data || typeof data !== 'object') {
            return data;
        }

        if (data.data && typeof data.data === 'object') {
            if (Array.isArray(data.errors) && data.errors.length > 0 && !data.data.errors) {
                return {
                    success: false,
                    errors: data.errors.map(getErrorMessage)
                };
            }

            return data.data;
        }

        if (Array.isArray(data.errors) && data.errors.length > 0) {
            return {
                success: false,
                errors: data.errors.map(getErrorMessage)
            };
        }

        return data;
    };

    const buildState = () => ({
        answers: {},
        fields: {}
    });

    const appendTextBlock = (container, className, text) => {
        if (text === undefined || text === null || String(text) === '') {
            return;
        }
        container.appendChild(create('div', className, text));
    };

    const hideAll = (nodes) => {
        nodes.start.hidden = true;
        nodes.question.hidden = true;
        nodes.form.hidden = true;
        nodes.result.hidden = true;
    };

    const showFinalForm = (nodes, quiz, state, currentResult) => {
        hideAll(nodes);
        clear(nodes.form);
        nodes.form.hidden = false;

        nodes.form.appendChild(create('h3', 'kk-quiz__form-title', 'Получить подборку'));

        const fields = toArray(quiz.form_fields).filter((field) => Object.prototype.hasOwnProperty.call(FIELD_LABELS, field));
        const requiredFields = toArray(quiz.required_fields);
        const form = create('form', 'kk-quiz__form-fields');
        const honeypot = document.createElement('input');
        honeypot.type = 'text';
        honeypot.name = 'website';
        honeypot.tabIndex = -1;
        honeypot.autocomplete = 'off';
        honeypot.hidden = true;
        form.appendChild(honeypot);

        (fields.length > 0 ? fields : ['name', 'phone', 'email']).forEach((field) => {
            const label = create('label', 'kk-quiz__field');
            label.appendChild(create('span', 'kk-quiz__field-label', FIELD_LABELS[field]));

            const input = field === 'comment' ? document.createElement('textarea') : document.createElement('input');
            input.className = 'kk-quiz__input';
            input.name = field;
            input.required = requiredFields.includes(field);
            input.type = field === 'email' ? 'email' : field === 'phone' ? 'tel' : 'text';
            input.addEventListener('input', () => {
                state.fields[field] = input.value;
            });

            label.appendChild(input);
            form.appendChild(label);
        });

        const submit = create('button', 'kk-quiz__button', 'Получить подборку');
        submit.type = 'submit';
        form.appendChild(submit);

        const message = create('div', 'kk-quiz__success');
        message.hidden = true;

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            message.hidden = true;
            message.textContent = '';
            submit.disabled = true;

            const formData = new FormData(form);
            fields.forEach((field) => {
                state.fields[field] = String(formData.get(field) || '');
            });

            const payload = {
                quiz_code: quiz.code,
                result_id: currentResult ? currentResult.id : null,
                result_code: currentResult ? currentResult.code : '',
                result_title: currentResult ? currentResult.name : '',
                fields: Object.assign({}, state.fields),
                answers: state.answers,
                page_url: window.location.href,
                referer: document.referrer,
                utm: getUtm(),
                website: honeypot.value
            };

            fetch(getAjaxUrl(nodes.root), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ payload })
            })
                .then((response) => response.json())
                .then((data) => {
                    const result = normalizeAjaxResponse(data);
                    if (result && result.success === true) {
                        form.hidden = true;
                        message.className = 'kk-quiz__success';
                        message.textContent = 'Спасибо! Заявка отправлена. Мы скоро свяжемся с вами.';
                        message.hidden = false;
                        return;
                    }

                    const errors = result && Array.isArray(result.errors) && result.errors.length > 0
                        ? result.errors
                        : ['Не удалось отправить заявку. Попробуйте позже.'];
                    message.className = 'kk-quiz__error';
                    message.innerHTML = '';
                    const list = document.createElement('ul');
                    errors.forEach((error) => {
                        const item = document.createElement('li');
                        item.textContent = getErrorMessage(error);
                        list.appendChild(item);
                    });
                    message.appendChild(list);
                    message.hidden = false;
                })
                .catch(() => {
                    message.className = 'kk-quiz__error';
                    message.textContent = 'Не удалось отправить заявку. Попробуйте позже.';
                    message.hidden = false;
                })
                .finally(() => {
                    submit.disabled = false;
                });
        });

        nodes.form.appendChild(form);
        nodes.form.appendChild(message);
    };

    const showResult = (nodes, quiz, state, resultId) => {
        const result = findById(quiz.results, resultId);
        if (!result) {
            showFinalForm(nodes, quiz, state, null);
            return;
        }

        hideAll(nodes);
        clear(nodes.result);
        nodes.result.hidden = false;

        const card = create('div', 'kk-quiz__result-card');
        appendTextBlock(card, 'kk-quiz__badge', result.badge);

        if (result.picture_src) {
            const image = document.createElement('img');
            image.className = 'kk-quiz__result-image';
            image.src = String(result.picture_src);
            image.alt = String(result.name || '');
            card.appendChild(image);
        }

        appendTextBlock(card, 'kk-quiz__result-title', result.name);
        appendTextBlock(card, 'kk-quiz__result-text', result.preview_text);

        if (result.cta_text && result.cta_link) {
            const link = create('a', 'kk-quiz__button kk-quiz__button--link', result.cta_text);
            link.href = String(result.cta_link);
            card.appendChild(link);
        }

        nodes.result.appendChild(card);

        if (result.show_form === true) {
            const formWrap = create('div', 'kk-quiz__result-form');
            nodes.result.appendChild(formWrap);
            const originalForm = nodes.form;
            nodes.form = formWrap;
            showFinalForm(nodes, quiz, state, result);
            nodes.result.hidden = false;
            nodes.form = originalForm;
        }
    };

    const goNext = (nodes, quiz, state, question, answer) => {
        if (answer && answer.result_id) {
            showResult(nodes, quiz, state, answer.result_id);
            return;
        }

        const nextQuestionId = answer && answer.next_question_id ? answer.next_question_id : question.default_next_question_id;
        if (nextQuestionId) {
            showQuestion(nodes, quiz, state, nextQuestionId);
            return;
        }

        showFinalForm(nodes, quiz, state, null);
    };

    const renderAnswerMedia = (button, answer) => {
        if (!answer.image_src) {
            return;
        }

        const image = document.createElement('img');
        image.className = 'kk-quiz__answer-image';
        image.src = String(answer.image_src);
        image.alt = String(answer.text || '');
        button.appendChild(image);
    };

    const renderAnswerText = (button, answer) => {
        button.appendChild(create('span', 'kk-quiz__answer-text', answer.text));
        appendTextBlock(button, 'kk-quiz__answer-description', answer.description);
    };

    function showQuestion(nodes, quiz, state, questionId) {
        const question = findById(quiz.questions, questionId);
        if (!question) {
            showFinalForm(nodes, quiz, state, null);
            return;
        }

        hideAll(nodes);
        clear(nodes.question);
        nodes.question.hidden = false;

        const type = getQuestionType(question);
        const template = getDisplayTemplate(question);

        nodes.question.appendChild(create('h3', 'kk-quiz__question-title', question.name));
        appendTextBlock(nodes.question, 'kk-quiz__question-hint', question.hint);

        if (OPTION_TYPES.includes(type)) {
            renderSingleChoice(nodes, quiz, state, question, template);
            return;
        }

        if (type === 'checkbox') {
            renderCheckboxes(nodes, quiz, state, question, template);
            return;
        }

        renderInputQuestion(nodes, quiz, state, question, type);
    }

    const renderSingleChoice = (nodes, quiz, state, question, template) => {
        const answers = create('div', 'kk-quiz__answers kk-quiz__answers--' + template);
        toArray(question.answers).forEach((answer) => {
            const button = create('button', 'kk-quiz__answer kk-quiz__answer--' + template);
            button.type = 'button';
            renderAnswerMedia(button, answer);
            renderAnswerText(button, answer);
            button.addEventListener('click', () => {
                state.answers[question.id] = answer;
                button.classList.add('kk-quiz__answer--active');
                goNext(nodes, quiz, state, question, answer);
            });
            answers.appendChild(button);
        });
        nodes.question.appendChild(answers);
    };

    const renderCheckboxes = (nodes, quiz, state, question, template) => {
        const selected = new Set();
        const answers = create('div', 'kk-quiz__answers kk-quiz__answers--' + template);

        toArray(question.answers).forEach((answer, index) => {
            const label = create('label', 'kk-quiz__answer kk-quiz__answer--' + template);
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.value = String(index);
            label.appendChild(input);
            renderAnswerMedia(label, answer);
            renderAnswerText(label, answer);
            input.addEventListener('change', () => {
                if (input.checked) {
                    selected.add(index);
                    label.classList.add('kk-quiz__answer--active');
                } else {
                    selected.delete(index);
                    label.classList.remove('kk-quiz__answer--active');
                }
            });
            answers.appendChild(label);
        });

        const next = create('button', 'kk-quiz__button kk-quiz__button--next', 'Далее');
        next.type = 'button';
        next.addEventListener('click', () => {
            state.answers[question.id] = [...selected].map((index) => question.answers[index]);
            goNext(nodes, quiz, state, question, null);
        });

        nodes.question.appendChild(answers);
        nodes.question.appendChild(next);
    };

    const renderInputQuestion = (nodes, quiz, state, question, type) => {
        const label = create('label', 'kk-quiz__field kk-quiz__field--question');
        const input = type === 'textarea' ? document.createElement('textarea') : document.createElement('input');
        input.className = 'kk-quiz__input';
        input.required = question.is_required === true;
        input.placeholder = String(question.placeholder || '');
        input.type = type === 'phone' ? 'tel' : type === 'email' ? 'email' : 'text';
        label.appendChild(input);

        const next = create('button', 'kk-quiz__button kk-quiz__button--next', 'Далее');
        next.type = 'button';
        next.addEventListener('click', () => {
            if (input.required && input.value.trim() === '') {
                input.focus();
                return;
            }
            state.answers[question.id] = input.value;
            goNext(nodes, quiz, state, question, null);
        });

        nodes.question.appendChild(label);
        nodes.question.appendChild(next);
    };

    document.querySelectorAll('[data-kk-quiz]').forEach((root) => {
        const dataNode = root.querySelector('[data-kk-quiz-data]');
        if (!dataNode) {
            return;
        }

        let quiz = null;
        try {
            quiz = JSON.parse(dataNode.textContent || '{}');
        } catch (error) {
            return;
        }

        const nodes = {
            start: root.querySelector('[data-kk-quiz-start]'),
            question: root.querySelector('[data-kk-quiz-question]'),
            form: root.querySelector('[data-kk-quiz-form]'),
            result: root.querySelector('[data-kk-quiz-result]'),
            root: root
        };

        if (!nodes.start || !nodes.question || !nodes.form || !nodes.result) {
            return;
        }

        const state = buildState();
        const startButton = root.querySelector('[data-kk-quiz-start-button]');
        if (startButton) {
            startButton.addEventListener('click', () => {
                const firstQuestionId = toId(quiz.first_question_id);
                if (firstQuestionId) {
                    showQuestion(nodes, quiz, state, firstQuestionId);
                    return;
                }
                showFinalForm(nodes, quiz, state, null);
            });
        }
    });
}());
