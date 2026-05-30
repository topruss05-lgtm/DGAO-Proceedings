document.addEventListener('DOMContentLoaded', () => {

    // Defensive: kein Input darf beim Page-Load Fokus haben. Manche
    // Browser (insbesondere Mobile-Safari/Chrome) restoren Fokus-State
    // beim Navigieren zwischen Seiten — das poppt überraschend die
    // Tastatur und zwingt den User in ein Suchfeld, das er gar nicht
    // anfassen wollte. User muss bewusst klicken/tippen um zu fokussieren.
    if (document.activeElement && document.activeElement.tagName === 'INPUT') {
        document.activeElement.blur();
    }

    const lang = document.body.dataset.lang || 'de';

    // --- Mobile navbar toggle ---
    const toggler = document.getElementById('navbarToggler');
    const navMenu = document.getElementById('navbarNav');
    if (toggler && navMenu) {
        toggler.addEventListener('click', () => {
            navMenu.classList.toggle('is-open');
            const expanded = toggler.getAttribute('aria-expanded') === 'true';
            toggler.setAttribute('aria-expanded', String(!expanded));
        });
    }

    // --- Archive detail: WAI-ARIA Combobox + Live-Filter ---
    // Recherche: W3C ARIA-APG (Combobox-Pattern), Adrian Roselli (kein type=search),
    // Custom Listbox statt <datalist> (Mobile/Safari-Bugs).
    initArchivFilter();
    initSucheCombobox();
    initSucheHighlight();

    // --- BibTeX toggle and copy ---
    const bibtexToggle = document.getElementById('bibtex-toggle-btn');
    const bibtexBlock = document.getElementById('bibtex-block');

    if (bibtexToggle && bibtexBlock) {
        bibtexToggle.addEventListener('click', () => {
            bibtexBlock.classList.toggle('d-none');
        });
    }

    const copyBtn = document.getElementById('bibtex-copy-btn');
    if (copyBtn) {
        const copiedLabel = lang === 'en' ? ' Copied!' : ' Kopiert!';
        const copyLabel = lang === 'en' ? ' Copy to Clipboard' : ' In Zwischenablage kopieren';

        copyBtn.addEventListener('click', () => {
            const bibtexText = document.getElementById('bibtex-output').textContent;
            navigator.clipboard.writeText(bibtexText).then(() => {
                const checkIcon = document.createElement('i');
                checkIcon.className = 'bi bi-check';

                copyBtn.textContent = '';
                copyBtn.appendChild(checkIcon);
                copyBtn.appendChild(document.createTextNode(copiedLabel));

                setTimeout(() => {
                    const clipIcon = document.createElement('i');
                    clipIcon.className = 'bi bi-clipboard';

                    copyBtn.textContent = '';
                    copyBtn.appendChild(clipIcon);
                    copyBtn.appendChild(document.createTextNode(copyLabel));
                }, 2000);
            });
        });
    }

});


