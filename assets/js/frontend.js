(function () {
    'use strict';

    var config = window.MTSCompletePolls || {};
    var lastFocused = null;

    function qs(selector, context) {
        return (context || document).querySelector(selector);
    }

    function qsa(selector, context) {
        return Array.prototype.slice.call((context || document).querySelectorAll(selector));
    }

    function ajax(action, data) {
        var body = new URLSearchParams();
        body.append('action', action);
        body.append('nonce', config.nonce || '');

        Object.keys(data || {}).forEach(function (key) {
            var value = data[key];
            if (Array.isArray(value)) {
                value.forEach(function (item) {
                    body.append(key + '[]', item);
                });
            } else {
                body.append(key, value == null ? '' : value);
            }
        });

        return fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        }).then(function (response) {
            return response.json().then(function (json) {
                if (!response.ok || !json.success) {
                    var error = new Error(
                        json && json.data && json.data.message
                            ? json.data.message
                            : (config.strings && config.strings.error) || 'Something went wrong.'
                    );
                    error.payload = json && json.data ? json.data : {};
                    error.status = response.status;
                    throw error;
                }
                return json.data;
            });
        });
    }

    function modalFor(element) {
        var app = element.closest('.mts-cp-app');
        return app ? qs('.mts-cp-modal', app) : null;
    }

    function openModal(trigger, loadingText) {
        var modal = modalFor(trigger);
        if (!modal) {
            return null;
        }

        lastFocused = trigger;
        var content = qs('.mts-cp-modal-content', modal);
        content.innerHTML = '<div class="mts-cp-modal-loading"><span class="mts-cp-spinner" aria-hidden="true"></span><p>' + escapeHtml(loadingText || 'Loading…') + '</p></div>';
        modal.hidden = false;
        document.documentElement.classList.add('mts-cp-modal-open');
        document.body.classList.add('mts-cp-modal-open');
        var close = qs('.mts-cp-modal-close', modal);
        if (close) {
            window.setTimeout(function () { close.focus(); }, 10);
        }
        return modal;
    }

    function closeModal(modal) {
        if (!modal) {
            return;
        }
        modal.hidden = true;
        var content = qs('.mts-cp-modal-content', modal);
        if (content) {
            content.innerHTML = '';
        }
        document.documentElement.classList.remove('mts-cp-modal-open');
        document.body.classList.remove('mts-cp-modal-open');
        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
    }

    function showModalError(modal, message, loginUrl) {
        if (!modal) {
            return;
        }
        var content = qs('.mts-cp-modal-content', modal);
        content.innerHTML = '<div class="mts-cp-modal-panel"><div class="mts-cp-notice mts-cp-notice--error" role="alert">' + escapeHtml(message) + '</div>' +
            (loginUrl ? '<a class="mts-cp-primary-button" href="' + escapeAttribute(loginUrl) + '">Log In</a>' : '') + '</div>';
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function escapeAttribute(value) {
        return escapeHtml(value).replace(/`/g, '&#96;');
    }

    function updateOtherField(form) {
        var otherInput = qs('input[value="__other__"]', form);
        var field = qs('.mts-cp-other-field', form);
        if (!field || !otherInput) {
            return;
        }
        field.hidden = !otherInput.checked;
        var textInput = qs('input[name="other_text"]', field);
        if (textInput) {
            textInput.required = otherInput.checked;
            if (otherInput.checked) {
                window.setTimeout(function () { textInput.focus(); }, 10);
            } else {
                textInput.value = '';
            }
        }
    }

    function updatePollRow(app, pollId, voteCount) {
        var row = qs('.mts-cp-poll-row[data-poll-id="' + pollId + '"]', app);
        if (!row) {
            return;
        }
        var countNode = qs('.mts-cp-vote-count', row);
        if (countNode) {
            countNode.textContent = voteCount + (Number(voteCount) === 1 ? ' vote' : ' votes');
        }
        if (!qs('.mts-cp-answered-badge', row)) {
            var meta = qs('.mts-cp-poll-meta', row);
            if (meta) {
                var badge = document.createElement('span');
                badge.className = 'mts-cp-answered-badge';
                badge.textContent = '✓ Answered';
                meta.appendChild(badge);
            }
        }
    }

    function getFilterState(app) {
        return {
            category: app.dataset.category || '',
            keyword: app.dataset.keyword || '',
            sort: app.dataset.sort || 'default'
        };
    }

    function setActiveFilterText(app) {
        var state = getFilterState(app);
        var status = qs('.mts-cp-active-filter', app);
        if (!status) {
            return;
        }

        var bits = [];
        var activeCategory = qs('.mts-cp-category-button.is-active', app);
        if (state.category && activeCategory) {
            bits.push('Category: ' + activeCategory.textContent.trim());
        }
        if (state.keyword) {
            bits.push('Keyword: “' + state.keyword + '”');
        }
        if (state.sort === 'popular') {
            bits.push('Sorted by Most Popular');
        } else if (state.sort === 'recent') {
            bits.push('Sorted by Most Recent');
        }

        status.textContent = bits.length ? bits.join(' • ') : '';
        status.classList.toggle('is-visible', bits.length > 0);
    }

    function filterPolls(app) {
        var target = qs('.mts-cp-poll-sections', app);
        if (!target) {
            return;
        }
        var state = getFilterState(app);
        target.classList.add('is-loading');
        target.setAttribute('aria-busy', 'true');
        target.innerHTML = '<div class="mts-cp-inline-loading"><span class="mts-cp-spinner" aria-hidden="true"></span><p>' + escapeHtml((config.strings && config.strings.searching) || 'Searching polls…') + '</p></div>';

        ajax('mts_cp_filter_polls', state)
            .then(function (data) {
                target.innerHTML = data.html;
                target.classList.remove('is-loading');
                target.removeAttribute('aria-busy');
                setActiveFilterText(app);
            })
            .catch(function (error) {
                target.classList.remove('is-loading');
                target.removeAttribute('aria-busy');
                target.innerHTML = '<div class="mts-cp-notice mts-cp-notice--error">' + escapeHtml(error.message) + '</div>';
            });
    }

    function validateVoteForm(form) {
        var type = form.dataset.type || 'single';

        if (type === 'rank') {
            var selects = qsa('.mts-cp-rank-select', form);
            if (!selects.length) {
                return 'Please rank all options.';
            }
            return '';
        }

        if (type === 'maxdiff') {
            var mostChecked = qs('input[name="maxdiff_most"]:checked', form);
            var leastChecked = qs('input[name="maxdiff_least"]:checked', form);
            if (!mostChecked || !leastChecked) {
                return 'Please select both Most Important and Least Important options.';
            }
            if (mostChecked.value === leastChecked.value) {
                return 'Most Important and Least Important cannot be the same option.';
            }
            return '';
        }

        if (type === 'matrix') {
            var matrixRows = qsa('.mts-cp-matrix-table tbody tr', form);
            for (var i = 0; i < matrixRows.length; i++) {
                var checkedRow = qs('input[type="radio"]:checked', matrixRows[i]);
                if (!checkedRow) {
                    return 'Please evaluate all statements on the scale.';
                }
            }
            return '';
        }

        if (type === 'slider') {
            var sliderInput = qs('input[name="slider_value"]', form);
            if (!sliderInput || sliderInput.value === '') {
                return 'Please set a value on the slider.';
            }
            return '';
        }

        var checked = qsa('input[name="answer"]:checked, input[name="answer[]"]:checked', form);
        if (!checked.length) {
            return (config.strings && config.strings.chooseAnswer) || 'Please select an answer.';
        }
        var other = qs('input[value="__other__"]:checked', form);
        var otherText = qs('input[name="other_text"]', form);
        if (other && (!otherText || !otherText.value.trim())) {
            return (config.strings && config.strings.enterOther) || 'Please type your Other answer.';
        }
        return '';
    }

    function serializeVoteForm(form) {
        var formData = new FormData(form);
        var data = {};
        formData.forEach(function (value, key) {
            if (key.indexOf('rank[') === 0) {
                if (!data.rank) {
                    data.rank = {};
                }
                var optName = key.substring(5, key.length - 1);
                data.rank[optName] = value;
            } else if (key.indexOf('matrix[') === 0) {
                if (!data.matrix) {
                    data.matrix = {};
                }
                var stmtName = key.substring(7, key.length - 1);
                data.matrix[stmtName] = value;
            } else if (key === 'answer[]') {
                if (!data.answer) {
                    data.answer = [];
                }
                data.answer.push(value);
            } else if (key === 'answer') {
                data.answer = value;
            } else {
                data[key] = value;
            }
        });
        return data;
    }

    document.addEventListener('click', function (event) {
        var close = event.target.closest('[data-mts-cp-close]');
        if (close) {
            var modal = close.closest('.mts-cp-modal');
            closeModal(modal);
            return;
        }

        var categoryButton = event.target.closest('.mts-cp-category-button');
        if (categoryButton) {
            var categoryApp = categoryButton.closest('.mts-cp-library');
            qsa('.mts-cp-category-button', categoryApp).forEach(function (button) {
                button.classList.toggle('is-active', button === categoryButton);
                button.setAttribute('aria-pressed', button === categoryButton ? 'true' : 'false');
            });
            var categorySlug = categoryButton.dataset.category || '';
            var targetSection = qs('.mts-cp-category-section[data-category-slug="' + categorySlug + '"]', categoryApp);
            if (targetSection) {
                if (!targetSection.classList.contains('is-open')) {
                    targetSection.classList.add('is-open');
                    var accordionBtn = qs('.mts-cp-accordion-button', targetSection);
                    var content = qs('.mts-cp-category-content', targetSection);
                    var symbol = qs('.mts-cp-accordion-symbol', targetSection);
                    if (accordionBtn) {
                        accordionBtn.setAttribute('aria-expanded', 'true');
                    }
                    if (content) {
                        content.hidden = false;
                    }
                    if (symbol) {
                        symbol.textContent = '−';
                    }
                }
                var rect = targetSection.getBoundingClientRect();
                var offset = window.pageYOffset || document.documentElement.scrollTop;
                window.scrollTo({
                    top: rect.top + offset - 20,
                    behavior: 'smooth'
                });
                qsa('.mts-cp-category-section.is-highlighted', categoryApp).forEach(function (sec) {
                    sec.classList.remove('is-highlighted');
                });
                targetSection.classList.add('is-highlighted');
                window.setTimeout(function () {
                    targetSection.classList.remove('is-highlighted');
                }, 1500);
            }
            return;
        }

        var searchButton = event.target.closest('.mts-cp-search-button');
        if (searchButton) {
            var searchApp = searchButton.closest('.mts-cp-library');
            var input = qs('.mts-cp-keyword-input', searchApp);
            searchApp.dataset.keyword = input ? input.value.trim() : '';
            filterPolls(searchApp);
            return;
        }

        var sortButton = event.target.closest('.mts-cp-sort-button');
        if (sortButton) {
            var sortApp = sortButton.closest('.mts-cp-library');
            var selectedSort = sortButton.dataset.sort || 'default';
            sortApp.dataset.sort = selectedSort;
            qsa('.mts-cp-sort-button', sortApp).forEach(function (button) {
                button.classList.toggle('is-active', button === sortButton);
                button.setAttribute('aria-pressed', button === sortButton ? 'true' : 'false');
            });
            filterPolls(sortApp);
            return;
        }

        var clearButton = event.target.closest('.mts-cp-clear-button');
        if (clearButton) {
            var clearApp = clearButton.closest('.mts-cp-library');
            clearApp.dataset.category = '';
            clearApp.dataset.keyword = '';
            clearApp.dataset.sort = 'default';
            var keyword = qs('.mts-cp-keyword-input', clearApp);
            if (keyword) {
                keyword.value = '';
            }
            qsa('.mts-cp-category-button', clearApp).forEach(function (button) {
                button.classList.remove('is-active');
                button.setAttribute('aria-pressed', 'false');
            });
            qsa('.mts-cp-sort-button', clearApp).forEach(function (button) {
                button.classList.remove('is-active');
            });
            filterPolls(clearApp);
            return;
        }

        var accordion = event.target.closest('.mts-cp-accordion-button');
        if (accordion) {
            var section = accordion.closest('.mts-cp-category-section');
            var content = qs('.mts-cp-category-content', section);
            var symbol = qs('.mts-cp-accordion-symbol', accordion);
            var opening = !section.classList.contains('is-open');
            section.classList.toggle('is-open', opening);
            accordion.setAttribute('aria-expanded', opening ? 'true' : 'false');
            if (content) {
                content.hidden = !opening;
            }
            if (symbol) {
                symbol.textContent = opening ? '−' : '+';
            }
            return;
        }

        var answerButton = event.target.closest('.mts-cp-answer-button');
        if (answerButton && !answerButton.disabled) {
            var answerModal = openModal(answerButton, (config.strings && config.strings.loading) || 'Loading…');
            ajax('mts_cp_get_answer_form', { poll_id: answerButton.dataset.pollId })
                .then(function (data) {
                    qs('.mts-cp-modal-content', answerModal).innerHTML = data.html;
                    var firstHeading = qs('#mts-cp-modal-title', answerModal);
                    if (firstHeading) {
                        firstHeading.focus && firstHeading.focus();
                    }
                    var form = qs('.mts-cp-vote-form', answerModal);
                    if (form) {
                        updateOtherField(form);
                    }
                })
                .catch(function (error) {
                    showModalError(answerModal, error.message, error.payload && error.payload.loginUrl);
                });
            return;
        }

        var resultsButton = event.target.closest('.mts-cp-results-button');
        if (resultsButton) {
            var resultsModal = openModal(resultsButton, (config.strings && config.strings.loading) || 'Loading…');
            ajax('mts_cp_get_results', { poll_id: resultsButton.dataset.pollId })
                .then(function (data) {
                    qs('.mts-cp-modal-content', resultsModal).innerHTML = data.html;
                })
                .catch(function (error) {
                    showModalError(resultsModal, error.message, error.payload && error.payload.loginUrl);
                });
            return;
        }

        var contextToggle = event.target.closest('.mts-cp-context-toggle');
        if (contextToggle) {
            var contextStep = contextToggle.closest('.mts-cp-context-step');
            var contextField = qs('.mts-cp-context-field', contextStep);
            var expanded = contextToggle.getAttribute('aria-expanded') === 'true';
            contextToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            if (contextField) {
                contextField.hidden = expanded;
                if (!expanded) {
                    var textarea = qs('textarea', contextField);
                    if (textarea) {
                        window.setTimeout(function () { textarea.focus(); }, 10);
                    }
                }
            }
            return;
        }
    });

    document.addEventListener('change', function (event) {
        var target = event.target;

        if (target.matches('.mts-cp-vote-form input[name="answer"], .mts-cp-vote-form input[name="answer[]"]')) {
            updateOtherField(target.closest('.mts-cp-vote-form'));
        }

        if (target.matches('.mts-cp-rank-select')) {
            var rankItem = target.closest('.mts-cp-rank-item');
            var numSpan = qs('.mts-cp-rank-num', rankItem);
            if (numSpan) {
                numSpan.textContent = target.value;
            }
        }

        if (target.matches('input[name="maxdiff_most"], input[name="maxdiff_least"]')) {
            var form = target.closest('.mts-cp-vote-form');
            var tr = target.closest('tr');
            if (target.name === 'maxdiff_most') {
                var otherRadio = qs('input[name="maxdiff_least"]', tr);
                if (otherRadio && otherRadio.checked) {
                    otherRadio.checked = false;
                }
            } else {
                var otherRadio = qs('input[name="maxdiff_most"]', tr);
                if (otherRadio && otherRadio.checked) {
                    otherRadio.checked = false;
                }
            }
        }

        if (target.matches('.mts-cp-slider-input')) {
            var container = target.closest('.mts-cp-slider-container');
            var badgeNum = qs('.mts-cp-slider-val-num', container);
            if (badgeNum) {
                badgeNum.textContent = target.value;
            }
        }
    });

    document.addEventListener('input', function (event) {
        if (event.target.matches('.mts-cp-slider-input')) {
            var container = event.target.closest('.mts-cp-slider-container');
            var badgeNum = qs('.mts-cp-slider-val-num', container);
            if (badgeNum) {
                badgeNum.textContent = event.target.value;
            }
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            var openModalNode = qs('.mts-cp-modal:not([hidden])');
            if (openModalNode) {
                closeModal(openModalNode);
            }
        }

        if (event.key === 'Enter' && event.target.matches('.mts-cp-keyword-input')) {
            event.preventDefault();
            var app = event.target.closest('.mts-cp-library');
            app.dataset.keyword = event.target.value.trim();
            filterPolls(app);
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('.mts-cp-vote-form');
        if (!form) {
            return;
        }
        event.preventDefault();

        var errorText = validateVoteForm(form);
        var messageNode = qs('.mts-cp-form-message', form);
        if (errorText) {
            if (messageNode) {
                messageNode.textContent = errorText;
                messageNode.className = 'mts-cp-form-message is-error';
            }
            return;
        }

        var submitButton = qs('.mts-cp-submit-vote', form);
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.dataset.originalText = submitButton.textContent;
            submitButton.textContent = (config.strings && config.strings.loading) || 'Loading…';
        }
        if (messageNode) {
            messageNode.textContent = '';
            messageNode.className = 'mts-cp-form-message';
        }

        var data = serializeVoteForm(form);
        ajax('mts_cp_submit_vote', data)
            .then(function (response) {
                var modal = form.closest('.mts-cp-modal');
                var app = form.closest('.mts-cp-app');
                qs('.mts-cp-modal-content', modal).innerHTML = response.html;
                updatePollRow(app, data.poll_id, response.voteCount);
            })
            .catch(function (error) {
                if (messageNode) {
                    messageNode.textContent = error.message;
                    messageNode.className = 'mts-cp-form-message is-error';
                }
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.textContent = submitButton.dataset.originalText || 'Submit Answer';
                }
            });
    });

    document.addEventListener('DOMContentLoaded', function () {
        qsa('.mts-cp-library').forEach(function (app) {
            app.dataset.category = '';
            app.dataset.keyword = '';
            app.dataset.sort = 'default';

            qsa('.mts-cp-category-button', app).forEach(function (button) {
                button.setAttribute('aria-pressed', 'false');
            });

            if (app.dataset.openFirst === 'no') {
                qsa('.mts-cp-category-section', app).forEach(function (section) {
                    section.classList.remove('is-open');
                    var button = qs('.mts-cp-accordion-button', section);
                    var content = qs('.mts-cp-category-content', section);
                    var symbol = qs('.mts-cp-accordion-symbol', section);
                    if (button) {
                        button.setAttribute('aria-expanded', 'false');
                    }
                    if (content) {
                        content.hidden = true;
                    }
                    if (symbol) {
                        symbol.textContent = '+';
                    }
                });
            }
        });
    });
})();
