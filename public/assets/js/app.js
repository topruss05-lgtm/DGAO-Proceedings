document.addEventListener('DOMContentLoaded', () => {

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

    // --- Archive detail: sort buttons (programm/autor) + author filter ---
    const sortButtons   = document.querySelectorAll('#archiv-sort-buttons .sort-btn');
    const viewProgramm  = document.getElementById('paper-list-programm');
    const viewAutor     = document.getElementById('paper-list-autor');
    const authorInput   = document.getElementById('archiv-author-input');
    const emptyMessage  = document.getElementById('archiv-empty');

    if (sortButtons.length && viewProgramm && viewAutor) {
        const switchView = (mode) => {
            sortButtons.forEach(b => b.classList.toggle('active', b.dataset.sort === mode));
            if (mode === 'autor') {
                viewProgramm.classList.add('d-none');
                viewAutor.classList.remove('d-none');
            } else {
                viewAutor.classList.add('d-none');
                viewProgramm.classList.remove('d-none');
            }
            applyAuthorFilter();
        };

        sortButtons.forEach(btn => {
            btn.addEventListener('click', () => switchView(btn.dataset.sort));
        });

        // Author filter: matches against data-author attribute (lowercased,
        // contains all co-authors). Empty input shows everything. Whitespace
        // is normalized; commas and asterisks ignored.
        const normalizeQuery = (q) =>
            q.toLowerCase().replace(/\*+/g, '').replace(/\s+/g, ' ').trim();

        const applyAuthorFilter = () => {
            if (!authorInput) return;
            const q = normalizeQuery(authorInput.value);
            const activeView = viewProgramm.classList.contains('d-none') ? viewAutor : viewProgramm;
            const items = activeView.querySelectorAll('.archiv-item');
            let visible = 0;
            items.forEach(it => {
                const hay = it.dataset.author || '';
                const match = q === '' || hay.includes(q);
                it.classList.toggle('d-none', !match);
                if (match) visible++;
            });
            // Sessions/Kategorien ohne sichtbare Papers ausblenden.
            activeView.querySelectorAll('details.archiv-session').forEach(det => {
                const anyVisible = det.querySelectorAll('.archiv-item:not(.d-none)').length > 0;
                det.classList.toggle('d-none', !anyVisible);
                if (anyVisible && q !== '') det.open = true;
            });
            if (emptyMessage) emptyMessage.classList.toggle('d-none', visible > 0);
        };

        if (authorInput) {
            authorInput.addEventListener('input', applyAuthorFilter);
        }
    }

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