// ============================================================
// Archive detail filter — WAI-ARIA 1.2 Combobox with Listbox.
// Pattern: https://www.w3.org/WAI/ARIA/apg/patterns/combobox/
// Diacritic-aware via NFD-normalize. Listbox uses DOM API (no innerHTML).
// ============================================================
function initArchivFilter() {
    const input    = document.getElementById('archiv-filter-input');
    const listbox  = document.getElementById('archiv-filter-listbox');
    const clearBtn = document.getElementById('archiv-filter-clear');
    const status   = document.getElementById('archiv-filter-status');
    const paperList = document.getElementById('paper-list');
    const emptyMsg  = document.getElementById('archiv-empty');
    const dataNode  = document.getElementById('archiv-filter-data');
    if (!input || !listbox || !paperList || !dataNode) return;

    const lang = document.body.dataset.lang || 'de';

    let suggestions = [];
    try { suggestions = JSON.parse(dataNode.textContent || '[]'); } catch (_) { suggestions = []; }

    const items = Array.from(paperList.querySelectorAll('.archiv-item'));
    const totalCount = items.length;
    const sessions = Array.from(paperList.querySelectorAll('details.archiv-session'));

    // NFD-normalize: 'Müller' -> 'muller', 'résumé' -> 'resume'.
    const normalize = (s) =>
        (s || '').toString().normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().replace(/\*+/g, '');

    items.forEach(el => { el.dataset.searchNorm = normalize(el.dataset.search || ''); });
    const normalizedSuggestions = suggestions.map(s => ({ label: s, norm: normalize(s) }));

    const tokenize = (q) => normalize(q).split(/[\s,]+/).filter(t => t.length > 0);

    let currentOptions = [];
    let activeIdx = -1;

    function updateStatus(visible) {
        if (!status) return;
        const hasInput = input.value.trim() !== '';
        status.textContent = (lang === 'en')
            ? (hasInput ? `${visible} of ${totalCount} papers` : `${totalCount} papers`)
            : (hasInput ? `${visible} von ${totalCount} Beiträgen` : `${totalCount} Beiträge`);
    }

    function applyFilter() {
        const tokens = tokenize(input.value);
        let visible = 0;
        items.forEach(it => {
            const hay = it.dataset.searchNorm || '';
            const match = tokens.length === 0 || tokens.every(t => hay.includes(t));
            it.hidden = !match;
            if (match) visible++;
        });
        sessions.forEach(det => {
            const anyVisible = det.querySelectorAll('.archiv-item:not([hidden])').length > 0;
            det.hidden = !anyVisible;
            if (anyVisible && tokens.length > 0) det.open = true;
            if (tokens.length === 0) det.open = false;
        });
        if (emptyMsg) emptyMsg.classList.toggle('d-none', visible > 0);
        updateStatus(visible);
        if (clearBtn) clearBtn.hidden = input.value === '';
    }

    function renderSuggestions() {
        const q = normalize(input.value);
        currentOptions = q.length < 1 ? [] :
            normalizedSuggestions.filter(s => s.norm.includes(q)).slice(0, 8);

        // Empty the listbox via DOM API (no innerHTML).
        while (listbox.firstChild) listbox.removeChild(listbox.firstChild);

        if (currentOptions.length === 0) {
            listbox.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.setAttribute('aria-activedescendant', '');
            activeIdx = -1;
            return;
        }
        currentOptions.forEach((o, i) => {
            const li = document.createElement('li');
            li.setAttribute('role', 'option');
            li.id = 'archiv-filter-opt-' + i;
            li.className = 'archiv-filter__option';
            li.textContent = o.label;
            listbox.appendChild(li);
        });
        listbox.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        activeIdx = -1;
        input.setAttribute('aria-activedescendant', '');
    }

    function setActive(i) {
        const opts = listbox.querySelectorAll('[role="option"]');
        opts.forEach(o => o.removeAttribute('aria-selected'));
        if (i >= 0 && i < opts.length) {
            opts[i].setAttribute('aria-selected', 'true');
            opts[i].scrollIntoView({ block: 'nearest' });
            input.setAttribute('aria-activedescendant', opts[i].id);
            activeIdx = i;
        } else {
            activeIdx = -1;
            input.setAttribute('aria-activedescendant', '');
        }
    }

    function commitSuggestion(label) {
        input.value = label;
        closeListbox();
        applyFilter();
    }

    function closeListbox() {
        listbox.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-activedescendant', '');
        activeIdx = -1;
    }

    function scrollToFirstVisible() {
        const first = paperList.querySelector('.archiv-item:not([hidden])');
        if (first) {
            const det = first.closest('details.archiv-session');
            if (det) det.open = true;
            first.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    input.addEventListener('input', () => { renderSuggestions(); applyFilter(); });

    input.addEventListener('keydown', (e) => {
        const max = currentOptions.length - 1;
        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                if (listbox.hidden) renderSuggestions();
                if (currentOptions.length > 0) setActive(Math.min(activeIdx + 1, max));
                break;
            case 'ArrowUp':
                e.preventDefault();
                if (currentOptions.length > 0) setActive(activeIdx <= 0 ? max : activeIdx - 1);
                break;
            case 'Enter':
                e.preventDefault();
                if (activeIdx >= 0 && currentOptions[activeIdx]) {
                    commitSuggestion(currentOptions[activeIdx].label);
                } else {
                    closeListbox();
                    scrollToFirstVisible();
                }
                break;
            case 'Escape':
                if (!listbox.hidden) closeListbox();
                else if (input.value !== '') { input.value = ''; applyFilter(); }
                break;
            case 'Tab':
                closeListbox();
                break;
        }
    });

    listbox.addEventListener('mousedown', (e) => {
        const li = e.target.closest('[role="option"]');
        if (!li) return;
        e.preventDefault();
        const idx = parseInt(li.id.replace('archiv-filter-opt-', ''), 10);
        if (currentOptions[idx]) commitSuggestion(currentOptions[idx].label);
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.archiv-filter')) closeListbox();
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            input.value = '';
            input.focus();
            closeListbox();
            applyFilter();
        });
    }

    updateStatus(totalCount);
    if (clearBtn) clearBtn.hidden = true;
}


