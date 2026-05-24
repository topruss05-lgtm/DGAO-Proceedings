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

    // --- Archive detail: universal filter (matches data-search attribute) ---
    const filterInput  = document.getElementById('archiv-filter-input');
    const paperList    = document.getElementById('paper-list');
    const emptyMessage = document.getElementById('archiv-empty');

    if (filterInput && paperList) {
        const tokenize = (q) =>
            q.toLowerCase().replace(/\*+/g, '').split(/[\s,]+/).filter(t => t.length > 0);

        const applyFilter = () => {
            const tokens = tokenize(filterInput.value);
            const items = paperList.querySelectorAll('.archiv-item');
            let visible = 0;
            items.forEach(it => {
                const hay = it.dataset.search || '';
                const match = tokens.length === 0 || tokens.every(t => hay.includes(t));
                it.classList.toggle('d-none', !match);
                if (match) visible++;
            });
            paperList.querySelectorAll('details.archiv-session').forEach(det => {
                const anyVisible = det.querySelectorAll('.archiv-item:not(.d-none)').length > 0;
                det.classList.toggle('d-none', !anyVisible);
                if (anyVisible && tokens.length > 0) det.open = true;
            });
            if (emptyMessage) emptyMessage.classList.toggle('d-none', visible > 0);
        };

        filterInput.addEventListener('input', applyFilter);
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
