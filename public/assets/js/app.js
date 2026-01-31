document.addEventListener('DOMContentLoaded', () => {

    // --- Mobile sidebar toggle ---
    const sidebar   = document.getElementById('siteSidebar');
    const backdrop  = document.getElementById('sidebarBackdrop');
    const toggleBtn = document.getElementById('sidebarToggle');
    const closeBtn  = document.getElementById('sidebarClose');

    function openSidebar() {
        if (!sidebar) return;
        sidebar.classList.add('open');
        if (backdrop) backdrop.classList.add('show');
        document.body.style.overflow = 'hidden';
        toggleBtn?.setAttribute('aria-expanded', 'true');
    }

    function closeSidebar() {
        if (!sidebar) return;
        sidebar.classList.remove('open');
        if (backdrop) backdrop.classList.remove('show');
        document.body.style.overflow = '';
        toggleBtn?.setAttribute('aria-expanded', 'false');
    }

    if (toggleBtn) toggleBtn.addEventListener('click', openSidebar);
    if (closeBtn)  closeBtn.addEventListener('click', closeSidebar);
    if (backdrop)  backdrop.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && sidebar?.classList.contains('open')) {
            closeSidebar();
        }
    });

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
                        return (a.dataset.title || '').localeCompare(b.dataset.title || '', 'de');
                    } else if (sortBy === 'autor') {
                        return (a.dataset.author || '').localeCompare(b.dataset.author || '', 'de');
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
        copyBtn.addEventListener('click', () => {
            const bibtexText = document.getElementById('bibtex-output').textContent;
            navigator.clipboard.writeText(bibtexText).then(() => {
                const checkIcon = document.createElement('i');
                checkIcon.className = 'bi bi-check';

                copyBtn.textContent = '';
                copyBtn.appendChild(checkIcon);
                copyBtn.appendChild(document.createTextNode(' Kopiert!'));

                setTimeout(() => {
                    const clipIcon = document.createElement('i');
                    clipIcon.className = 'bi bi-clipboard';

                    copyBtn.textContent = '';
                    copyBtn.appendChild(clipIcon);
                    copyBtn.appendChild(document.createTextNode(' In Zwischenablage kopieren'));
                }, 2000);
            });
        });
    }

});