// ============================================================
// /suche — Live-Combobox mit AJAX-Suggestions (Google-Style).
// Pattern: W3C ARIA-APG Combobox + debounced fetch zu /api/suggest.
// ============================================================
function initSucheCombobox() {
    const input    = document.getElementById('suche-q');
    const listbox  = document.getElementById('suche-q-listbox');
    const clearBtn = document.getElementById('suche-q-clear');
    if (!input || !listbox) return;

    const lang = document.body.dataset.lang || 'de';
    const LABELS = (lang === 'en')
        ? { authors: 'Authors', papers: 'Papers', tagungen: 'Conferences', papers_count: 'papers' }
        : { authors: 'Autor:innen', papers: 'Beiträge', tagungen: 'Tagungen', papers_count: 'Beiträge' };

    let currentOptions = [];
    let activeIdx = -1;
    let debounceHandle = null;
    let currentFetchController = null;

    function closeListbox() {
        listbox.hidden = true;
        while (listbox.firstChild) listbox.removeChild(listbox.firstChild);
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-activedescendant', '');
        activeIdx = -1;
        currentOptions = [];
    }

    function setActive(i) {
        const opts = listbox.querySelectorAll('[role="option"]');
        opts.forEach(o => o.removeAttribute('aria-selected'));
        if (i >= 0 && i < opts.length) {
            opts[i].setAttribute('aria-selected', 'true');
            opts[i].scrollIntoView({ block: 'nearest' });
            input.setAttribute('aria-activedescendant', opts[i].id);
            activeIdx = i;
        } else {
            activeIdx = -1;
            input.setAttribute('aria-activedescendant', '');
        }
    }

    function appendGroup(headerLabel, items, type) {
        if (!items || items.length === 0) return;
        const header = document.createElement('div');
        header.className = 'suche-combobox__group-header';
        header.textContent = headerLabel;
        header.setAttribute('role', 'presentation');
        listbox.appendChild(header);

        items.forEach(item => {
            const optIdx = currentOptions.length;
            const li = document.createElement('div');
            li.setAttribute('role', 'option');
            li.id = 'suche-q-opt-' + optIdx;
            li.className = 'suche-combobox__option suche-combobox__option--' + type;

            const main = document.createElement('span');
            main.className = 'suche-combobox__option-main';
            const sub  = document.createElement('span');
            sub.className = 'suche-combobox__option-sub';

            let label, subText, url;
            if (type === 'author') {
                label   = item.name;
                subText = item.papers + ' ' + LABELS.papers_count + (item.affiliation ? ' · ' + item.affiliation : '');
                url     = item.url;
            } else if (type === 'paper') {
                label   = item.code + ' — ' + item.titel;
                subText = (item.hauptautor ? item.hauptautor + ' · ' : '') + item.tagung_nummer + '. Tagung';
                url     = item.url;
            } else if (type === 'tagung') {
                label   = item.nummer + '. Jahrestagung';
                subText = item.jahr + (item.ort ? ' · ' + item.ort : '');
                url     = item.url;
            }

            main.textContent = label;
            sub.textContent  = subText;
            li.appendChild(main);
            li.appendChild(sub);
            li.dataset.url = url;
            listbox.appendChild(li);
            currentOptions.push({ url: url, label: label });
        });
    }

    async function fetchSuggestions(q) {
        if (currentFetchController) currentFetchController.abort();
        currentFetchController = new AbortController();
        try {
            const resp = await fetch('/api/suggest?q=' + encodeURIComponent(q),
                { signal: currentFetchController.signal, headers: { 'Accept': 'application/json' } });
            if (!resp.ok) return null;
            return await resp.json();
        } catch (e) {
            if (e.name !== 'AbortError') console.warn('Suggest fetch failed', e);
            return null;
        }
    }

    function renderSuggestions(data) {
        while (listbox.firstChild) listbox.removeChild(listbox.firstChild);
        currentOptions = [];

        if (!data) { closeListbox(); return; }
        // Reihenfolge: Papers (haeufigste Intention) > Authors > Tagungen.
        appendGroup(LABELS.papers,   data.papers,   'paper');
        appendGroup(LABELS.authors,  data.authors,  'author');
        appendGroup(LABELS.tagungen, data.tagungen, 'tagung');

        if (currentOptions.length === 0) { closeListbox(); return; }
        listbox.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        activeIdx = -1;
        input.setAttribute('aria-activedescendant', '');
    }

    function triggerSuggest() {
        const q = input.value.trim();
        if (clearBtn) clearBtn.hidden = q === '';
        if (q.length < 2) { closeListbox(); return; }
        clearTimeout(debounceHandle);
        debounceHandle = setTimeout(async () => {
            const data = await fetchSuggestions(q);
            renderSuggestions(data);
        }, 180);
    }

    function navigateTo(url) { if (url) window.location.href = url; }

    input.addEventListener('input', triggerSuggest);

    input.addEventListener('keydown', (e) => {
        const max = currentOptions.length - 1;
        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                if (listbox.hidden) triggerSuggest();
                if (currentOptions.length > 0) setActive(Math.min(activeIdx + 1, max));
                break;
            case 'ArrowUp':
                e.preventDefault();
                if (currentOptions.length > 0) setActive(activeIdx <= 0 ? max : activeIdx - 1);
                break;
            case 'Enter':
                if (activeIdx >= 0 && currentOptions[activeIdx]) {
                    e.preventDefault();
                    navigateTo(currentOptions[activeIdx].url);
                }
                // Sonst: Default-Submit des Form (zur Suchergebnisseite).
                break;
            case 'Escape':
                if (!listbox.hidden) { e.preventDefault(); closeListbox(); }
                break;
            case 'Tab':
                closeListbox();
                break;
        }
    });

    listbox.addEventListener('mousedown', (e) => {
        const li = e.target.closest('[role="option"]');
        if (!li) return;
        e.preventDefault();
        navigateTo(li.dataset.url);
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.suche-combobox')) closeListbox();
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            input.value = '';
            input.focus();
            closeListbox();
            clearBtn.hidden = true;
        });
        clearBtn.hidden = input.value === '';
    }
}


