document.addEventListener('DOMContentLoaded', () => {

    // --- Language detection ---
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

    // --- Paper sorting on conference detail page ---
    const sortButtons = document.querySelectorAll('[data-sort]');
    const paperList = document.getElementById('paper-list');

    if (sortButtons.length && paperList) {
        sortButtons.forEach((btn) => {
            btn.addEventListener('click', () => {
                sortButtons.forEach((b) => b.classList.remove('active'));
                btn.classList.add('active');

                const sortBy = btn.getAttribute('data-sort');
                const items = Array.from(paperList.children);

                items.sort((a, b) => {
                    if (sortBy === 'titel') {
                        return (a.dataset.title || '').localeCompare(b.dataset.title || '', lang);
                    } else if (sortBy === 'autor') {
                        return (a.dataset.author || '').localeCompare(b.dataset.author || '', lang);
                    } else {
                        return parseInt(a.dataset.sortOrder) - parseInt(b.dataset.sortOrder);
                    }
                });

                items.forEach((item) => paperList.appendChild(item));
            });
        });
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
