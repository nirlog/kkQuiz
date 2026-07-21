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
    const OPTION_TYPES = ['radio'];
    const TEMPLATE_NAMES = ['image_cards', 'cards', 'list', 'select'];
    const loadedQuizzes = new Map();
    const loadingQuizzes = new Map();

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

    const buildCustomAnswerPayload = (input) => ({
        custom: true,
        value: String(input.value || '').trim()
    });

    const appendCustomAnswerInput = (container) => {
        const wrap = create('label', 'kk-quiz__field kk-quiz__field--custom-answer');
        const input = document.createElement('input');
        input.className = 'kk-quiz__input';
        input.type = 'text';
        input.placeholder = 'Введите свой вариант';
        wrap.hidden = true;
        wrap.appendChild(input);
        container.appendChild(wrap);

        return { wrap, input };
    };

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
        if (type === 'select') {
            return 'radio';
        }
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

    const getSessid = (node) => node && node.getAttribute('data-kk-quiz-sessid')
        ? node.getAttribute('data-kk-quiz-sessid')
        : (window.BX && BX.bitrix_sessid ? BX.bitrix_sessid() : '');

    const getAjaxUrl = (root, action = 'kk:quiz.api.submitLead') => {
        const sessid = getSessid(root);
        const params = new URLSearchParams({ action });
        if (sessid) {
            params.set('sessid', sessid);
        }
        return '/bitrix/services/main/ajax.php?' + params.toString();
    };

    const createRunId = () => {
        if (window.crypto && crypto.randomUUID) {
            return crypto.randomUUID().replace(/-/g, '');
        }

        return 'run_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 12);
    };

    const ensureRunId = (state) => {
        if (!state.runId) {
            state.runId = createRunId();
        }

        return state.runId;
    };

    const isTrackingDisabled = () => {
        const params = new URLSearchParams(window.location.search);
        const value = String(params.get('kkquiz_nostat') || '').toUpperCase();

        return value === 'Y' || value === '1' || value === 'YES' || value === 'TRUE';
    };

    const resetRunState = (state) => {
        state.runId = createRunId();
        state.answers = {};
        state.scores = {};
        state.fields = {};
        state.stepIndex = 0;
        state.analytics.firstAnswerSent = false;
        state.analytics.resultReachedSent = false;
        state.tracking.quizViewSent = false;
        state.tracking.quizOpenSent = false;
        state.tracking.quizStartSent = false;
        state.tracking.formShowSent = false;
        state.tracking.leadSuccessSent = false;
        state.tracking.resultShowCodes = {};
    };

    const trackQuizEvent = (root, eventType, data = {}) => {
        if (isTrackingDisabled()) {
            return;
        }

        if (!root || !eventType) {
            return;
        }

        const state = root.__kkQuizState || null;
        const quiz = root.__kkQuizData || {};
        const quizCode = String(root.getAttribute('data-kk-quiz-code') || quiz.code || '').trim();
        const runId = state ? ensureRunId(state) : (root.__kkQuizRunId || (root.__kkQuizRunId = createRunId()));

        if (quizCode === '' || runId === '') {
            return;
        }

        const payload = Object.assign({
            quiz_code: quizCode,
            quiz_section_id: quiz.id || '',
            event_type: eventType,
            run_id: runId
        }, data || {});

        fetch(getAjaxUrl(root, 'kk:quiz.api.trackEvent'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).catch(() => {});
    };

    const sendQuizView = (root) => {
        if (!root) {
            return;
        }

        const state = root.__kkQuizState || null;
        if (!state) {
            return;
        }

        if (state.tracking.quizViewSent) {
            return;
        }

        state.tracking.quizViewSent = true;
        trackQuizEvent(root, 'quiz_view');
    };

    const sendQuizOpen = (root) => {
        const state = root && root.__kkQuizState ? root.__kkQuizState : null;
        if (!state) {
            return;
        }

        if (state.tracking.quizOpenSent) {
            return;
        }

        state.tracking.quizOpenSent = true;
        trackQuizEvent(root, 'quiz_open');
    };

    const isPopupRoot = (root) => {
        return !!(
            root
            && (
                root.hasAttribute('data-kk-quiz-popup-root')
                || root.classList.contains('kk-quiz--popup')
            )
        );
    };

    const observeQuizView = (root) => {
        if (!root) {
            return;
        }

        if (!('IntersectionObserver' in window)) {
            setTimeout(() => sendQuizView(root), 300);
            return;
        }

        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting && entry.intersectionRatio > 0) {
                    sendQuizView(root);
                    observer.disconnect();
                }
            });
        }, {
            threshold: 0.1
        });

        observer.observe(root);
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

    const sendGoogleAnalyticsEvent = (quiz, eventName, params = {}) => {
        if (!quiz || !quiz.google_analytics || quiz.google_analytics.enabled !== true) {
            return;
        }

        if (typeof window.gtag !== 'function') {
            return;
        }

        const event = String(eventName || '').trim();
        if (event === '') {
            return;
        }

        const measurementId = String(quiz.google_analytics.measurement_id || '').trim();

        const eventParams = {
            event_category: 'kk_quiz',
            event_type: params.event_type || '',
            quiz_code: params.quiz_code || '',
            question_id: params.question_id || '',
            question_code: params.question_code || '',
            result_id: params.result_id || '',
            result_code: params.result_code || '',
            lead_id: params.lead_id || '',
            cta_text: params.cta_text || '',
            cta_link: params.cta_link || '',
            product_id: params.product_id || '',
            product_name: params.product_name || '',
            product_url: params.product_url || ''
        };

        if (measurementId !== '') {
            eventParams.send_to = measurementId;
        }

        try {
            window.gtag('event', event, eventParams);
        } catch (error) {
            // Ошибка GA4 не должна ломать квиз.
        }
    };

    const getMetrikaGoal = (quiz, eventType) => {
        const goals = quiz && quiz.metrika && quiz.metrika.goals ? quiz.metrika.goals : {};
        const goal = String(goals[eventType] || '').trim();

        if (goal !== '') {
            return goal;
        }

        if (eventType === 'form_submit') {
            return String(quiz && quiz.metrika ? quiz.metrika.goal || '' : '').trim() || 'kk_quiz_lead';
        }

        if (eventType === 'first_answer') {
            return 'kk_quiz_first_answer';
        }

        if (eventType === 'result_reached') {
            return 'kk_quiz_result_reached';
        }

        if (eventType === 'result_cta_click') {
            return 'kk_quiz_result_cta_click';
        }

        if (eventType === 'product_click') {
            return 'kk_quiz_recommendation_click';
        }

        return '';
    };

    const getGoogleAnalyticsEventName = (quiz, eventType) => {
        const events = quiz && quiz.google_analytics && quiz.google_analytics.events ? quiz.google_analytics.events : {};
        const eventName = String(events[eventType] || '').trim();

        if (eventName !== '') {
            return eventName;
        }

        if (eventType === 'form_submit') {
            return String(quiz && quiz.google_analytics ? quiz.google_analytics.event_name || '' : '').trim() || 'generate_lead';
        }

        if (eventType === 'first_answer') {
            return 'kk_quiz_first_answer';
        }

        if (eventType === 'result_reached') {
            return 'kk_quiz_result_reached';
        }

        if (eventType === 'result_cta_click') {
            return 'kk_quiz_result_cta_click';
        }

        if (eventType === 'product_click') {
            return 'kk_quiz_recommendation_click';
        }

        return '';
    };

    const sendAnalyticsEvent = (quiz, eventType, params = {}) => {
        const eventParams = {
            event_type: eventType,
            quiz_code: params.quiz_code || '',
            question_id: params.question_id || '',
            question_code: params.question_code || '',
            result_id: params.result_id || '',
            result_code: params.result_code || '',
            lead_id: params.lead_id || '',
            cta_text: params.cta_text || '',
            cta_link: params.cta_link || '',
            product_id: params.product_id || '',
            product_name: params.product_name || '',
            product_url: params.product_url || ''
        };

        sendMetrikaGoal(quiz, getMetrikaGoal(quiz, eventType), eventParams);
        sendGoogleAnalyticsEvent(quiz, getGoogleAnalyticsEventName(quiz, eventType), eventParams);
    };

    const hasQuestionAnswer = (state, question, answer) => {
        if (answer) {
            return true;
        }

        const value = state.answers[question.id];
        if (Array.isArray(value)) {
            return value.length > 0;
        }

        return String(value || '').trim() !== '';
    };

    const getAnswerTrackingCode = (item) => {
        if (!item || typeof item !== 'object') {
            return '';
        }

        if (item.custom) {
            return 'custom';
        }

        const code = String(item.code || '').trim();
        if (code !== '') {
            return code;
        }

        const index = Number.parseInt(item.index, 10);
        if (Number.isInteger(index) && index >= 0) {
            return 'answer_' + String(index + 1);
        }

        return '';
    };

    const getTrackedAnswerCodes = (state, question) => {
        const value = state.answers[question.id];
        if (Array.isArray(value)) {
            return value
                .map(getAnswerTrackingCode)
                .filter((code) => code !== '');
        }

        if (value && typeof value === 'object') {
            const code = getAnswerTrackingCode(value);
            return code !== '' ? [code] : [];
        }

        if (String(value || '').trim() !== '') {
            return ['custom'];
        }

        return [];
    };

    const buildState = () => ({
        runId: createRunId(),
        stepIndex: 0,
        answers: {},
        scores: {},
        fields: {},
        analytics: {
            firstAnswerSent: false,
            resultReachedSent: false
        },
        tracking: {
            quizViewSent: false,
            quizOpenSent: false,
            quizStartSent: false,
            formShowSent: false,
            leadSuccessSent: false,
            resultShowCodes: {}
        }
    });

    const appendTextBlock = (container, className, text) => {
        if (text === undefined || text === null || String(text) === '') {
            return;
        }
        container.appendChild(create('div', className, text));
    };

    const renderProgress = (quiz, state) => {
        const technicalCount = Array.isArray(quiz.questions) ? quiz.questions.length : 0;
        const configuredTotal = Number(quiz.progress_total || 0);
        const questionsCount = configuredTotal > 0 ? configuredTotal : technicalCount;
        if (questionsCount <= 0) {
            return null;
        }

        const current = Math.min(Math.max(Number(state.stepIndex || 1), 1), questionsCount);
        const progress = create('div', 'kk-quiz__progress');
        progress.setAttribute('aria-label', 'Прогресс прохождения квиза');
        progress.appendChild(create('div', 'kk-quiz__progress-label', 'Вопрос ' + current + ' из ' + questionsCount));

        const track = create('div', 'kk-quiz__progress-track');
        const bar = create('div', 'kk-quiz__progress-bar');
        bar.style.width = String(Math.round((current / questionsCount) * 100)) + '%';
        track.appendChild(bar);
        progress.appendChild(track);

        return progress;
    };

    const hideAll = (nodes) => {
        nodes.start.hidden = true;
        nodes.question.hidden = true;
        nodes.form.hidden = true;
        nodes.result.hidden = true;
    };

    const getQuizCode = (root, quiz) => String(root.getAttribute('data-kk-quiz-code') || quiz.code || '').trim();

    const findPopupRoot = (quizCode) => Array.from(document.querySelectorAll('[data-kk-quiz-popup-root]'))
        .find((root) => String(root.getAttribute('data-kk-quiz-code') || '').trim() === quizCode) || null;

    const updatePopupLock = () => {
        const hasOpenPopup = document.querySelector('[data-kk-quiz-popup-root].kk-quiz--popup-open') !== null;
        document.body.classList.toggle('kk-quiz-popup-lock', hasOpenPopup);
    };

    const openPopup = (root) => {
        if (!root) {
            return;
        }

        root.hidden = false;
        root.classList.add('kk-quiz--popup-open');
        updatePopupLock();
        sendQuizView(root);
        sendQuizOpen(root);

        const focusTarget = root.querySelector('[data-kk-quiz-popup-close]')
            || root.querySelector('[data-kk-quiz-start-button]')
            || root.querySelector('button, a, input, select, textarea, [tabindex]:not([tabindex="-1"])')
            || root.querySelector('.kk-quiz__popup-card');

        if (focusTarget && typeof focusTarget.focus === 'function') {
            try {
                focusTarget.focus({ preventScroll: true });
            } catch (error) {
                focusTarget.focus();
            }
        }
    };

    const closePopup = (root) => {
        if (!root) {
            return;
        }

        root.classList.remove('kk-quiz--popup-open');
        root.hidden = true;
        updatePopupLock();
    };

    const getLoaderNode = () => document.querySelector('[data-kk-quiz-loader]');

    const getLoaderSessid = () => getSessid(getLoaderNode());

    const normalizeQuizCode = (quizCode) => String(quizCode || '').trim();

    const isValidQuizCode = (quizCode) => /^[a-zA-Z0-9_-]+$/.test(quizCode);

    const warnPopupError = (error) => {
        if (window.console && typeof window.console.warn === 'function') {
            window.console.warn('KK Quiz popup was not opened:', error.message || error);
        }
    };

    const createPopupRootFromQuiz = (quiz, sessid) => {
        const quizCode = String(quiz.code || '').trim();
        const root = document.createElement('div');
        root.className = 'kk-quiz kk-quiz--popup';
        root.setAttribute('data-kk-quiz', '');
        root.setAttribute('data-kk-quiz-popup-root', '');
        root.setAttribute('data-kk-quiz-code', quizCode);
        root.setAttribute('data-kk-quiz-sessid', sessid || '');
        root.hidden = true;

        const card = document.createElement('div');
        card.className = 'kk-quiz__popup-card';
        card.setAttribute('role', 'dialog');
        card.setAttribute('aria-modal', 'true');
        card.setAttribute('aria-label', String(quiz.title || 'Квиз'));
        card.tabIndex = -1;

        const close = document.createElement('button');
        close.className = 'kk-quiz__popup-close';
        close.type = 'button';
        close.setAttribute('data-kk-quiz-popup-close', '');
        close.setAttribute('aria-label', 'Закрыть');
        close.textContent = '×';
        card.appendChild(close);

        const start = create('div', 'kk-quiz__start');
        start.setAttribute('data-kk-quiz-start', '');
        if (String(quiz.title || '') !== '') {
            start.appendChild(create('h2', 'kk-quiz__title', quiz.title));
        }
        if (String(quiz.subtitle || '') !== '') {
            start.appendChild(create('div', 'kk-quiz__subtitle', quiz.subtitle));
        }
        if (String(quiz.start_text || '') !== '') {
            start.appendChild(create('div', 'kk-quiz__start-text', quiz.start_text));
        }
        const startButton = create('button', 'kk-quiz__button', String(quiz.button_text || '').trim() || 'Начать');
        startButton.type = 'button';
        startButton.setAttribute('data-kk-quiz-start-button', '');
        start.appendChild(startButton);

        const question = create('div', 'kk-quiz__question');
        question.setAttribute('data-kk-quiz-question', '');
        question.hidden = true;

        const form = create('div', 'kk-quiz__form');
        form.setAttribute('data-kk-quiz-form', '');
        form.hidden = true;

        const result = create('div', 'kk-quiz__result');
        result.setAttribute('data-kk-quiz-result', '');
        result.hidden = true;

        const data = document.createElement('script');
        data.type = 'application/json';
        data.setAttribute('data-kk-quiz-data', '');
        data.textContent = JSON.stringify(quiz);

        card.appendChild(start);
        card.appendChild(question);
        card.appendChild(form);
        card.appendChild(result);
        card.appendChild(data);
        root.appendChild(card);

        return root;
    };

    const loadQuizByCode = (quizCode) => {
        const normalizedCode = normalizeQuizCode(quizCode);
        if (!isValidQuizCode(normalizedCode)) {
            return Promise.reject(new Error('INVALID_QUIZ_CODE'));
        }

        if (loadedQuizzes.has(normalizedCode)) {
            return Promise.resolve(loadedQuizzes.get(normalizedCode));
        }

        if (loadingQuizzes.has(normalizedCode)) {
            return loadingQuizzes.get(normalizedCode);
        }

        const loader = getLoaderNode();
        const request = fetch(getAjaxUrl(loader, 'kk:quiz.api.getQuiz'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ quizCode: normalizedCode })
        })
            .then((response) => response.json())
            .then((data) => {
                const result = normalizeAjaxResponse(data);
                if (!result || result.success !== true || !result.quiz) {
                    const message = result && Array.isArray(result.errors) && result.errors.length > 0
                        ? result.errors.join(', ')
                        : 'QUIZ_NOT_FOUND';
                    throw new Error(message);
                }

                loadedQuizzes.set(normalizedCode, result.quiz);
                return result.quiz;
            })
            .finally(() => {
                loadingQuizzes.delete(normalizedCode);
            });

        loadingQuizzes.set(normalizedCode, request);
        return request;
    };

    const openQuizPopupByCode = (quizCode) => {
        const normalizedCode = normalizeQuizCode(quizCode);
        if (!isValidQuizCode(normalizedCode)) {
            warnPopupError(new Error('INVALID_QUIZ_CODE'));
            return Promise.resolve(null);
        }

        const existingRoot = findPopupRoot(normalizedCode);
        if (existingRoot) {
            openPopup(existingRoot);
            return Promise.resolve(existingRoot);
        }

        if (!getLoaderNode()) {
            warnPopupError(new Error('QUIZ_POPUP_LOADER_NOT_FOUND'));
            return Promise.resolve(null);
        }

        return loadQuizByCode(normalizedCode)
            .then((quiz) => {
                const root = findPopupRoot(normalizedCode) || createPopupRootFromQuiz(quiz, getLoaderSessid());
                if (!root.parentNode) {
                    document.body.appendChild(root);
                }
                initQuizRoot(root);
                openPopup(root);
                return root;
            })
            .catch((error) => {
                warnPopupError(error);
                return null;
            });
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

        if (!state.tracking.formShowSent) {
            state.tracking.formShowSent = true;
            trackQuizEvent(nodes.root, 'form_show', {
                step_index: state.stepIndex,
                result_id: currentResult ? currentResult.id : '',
                result_code: currentResult ? currentResult.code : ''
            });
        }

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

            if (input.tagName === 'INPUT') {
                input.type = field === 'email' ? 'email' : field === 'phone' ? 'tel' : 'text';
            }

            if (field === 'comment') {
                input.rows = 4;
            }

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
        const submitDefaultText = formButtonText;
        const submitLoadingText = 'Отправляется...';
        form.appendChild(submit);

        const message = create('div', 'kk-quiz__success');
        message.setAttribute('aria-live', 'polite');
        message.hidden = true;

        form.addEventListener('submit', (event) => {
            event.preventDefault();

            if (submit.disabled) {
                return;
            }

            message.hidden = true;
            message.textContent = '';

            const agreementInput = form.querySelector('input[name="agreement"]');
            const agreementAccepted = !agreementInput || agreementInput.checked;
            if (!agreementAccepted) {
                message.className = 'kk-quiz__error';
                message.textContent = 'Необходимо согласие с политикой обработки персональных данных.';
                message.hidden = false;
                if (agreementInput) {
                    agreementInput.setAttribute('aria-invalid', 'true');
                    const agreementWrap = agreementInput.closest('.kk-quiz__agreement');
                    if (agreementWrap) {
                        agreementWrap.classList.add('is-error');
                    }
                    agreementInput.focus();
                }
                return;
            }
            if (agreementInput) {
                agreementInput.removeAttribute('aria-invalid');
                const agreementWrap = agreementInput.closest('.kk-quiz__agreement');
                if (agreementWrap) {
                    agreementWrap.classList.remove('is-error');
                }
            }

            submit.disabled = true;
            submit.textContent = submitLoadingText;
            submit.classList.add('kk-quiz__button--loading');

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
                scores: state.scores,
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
                        const analyticsParams = {
                            quiz_code: quiz.code || '',
                            step_index: state.stepIndex,
                            result_id: currentResult ? currentResult.id : '',
                            result_code: currentResult ? currentResult.code : '',
                            lead_id: result.lead_id || ''
                        };

                        sendAnalyticsEvent(quiz, 'form_submit', analyticsParams);
                        if (!state.tracking.leadSuccessSent) {
                            state.tracking.leadSuccessSent = true;
                            trackQuizEvent(nodes.root, 'lead_success', analyticsParams);
                        }
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
                    if (!form.hidden) {
                        submit.disabled = false;
                        submit.textContent = submitDefaultText;
                        submit.classList.remove('kk-quiz__button--loading');
                    }
                });
        });

        nodes.form.appendChild(form);
        nodes.form.appendChild(message);
    };

    const renderResultProducts = (quiz, result) => {
        const products = Array.isArray(result.products) ? result.products : [];
        if (products.length === 0) {
            return null;
        }

        const wrapper = create('div', 'kk-quiz__products');
        wrapper.appendChild(create('h3', 'kk-quiz__products-title', 'Подходящие варианты'));

        const grid = create('div', 'kk-quiz__products-grid');

        products.forEach((product) => {
            const card = create('a', 'kk-quiz__product-card');
            card.href = String(product.url || '#');

            if (product.url) {
                card.target = '_blank';
                card.rel = 'noopener noreferrer';
            }

            card.addEventListener('click', () => {
                sendAnalyticsEvent(quiz, 'product_click', {
                    quiz_code: quiz.code || '',
                    result_id: result.id || '',
                    result_code: result.code || '',
                    product_id: product.id || '',
                    product_name: product.name || '',
                    product_url: product.url || ''
                });
            });

            if (product.picture_src) {
                const image = document.createElement('img');
                image.className = 'kk-quiz__product-image';
                image.src = String(product.picture_src);
                image.alt = String(product.name || '');
                card.appendChild(image);
            }

            card.appendChild(create('div', 'kk-quiz__product-name', product.name || 'Вариант'));

            const linkText = create('div', 'kk-quiz__product-link', 'Подробнее');
            card.appendChild(linkText);

            grid.appendChild(card);
        });

        wrapper.appendChild(grid);

        return wrapper;
    };

    const normalizeResultVideoPosition = (position) => {
        const normalized = String(position || '').trim();
        return ['after_text', 'before_form', 'after_form', 'before_products'].includes(normalized)
            ? normalized
            : 'after_text';
    };

    const renderResultVideo = (video) => {
        if (!video || typeof video !== 'object') {
            return null;
        }

        const url = String(video.url || '').trim();
        const embedUrl = String(video.embedUrl || '').trim();
        const type = String(video.type || '').trim();
        if (url === '') {
            return null;
        }

        const wrapper = create('div', 'kk-quiz-result-video');
        const title = String(video.title || '').trim();
        if (title !== '') {
            wrapper.appendChild(create('div', 'kk-quiz-result-video__title', title));
        }

        if (type === 'iframe' && embedUrl !== '') {
            const frame = create('div', 'kk-quiz-result-video__frame');
            const iframe = document.createElement('iframe');
            iframe.src = embedUrl;
            iframe.loading = 'lazy';
            iframe.allowFullscreen = true;
            iframe.referrerPolicy = 'strict-origin-when-cross-origin';
            iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
            frame.appendChild(iframe);
            wrapper.appendChild(frame);

            return wrapper;
        }

        if (type === 'video') {
            const frame = create('div', 'kk-quiz-result-video__frame');
            const videoNode = document.createElement('video');
            videoNode.controls = true;
            videoNode.preload = 'metadata';
            videoNode.src = url;
            frame.appendChild(videoNode);
            wrapper.appendChild(frame);

            return wrapper;
        }

        const link = create('a', 'kk-quiz-result-video__link', title || 'Открыть видео');
        link.href = url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        wrapper.appendChild(link);

        return wrapper;
    };

    const getResultLines = (result, itemsKey, textKey) => {
        if (Array.isArray(result[itemsKey])) {
            return result[itemsKey].map((item) => String(item || '').trim()).filter((item) => item !== '');
        }

        return String(result[textKey] || '')
            .split(/\r?\n/)
            .map((item) => item.trim())
            .filter((item) => item !== '');
    };

    const hasEnhancedResultContent = (result) => {
        return ['summary', 'why_text', 'specs_text', 'note_text', 'form_title', 'form_intro', 'form_button_text'].some((key) => String(result[key] || '').trim() !== '')
            || getResultLines(result, 'why_items', 'why_text').length > 0
            || getResultLines(result, 'specs_items', 'specs_text').length > 0;
    };

    const renderResultSection = (title, items, extraClassName) => {
        if (!Array.isArray(items) || items.length === 0) {
            return null;
        }

        const section = create('section', 'kk-quiz__result-section' + (extraClassName ? ' ' + extraClassName : ''));
        section.appendChild(create('h4', 'kk-quiz__result-section-title', title));

        const list = create('ul', 'kk-quiz__result-list');
        items.forEach((item) => {
            list.appendChild(create('li', '', item));
        });
        section.appendChild(list);

        return section;
    };

    const renderResultNote = (text) => {
        const noteText = String(text || '').trim();
        if (noteText === '') {
            return null;
        }

        const note = create('section', 'kk-quiz__result-section kk-quiz__result-note');
        note.appendChild(create('h4', 'kk-quiz__result-section-title', 'Что важно учесть'));
        note.appendChild(create('div', 'kk-quiz__result-note-text', noteText));

        return note;
    };

    const renderResultFormHelp = (nodes, quiz, state, result) => {
        const help = create('div', 'kk-quiz__result-help');
        help.appendChild(create(
            'h3',
            'kk-quiz__result-help-title',
            String(result.form_title || '').trim() || 'Хотите точнее?'
        ));
        help.appendChild(create(
            'div',
            'kk-quiz__result-help-text',
            String(result.form_intro || '').trim() || 'Отправьте результат специалисту — он проверит наличие и предложит 2–3 подходящих варианта.'
        ));

        const toggle = create(
            'button',
            'kk-quiz__button kk-quiz__button--secondary kk-quiz__result-form-toggle',
            String(result.form_button_text || '').trim() || 'Отправить результат специалисту'
        );
        toggle.type = 'button';

        const formWrap = create('div', 'kk-quiz__result-form');
        formWrap.hidden = true;

        toggle.addEventListener('click', () => {
            toggle.hidden = true;
            formWrap.hidden = false;

            const originalForm = nodes.form;
            nodes.form = formWrap;
            showFinalForm(nodes, quiz, state, result);
            nodes.result.hidden = false;
            nodes.form = originalForm;
        });

        help.appendChild(toggle);
        help.appendChild(formWrap);

        return help;
    };

    const showResult = (nodes, quiz, state, resultId) => {
        const result = findById(quiz.results, resultId);
        if (!result) {
            showFinalForm(nodes, quiz, state, null);
            return;
        }

        if (!state.analytics.resultReachedSent) {
            state.analytics.resultReachedSent = true;

            sendAnalyticsEvent(quiz, 'result_reached', {
                quiz_code: quiz.code || '',
                result_id: result.id || '',
                result_code: result.code || ''
            });
        }

        const resultCode = String(result.code || result.id || '');
        if (!state.tracking.resultShowCodes[resultCode]) {
            state.tracking.resultShowCodes[resultCode] = true;
            trackQuizEvent(nodes.root, 'result_show', {
                step_index: state.stepIndex,
                result_id: result.id || '',
                result_code: result.code || ''
            });
        }

        hideAll(nodes);
        clear(nodes.result);
        nodes.result.hidden = false;

        const enhancedResult = hasEnhancedResultContent(result);
        const summaryText = String(result.summary || '').trim() || String(result.preview_text || '').trim();
        const whyItems = getResultLines(result, 'why_items', 'why_text');
        const specsItems = getResultLines(result, 'specs_items', 'specs_text');

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
        appendTextBlock(card, enhancedResult ? 'kk-quiz__result-summary' : 'kk-quiz__result-text', summaryText);

        const whySection = renderResultSection('Почему подходит', whyItems, 'kk-quiz__result-why');
        if (whySection) {
            card.appendChild(whySection);
        }

        const specsSection = renderResultSection('Ориентир по комплектующим', specsItems, 'kk-quiz__result-specs');
        if (specsSection) {
            card.appendChild(specsSection);
        }

        const noteSection = renderResultNote(result.note_text);
        if (noteSection) {
            card.appendChild(noteSection);
        }

        const videoBlock = renderResultVideo(result.video);
        const videoPosition = normalizeResultVideoPosition(result.video ? result.video.position : '');
        if (videoBlock && videoPosition === 'after_text') {
            card.appendChild(videoBlock);
        }

        if (result.cta_text && result.cta_link) {
            const actions = create('div', 'kk-quiz__result-actions');
            const link = create('a', 'kk-quiz__button kk-quiz__button--link kk-quiz__result-catalog-link', result.cta_text);
            link.href = String(result.cta_link);

            link.addEventListener('click', () => {
                sendAnalyticsEvent(quiz, 'result_cta_click', {
                    quiz_code: quiz.code || '',
                    result_id: result.id || '',
                    result_code: result.code || '',
                    cta_text: result.cta_text || '',
                    cta_link: result.cta_link || ''
                });
            });

            actions.appendChild(link);
            card.appendChild(actions);
        }

        nodes.result.appendChild(card);

        if (videoBlock && videoPosition === 'before_products') {
            nodes.result.appendChild(videoBlock);
        }

        const productsBlock = renderResultProducts(quiz, result);
        if (productsBlock) {
            nodes.result.appendChild(productsBlock);
        }

        if (result.show_form === true) {
            if (videoBlock && videoPosition === 'before_form') {
                nodes.result.appendChild(videoBlock);
            }

            if (enhancedResult) {
                nodes.result.appendChild(renderResultFormHelp(nodes, quiz, state, result));
            } else {
                const formWrap = create('div', 'kk-quiz__result-form');
                nodes.result.appendChild(formWrap);
                const originalForm = nodes.form;
                nodes.form = formWrap;
                showFinalForm(nodes, quiz, state, result);
                nodes.result.hidden = false;
                nodes.form = originalForm;
            }

            if (videoBlock && videoPosition === 'after_form') {
                nodes.result.appendChild(videoBlock);
            }
        } else if (videoBlock && (videoPosition === 'before_form' || videoPosition === 'after_form')) {
            nodes.result.appendChild(videoBlock);
        }
    };

    const addAnswerScores = (scores, answers) => {
        toArray(answers).forEach((answer) => {
            const resultId = toId(answer && answer.score_result_id);
            const scoreValue = Number(answer && answer.score_value);
            if (resultId === null || !Number.isFinite(scoreValue) || scoreValue === 0) {
                return;
            }

            const key = String(resultId);
            scores[key] = Number(scores[key] || 0) + scoreValue;
        });
    };

    const findScoredResult = (quiz, scores) => {
        const candidates = toArray(quiz.results).filter((result) => {
            const score = Number(scores[String(toId(result.id))] || 0);
            if (score <= 0) {
                return false;
            }

            const minScore = result.min_score === null || result.min_score === undefined || result.min_score === ''
                ? null
                : Number(result.min_score);
            const maxScore = result.max_score === null || result.max_score === undefined || result.max_score === ''
                ? null
                : Number(result.max_score);

            return (minScore === null || score >= minScore) && (maxScore === null || score <= maxScore);
        });

        candidates.sort((left, right) => {
            const scoreDifference = Number(scores[String(toId(right.id))] || 0) - Number(scores[String(toId(left.id))] || 0);
            if (scoreDifference !== 0) {
                return scoreDifference;
            }

            return Number(left.priority || 0) - Number(right.priority || 0);
        });

        return candidates[0] || null;
    };

    const goNext = (nodes, quiz, state, question, answer) => {
        const selectedAnswers = Array.isArray(answer) ? answer : (answer ? [answer] : []);
        addAnswerScores(state.scores, selectedAnswers);

        sendQuizView(nodes.root);
        sendQuizOpen(nodes.root);

        if (
            !state.analytics.firstAnswerSent
            && question
            && toId(question.id) === toId(quiz.first_question_id)
            && hasQuestionAnswer(state, question, answer)
        ) {
            state.analytics.firstAnswerSent = true;

            sendAnalyticsEvent(quiz, 'first_answer', {
                quiz_code: quiz.code || '',
                question_id: question.id || '',
                question_code: question.code || ''
            });
        }

        if (
            !state.tracking.quizStartSent
            && question
            && toId(question.id) === toId(quiz.first_question_id)
            && hasQuestionAnswer(state, question, answer)
        ) {
            state.tracking.quizStartSent = true;
            trackQuizEvent(nodes.root, 'quiz_start', {
                step_index: state.stepIndex,
                question_id: question.id || '',
                question_code: question.code || ''
            });
        }

        if (question) {
            const answerCodes = getTrackedAnswerCodes(state, question);
            answerCodes.forEach((answerCode) => {
                trackQuizEvent(nodes.root, 'question_answer', {
                    step_index: state.stepIndex,
                    question_id: question.id || '',
                    question_code: question.code || '',
                    answer_code: answerCode
                });
            });
        }

        const resultAnswer = selectedAnswers.find((item) => item.result_id);
        if (resultAnswer) {
            showResult(nodes, quiz, state, resultAnswer.result_id);
            return;
        }

        const nextAnswer = selectedAnswers.find((item) => item.next_question_id);
        const nextQuestionId = nextAnswer ? nextAnswer.next_question_id : question.default_next_question_id;
        if (nextQuestionId) {
            showQuestion(nodes, quiz, state, nextQuestionId);
            return;
        }

        const scoredResult = findScoredResult(quiz, state.scores);
        if (scoredResult) {
            showResult(nodes, quiz, state, scoredResult.id);
            return;
        }

        if (question.default_result_id) {
            showResult(nodes, quiz, state, question.default_result_id);
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

        state.stepIndex += 1;
        trackQuizEvent(nodes.root, 'question_show', {
            step_index: state.stepIndex,
            question_id: question.id || '',
            question_code: question.code || ''
        });

        hideAll(nodes);
        clear(nodes.question);
        nodes.question.hidden = false;

        const type = getQuestionType(question);
        const template = getDisplayTemplate(question);

        const progress = renderProgress(quiz, state);
        if (progress) {
            nodes.question.appendChild(progress);
        }

        nodes.question.appendChild(create('h3', 'kk-quiz__question-title', question.name));
        if (question.is_required === true) {
            nodes.question.appendChild(create('div', 'kk-quiz__required-badge', 'Обязательный вопрос'));
        }
        appendTextBlock(nodes.question, 'kk-quiz__question-hint', question.hint);

        if (type === 'radio') {
            if (template === 'select') {
                renderSelectChoice(nodes, quiz, state, question);
                return;
            }

            renderSingleChoice(nodes, quiz, state, question, template);
            return;
        }

        if (type === 'checkbox') {
            renderCheckboxes(nodes, quiz, state, question, template === 'select' ? 'list' : template);
            return;
        }

        renderInputQuestion(nodes, quiz, state, question, type);
    }

    const renderSelectChoice = (nodes, quiz, state, question) => {
        const wrapper = create('div', 'kk-quiz__select-wrap');

        const select = document.createElement('select');
        select.className = 'kk-quiz__input';
        select.required = question.is_required === true;

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Выберите вариант';
        placeholder.disabled = true;
        placeholder.selected = true;
        select.appendChild(placeholder);

        toArray(question.answers).forEach((answer, index) => {
            const option = document.createElement('option');
            option.value = String(index);
            option.textContent = String(answer.text || '');
            select.appendChild(option);
        });

        if (question.allow_custom_answer === true) {
            const customOption = document.createElement('option');
            customOption.value = '__custom__';
            customOption.textContent = 'Свой вариант ответа';
            select.appendChild(customOption);
        }

        wrapper.appendChild(select);

        const custom = question.allow_custom_answer === true
            ? appendCustomAnswerInput(wrapper)
            : { wrap: null, input: null };
        select.addEventListener('change', () => {
            if (!custom.wrap) return;
            custom.wrap.hidden = select.value !== '__custom__';
            if (custom.wrap.hidden) {
                custom.input.value = '';
            } else {
                custom.input.focus();
            }
        });

        const next = create('button', 'kk-quiz__button kk-quiz__button--next', 'Далее');
        next.type = 'button';

        next.addEventListener('click', () => {
            if (select.value === '__custom__') {
                if (custom.input.value.trim() === '') {
                    custom.input.focus();
                    return;
                }

                state.answers[question.id] = buildCustomAnswerPayload(custom.input);
                goNext(nodes, quiz, state, question, null);
                return;
            }

            const index = Number.parseInt(select.value, 10);
            if (!Number.isInteger(index) || !question.answers[index]) {
                select.focus();
                return;
            }

            const answer = question.answers[index];
            state.answers[question.id] = buildAnswerPayload(answer, index);
            goNext(nodes, quiz, state, question, answer);
        });

        nodes.question.appendChild(wrapper);
        nodes.question.appendChild(next);
    };

    const renderSingleChoice = (nodes, quiz, state, question, template) => {
        const answers = create('div', 'kk-quiz__answers kk-quiz__answers--' + template);
        let customInput = null;
        let customNext = null;

        const deactivateAnswers = () => {
            answers.querySelectorAll('.kk-quiz__answer--active').forEach((element) => {
                element.classList.remove('kk-quiz__answer--active');
                element.classList.remove('is-selected');
            });
        };

        toArray(question.answers).forEach((answer, index) => {
            const button = create('button', 'kk-quiz__answer kk-quiz__answer--' + template);
            button.type = 'button';
            renderAnswerMedia(button, answer);
            renderAnswerText(button, answer);
            button.addEventListener('click', () => {
                state.answers[question.id] = buildAnswerPayload(answer, index);
                deactivateAnswers();
                button.classList.add('kk-quiz__answer--active');
                button.classList.add('is-selected');
                goNext(nodes, quiz, state, question, answer);
            });
            answers.appendChild(button);
        });

        if (question.allow_custom_answer === true) {
            const customButton = create('button', 'kk-quiz__answer kk-quiz__answer--' + template, 'Свой вариант ответа');
            customButton.type = 'button';
            customButton.addEventListener('click', () => {
                deactivateAnswers();
                customButton.classList.add('kk-quiz__answer--active');
                customButton.classList.add('is-selected');
                customInput.wrap.hidden = false;
                customNext.hidden = false;
                customInput.input.focus();
            });
            answers.appendChild(customButton);
        }

        nodes.question.appendChild(answers);

        if (question.allow_custom_answer === true) {
            customInput = appendCustomAnswerInput(nodes.question);
            customNext = create('button', 'kk-quiz__button kk-quiz__button--next', 'Далее');
            customNext.type = 'button';
            customNext.hidden = true;
            customNext.addEventListener('click', () => {
                if (customInput.input.value.trim() === '') {
                    customInput.input.focus();
                    return;
                }

                state.answers[question.id] = buildCustomAnswerPayload(customInput.input);
                goNext(nodes, quiz, state, question, null);
            });
            nodes.question.appendChild(customNext);
        }
    };

    const renderCheckboxes = (nodes, quiz, state, question, template) => {
        const selected = new Set();
        const answers = create('div', 'kk-quiz__answers kk-quiz__answers--' + template);
        let customInput = null;
        let customCheckbox = null;

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
                    label.classList.add('is-selected');
                } else {
                    selected.delete(index);
                    label.classList.remove('kk-quiz__answer--active');
                    label.classList.remove('is-selected');
                }
            });
            answers.appendChild(label);
        });

        if (question.allow_custom_answer === true) {
            const label = create('label', 'kk-quiz__answer kk-quiz__answer--' + template);
            customCheckbox = document.createElement('input');
            customCheckbox.type = 'checkbox';
            label.appendChild(customCheckbox);
            label.appendChild(create('span', 'kk-quiz__answer-text', 'Свой вариант ответа'));
            customCheckbox.addEventListener('change', () => {
                label.classList.toggle('kk-quiz__answer--active', customCheckbox.checked);
                label.classList.toggle('is-selected', customCheckbox.checked);
                customInput.wrap.hidden = !customCheckbox.checked;
                if (customCheckbox.checked) {
                    customInput.input.focus();
                } else {
                    customInput.input.value = '';
                }
            });
            answers.appendChild(label);
        }

        const next = create('button', 'kk-quiz__button kk-quiz__button--next', 'Далее');
        next.type = 'button';
        next.addEventListener('click', () => {
            const selectedIndexes = [...selected].sort((left, right) => left - right);
            const selectedAnswers = selectedIndexes.map((index) => question.answers[index]);
            const payload = selectedIndexes.map((index) => buildAnswerPayload(question.answers[index], index));
            if (customCheckbox && customCheckbox.checked) {
                if (customInput.input.value.trim() === '') {
                    customInput.input.focus();
                    return;
                }
                payload.push(buildCustomAnswerPayload(customInput.input));
            }

            if (question.is_required === true && payload.length === 0) {
                const firstInput = answers.querySelector('input[type="checkbox"]');
                if (firstInput) firstInput.focus();
                return;
            }

            state.answers[question.id] = payload;
            goNext(nodes, quiz, state, question, selectedAnswers);
        });

        nodes.question.appendChild(answers);
        if (question.allow_custom_answer === true) {
            customInput = appendCustomAnswerInput(nodes.question);
        }
        nodes.question.appendChild(next);
    };

    const renderInputQuestion = (nodes, quiz, state, question, type) => {
        const label = create('label', 'kk-quiz__field kk-quiz__field--question');
        const input = type === 'textarea' ? document.createElement('textarea') : document.createElement('input');
        input.className = 'kk-quiz__input';
        input.required = question.is_required === true;
        input.placeholder = String(question.placeholder || '');
        if (input.tagName === 'INPUT') {
            input.type = type === 'phone' ? 'tel' : type === 'email' ? 'email' : 'text';
        }
        if (type === 'textarea') {
            input.rows = 4;
        }
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

    const initQuizRoot = (root) => {
        if (!root || root.dataset.kkQuizInitialized === 'Y') {
            return;
        }

        root.dataset.kkQuizInitialized = 'Y';
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

        const quizCode = getQuizCode(root, quiz);
        if (quizCode !== '') {
            root.setAttribute('data-kk-quiz-code', quizCode);
        }

        if (root.hasAttribute('data-kk-quiz-popup-root') && quizCode !== '') {
            loadedQuizzes.set(quizCode, quiz);
            root.addEventListener('click', (event) => {
                if (event.target === root) {
                    closePopup(root);
                }
            });
            root.querySelectorAll('[data-kk-quiz-popup-close]').forEach((button) => {
                button.addEventListener('click', () => closePopup(root));
            });
        }

        const state = buildState();
        root.__kkQuizData = quiz;
        root.__kkQuizState = state;

        if (!isPopupRoot(root)) {
            observeQuizView(root);
        }

        const startButton = root.querySelector('[data-kk-quiz-start-button]');
        if (startButton) {
            startButton.addEventListener('click', () => {
                sendQuizView(root);
                sendQuizOpen(root);

                const firstQuestionId = toId(quiz.first_question_id);
                if (firstQuestionId) {
                    showQuestion(nodes, quiz, state, firstQuestionId);
                    return;
                }
                showFinalForm(nodes, quiz, state, null);
            });
        }
    };

    document.querySelectorAll('[data-kk-quiz]').forEach(initQuizRoot);

    document.addEventListener('click', (event) => {
        const target = event.target && event.target.nodeType === 1 ? event.target : (event.target ? event.target.parentElement : null);
        const trigger = target ? target.closest('[data-kk-quiz-popup]') : null;
        if (!trigger) {
            return;
        }

        event.preventDefault();

        const quizCode = String(trigger.getAttribute('data-kk-quiz-popup') || '').trim();
        if (quizCode === '') {
            return;
        }

        openQuizPopupByCode(quizCode);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        document.querySelectorAll('[data-kk-quiz-popup-root].kk-quiz--popup-open').forEach((root) => {
            closePopup(root);
        });
    });

    const params = new URLSearchParams(window.location.search);
    const quizCode = String(params.get('kkquiz') || '').trim();
    if (quizCode !== '') {
        openQuizPopupByCode(quizCode);
    }
}());