// ============================================================
// /suche — Highlight der Suchbegriffe in den Ergebnis-Snippets.
// Diakritik-aware (NFD-Mapping zwischen Original-Text und seinem
// normalisierten Pendant), wendet <mark> nur auf Textknoten an —
// kein innerHTML-Replace, damit Tags und Attribute unangetastet
// bleiben. Ignoriert Phrasen-Anführungszeichen, Wildcards und
// Boolean-Operatoren wie der Server.
// ============================================================
function initSucheHighlight() {
    const root = document.getElementById('suche-results');
    if (!root) return;
    const raw = (root.dataset.highlight || '').trim();
    if (raw === '') return;

    // Tokens extrahieren: gleiches Vorgehen wie sanitizeFtsQuery clientseitig.
    const tokens = [];
    const phraseRe = /"([^"]+)"/g;
    let working = raw;
    let m;
    while ((m = phraseRe.exec(raw)) !== null) {
        if (m[1].trim()) tokens.push(m[1].trim());
    }
    working = working.replace(phraseRe, ' ');
    working.split(/\s+/).forEach(w => {
        if (!w) return;
        if (w === 'AND' || w === 'OR' || w === 'NOT') return;
        let t = w;
        if (t.startsWith('-')) t = t.slice(1);
        if (t.endsWith('*'))   t = t.slice(0, -1);
        if (t.length >= 2) tokens.push(t);
    });
    if (tokens.length === 0) return;

    const normalize = (s) =>
        (s || '').toString().normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();

    // Eindeutige Tokens nach Laenge (laengster zuerst -> greedy match).
    const uniq = [...new Set(tokens.map(normalize))].filter(t => t.length >= 2);
    uniq.sort((a, b) => b.length - a.length);
    if (uniq.length === 0) return;

    // Highlight pro Textknoten: original und normalize parallel, finde Matches
    // im Normalized-String, baue dann Original-Slices + <mark> aus den
    // selben Indizes (Length-Mapping: 1 zu 1 nach NFD-Strip-of-Diakritik).
    function highlightTextNode(node) {
        const orig = node.nodeValue;
        if (!orig || orig.length < 2) return;
        const norm = normalize(orig);
        if (norm.length !== orig.length) {
            // NFD koennte Compose-Sequenzen erzeugen, wo Laenge differiert
            // (selten in Latin1+Umlauts, aber moeglich). In dem Fall skippen.
            return;
        }
        // Finde alle Match-Bereiche (start,end).
        const ranges = [];
        uniq.forEach(t => {
            let from = 0;
            while (from < norm.length) {
                const idx = norm.indexOf(t, from);
                if (idx === -1) break;
                ranges.push([idx, idx + t.length]);
                from = idx + t.length;
            }
        });
        if (ranges.length === 0) return;
        // Merge ueberlappende Ranges.
        ranges.sort((a, b) => a[0] - b[0]);
        const merged = [ranges[0].slice()];
        for (let i = 1; i < ranges.length; i++) {
            const last = merged[merged.length - 1];
            if (ranges[i][0] <= last[1]) last[1] = Math.max(last[1], ranges[i][1]);
            else merged.push(ranges[i].slice());
        }
        // DOM ersetzen.
        const frag = document.createDocumentFragment();
        let cursor = 0;
        merged.forEach(([s, e]) => {
            if (s > cursor) frag.appendChild(document.createTextNode(orig.slice(cursor, s)));
            const mark = document.createElement('mark');
            mark.textContent = orig.slice(s, e);
            frag.appendChild(mark);
            cursor = e;
        });
        if (cursor < orig.length) frag.appendChild(document.createTextNode(orig.slice(cursor)));
        node.parentNode.replaceChild(frag, node);
    }

    // Nur Titel + Autorenzeile in jeder Card highlighten (keine Metadaten,
    // damit "2020" nicht jede Jahreszahl markiert).
    const targets = root.querySelectorAll('.paper-card .card-title, .paper-card .card-authors');
    targets.forEach(el => {
        const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null);
        const nodes = [];
        let n;
        while ((n = walker.nextNode())) nodes.push(n);
        nodes.forEach(highlightTextNode);
    });
}
