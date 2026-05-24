<?php
$pageTitle    = t('kontakt.title') . ' - ' . SITE_NAME;
$canonicalUrl = canonicalUrl('/kontakt');
?>

<h1 class="h3 mb-4"><?= e(t('kontakt.title')) ?></h1>

<div class="article-detail">
    <p><?= t('kontakt.intro') ?></p>

    <h2 class="h6 mt-4"><?= e(t('kontakt.web_contact_heading')) ?></h2>
    <p><?= e(t('kontakt.web_contact_intro')) ?></p>
    <div class="ms-3 mb-4">
        <p class="mb-2">
            Christof Pruß<br>
            <a href="mailto:info@dgao-proceedings.de" class="accent-link">
                <i class="bi bi-envelope"></i> info@dgao-proceedings.de
            </a>
        </p>
        <p class="text-muted small mb-0"><?= e(t('kontakt.or')) ?></p>
    </div>

    <h2 class="h6 mt-4"><?= e(t('kontakt.sekretariat_heading')) ?></h2>
    <div class="ms-3 mb-4">
        <p class="mb-2">
            c/o Hochschule Pforzheim<br>
            Tiefenbronner Str. 65<br>
            75175 Pforzheim
        </p>
        <p class="mb-0">
            <a href="mailto:sekretariat@dgao.de" class="accent-link">
                <i class="bi bi-envelope"></i> sekretariat@dgao.de
            </a>
        </p>
    </div>

    <h2 class="h6 mt-4"><?= e(t('kontakt.dgao_address_heading')) ?></h2>
    <div class="ms-3 mb-4">
        <p class="mb-2">
            <strong>Deutsche Gesellschaft für angewandte Optik e.V.</strong><br>
            c/o Prof. Dr. Steffen Reichel<br>
            Hochschule Pforzheim<br>
            Tiefenbronner Str. 65<br>
            75175 Pforzheim
        </p>
        <p class="mb-0">
            <a href="mailto:sekretariat@dgao.de" class="accent-link">
                <i class="bi bi-envelope"></i> sekretariat@dgao.de
            </a><br>
            <a href="https://www.dgao.de/" target="_blank" rel="noopener" class="accent-link">
                <i class="bi bi-globe2"></i> www.dgao.de
            </a>
        </p>
    </div>
</div>
