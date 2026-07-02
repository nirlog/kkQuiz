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



    const buildAnswerPayload = (answer, index) => ({
        code: String(answer.code || ''),
        sort: Number(answer.sort || 0),
        index: Number(index)
    });

    const formatPhoneInput = (value) => {
        const raw = String(value || '');
        const trimmed = raw.trim();
        const digits = raw.replace(/\D+/g, '');

        if (digits === '') {
            return '';
        }

        const startsWithPlus = trimmed.startsWith('+');
        const isRussian = digits[0] === '7'
            || digits[0] === '8'
            || (!startsWithPlus && digits[0] === '9');

        if (isRussian) {
            let number = digits;
            if (number.length > 0 && (number[0] === '7' || number[0] === '8')) {
                number = number.slice(1);
            }

            number = number.slice(0, 10);
            let result = '+7';

            if (number.length > 0) {
                result += ' (' + number.slice(0, 3);
            }

            if (number.length >= 3) {
                result += ')';
            }

            if (number.length > 3) {
                result += ' ' + number.slice(3, 6);
            }

            if (number.length > 6) {
                result += '-' + number.slice(6, 8);
            }

            if (number.length > 8) {
                result += '-' + number.slice(8, 10);
            }

            return result;
        }

        if (startsWithPlus) {
            return '+' + digits.slice(0, 15);
        }

        return digits.slice(0, 15);
    };


    const removeLastPhoneDigit = (value) => {
        const digits = String(value || '').replace(/\D+/g, '');
        if (digits === '') {
            return '';
        }

        return formatPhoneInput(digits.slice(0, -1));
    };

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


    const sendMetrikaGoal = (quiz, goalName, params = {}) => {
        if (!quiz || !quiz.metrika || quiz.metrika.enabled !== true) {
            return;
        }

        const counterId = String(quiz.metrika.counter_id || '').trim();
        if (counterId === '') {
            return;
        }

        const goal = String(goalName || '').trim();
        if (goal === '') {
            return;
        }

        if (typeof window.ym !== 'function') {
            return;
        }

        try {
            window.ym(Number(counterId), 'reachGoal', goal, params);
        } catch (error) {
            // Ошибка Метрики не должна ломать отправку формы.
        }
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


    const buildAgreementField = (quiz) => {
        if (!quiz.privacy || quiz.privacy.required !== true) {
            return null;
        }

        const wrapper = document.createElement('label');
        wrapper.className = 'kk-quiz__agreement';

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.name = 'agreement';
        checkbox.value = 'Y';
        checkbox.required = true;

        const text = document.createElement('span');
        text.textContent = quiz.privacy.text || 'Я согласен с политикой обработки персональных данных';

        wrapper.appendChild(checkbox);
        wrapper.appendChild(text);

        const url = String(quiz.privacy.url || '').trim();
        if (url !== '') {
            const link = document.createElement('a');
            link.href = url;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = 'Подробнее';
            wrapper.appendChild(document.createTextNode(' '));
            wrapper.appendChild(link);
        }

        return wrapper;
    };

    const showFinalForm = (nodes, quiz, state, currentResult) => {
        hideAll(nodes);
        clear(nodes.form);
        nodes.form.hidden = false;

        const formButtonText = String(quiz.form_button_text || '').trim() || 'Получить подборку';
        const formTitle = String(quiz.form_title || '').trim() || 'Получить подборку';
        const formSubtitle = String(quiz.form_subtitle || '').trim();
        const successText = String(quiz.success_text || '').trim() || 'Спасибо! Заявка отправлена. Мы скоро свяжемся с вами.';
        nodes.form.appendChild(create('h3', 'kk-quiz__form-title', formTitle));
        if (formSubtitle !== '') {
            nodes.form.appendChild(create('div', 'kk-quiz__form-subtitle', formSubtitle));
        }

        const fields = toArray(quiz.form_fields).filter((field) => Object.prototype.hasOwnProperty.call(FIELD_LABELS, field));
        const requiredFields = toArray(quiz.required_fields);
        const visibleFields = fields.length > 0 ? fields : ['name', 'phone', 'email'];
        const visibleRequiredFields = requiredFields.filter((field) => visibleFields.includes(field));
        const form = create('form', 'kk-quiz__form-fields');
        const honeypot = document.createElement('input');
        honeypot.type = 'text';
        honeypot.name = 'website';
        honeypot.tabIndex = -1;
        honeypot.autocomplete = 'off';
        honeypot.hidden = true;
        form.appendChild(honeypot);

        visibleFields.forEach((field) => {
            const label = create('label', 'kk-quiz__field');
            label.appendChild(create('span', 'kk-quiz__field-label', FIELD_LABELS[field]));

            const input = field === 'comment' ? document.createElement('textarea') : document.createElement('input');
            input.className = 'kk-quiz__input';
            input.name = field;
            input.required = visibleRequiredFields.includes(field);
            input.type = field === 'email' ? 'email' : field === 'phone' ? 'tel' : 'text';
            if (field === 'phone') {
                input.inputMode = 'tel';
                input.autocomplete = 'tel';
                input.placeholder = '+7 (999) 123-45-67';
                input.addEventListener('keydown', (event) => {
                    if (event.key !== 'Backspace') {
                        return;
                    }

                    const selectionStart = input.selectionStart || 0;
                    const selectionEnd = input.selectionEnd || 0;
                    if (selectionStart !== selectionEnd) {
                        return;
                    }

                    if (selectionStart !== input.value.length) {
                        return;
                    }

                    event.preventDefault();
                    input.value = removeLastPhoneDigit(input.value);
                    state.fields[field] = input.value;

                    requestAnimationFrame(() => {
                        input.setSelectionRange(input.value.length, input.value.length);
                    });
                });
                input.addEventListener('input', () => {
                    input.value = formatPhoneInput(input.value);
                    state.fields[field] = input.value;
                });
            } else {
                input.addEventListener('input', () => {
                    state.fields[field] = input.value;
                });
            }

            label.appendChild(input);
            form.appendChild(label);
        });

        const agreementField = buildAgreementField(quiz);
        if (agreementField) {
            form.appendChild(agreementField);
        }

        const submit = create('button', 'kk-quiz__button', formButtonText);
        submit.type = 'submit';
        form.appendChild(submit);

        const message = create('div', 'kk-quiz__success');
        message.hidden = true;

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            message.hidden = true;
            message.textContent = '';

            const agreementInput = form.querySelector('input[name="agreement"]');
            const agreementAccepted = !agreementInput || agreementInput.checked;
            if (!agreementAccepted) {
                message.className = 'kk-quiz__error';
                message.textContent = 'Необходимо согласие с политикой обработки персональных данных.';
                message.hidden = false;
                return;
            }

            submit.disabled = true;

            const formData = new FormData(form);
            const payloadFields = {};
            visibleFields.forEach((field) => {
                payloadFields[field] = String(formData.get(field) || '');
                state.fields[field] = payloadFields[field];
            });

            const payload = {
                quiz_code: quiz.code,
                result_id: currentResult ? currentResult.id : null,
                result_code: currentResult ? currentResult.code : '',
                result_title: currentResult ? currentResult.name : '',
                fields: payloadFields,
                answers: state.answers,
                page_url: window.location.href,
                referer: document.referrer,
                utm: getUtm(),
                website: honeypot.value,
                agreement_accepted: agreementAccepted
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
                        message.textContent = successText;
                        message.hidden = false;
                        sendMetrikaGoal(quiz, quiz.metrika.goal || 'kk_quiz_lead', {
                            quiz_code: quiz.code || '',
                            result_id: currentResult ? currentResult.id : '',
                            result_code: currentResult ? currentResult.code : '',
                            lead_id: result.lead_id || ''
                        });
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
        toArray(question.answers).forEach((answer, index) => {
            const button = create('button', 'kk-quiz__answer kk-quiz__answer--' + template);
            button.type = 'button';
            renderAnswerMedia(button, answer);
            renderAnswerText(button, answer);
            button.addEventListener('click', () => {
                state.answers[question.id] = buildAnswerPayload(answer, index);
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
            state.answers[question.id] = [...selected].map((index) => buildAnswerPayload(question.answers[index], index));
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
