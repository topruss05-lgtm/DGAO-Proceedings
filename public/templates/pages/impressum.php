<?php
$pageTitle = t('impressum.title') . ' - ' . SITE_NAME;
?>

<h1 class="h3 mb-4"><?= t('impressum.title') ?></h1>

<div class="article-detail">
    <p class="text-muted"><?= t('impressum.legal_ref') ?></p>

    <h2 class="h5"><?= t('impressum.heading') ?><?= SITE_ISSN ?></h2>

    <h3 class="h6 mt-4 section-header"><?= t('impressum.provider') ?></h3>
    <p>
        Deutsche Gesellschaft für angewandte Optik e.V. (DGaO)<br>
        c/o Prof. Dr.-Ing. Steffen Reichel<br>
        Hochschule Pforzheim<br>
        Tiefenbronner Str. 65<br>
        75175 Pforzheim
    </p>
    <p>
        E-Mail: <a href="mailto:sekretariat@dgao.de" class="accent-link">sekretariat@dgao.de</a><br>
        Web: <a href="https://www.dgao-proceedings.de/" class="accent-link">https://www.dgao-proceedings.de/</a>
    </p>

    <h3 class="h6 mt-4 section-header"><?= t('impressum.registration') ?></h3>
    <p>
        <?= t('impressum.registration_text') ?>
    </p>

    <h3 class="h6 mt-4 section-header"><?= t('impressum.board') ?></h3>
    <p class="small text-muted mb-3">
        <?= e(t('impressum.board_link')) ?>
        <a href="https://dgao.de/vorstand/" target="_blank" rel="noopener" class="accent-link">dgao.de/vorstand/</a>
    </p>
    <div class="row">
        <div class="col-md-6 mb-3">
            <strong><?= t('impressum.president') ?></strong><br>
            Prof. Dr.-Ing. Steffen Reichel<br>
            Hochschule Pforzheim<br>
            Tiefenbronner Str. 65<br>
            75175 Pforzheim
        </div>
        <div class="col-md-6 mb-3">
            <strong><?= t('impressum.past_president') ?></strong><br>
            Dipl.-Phys. Ricarda Kafka<br>
            TRIOPTICS Berlin GmbH<br>
            Schwarzschildstraße 12<br>
            12489 Berlin
        </div>
    </div>
    <div class="row">
        <div class="col-md-6 mb-3">
            <strong><?= t('impressum.treasurer') ?></strong><br>
            Marko Hanft<br>
            Carl Zeiss AG<br>
            Carl-Zeiss-Promenade 10<br>
            07743 Jena
        </div>
        <div class="col-md-6 mb-3">
            <strong><?= t('impressum.secretary') ?></strong><br>
            Dr. Christof Pruß<br>
            ITO &ndash; Institut für Technische Optik<br>
            Universität Stuttgart<br>
            Pfaffenwaldring 9<br>
            70569 Stuttgart
        </div>
    </div>

    <h3 class="h6 mt-4 section-header"><?= t('impressum.editors') ?></h3>
    <div class="row">
        <div class="col-md-6 mb-3">
            Prof. Dr. Gerd Häusler<br>
            Friedrich-Alexander-Universität Erlangen-Nürnberg<br>
            Institut für Optik, Information und Photonik<br>
            Staudtstr. 7/B2<br>
            91058 Erlangen
        </div>
        <div class="col-md-6 mb-3">
            Prof. Dr. Christian Faber<br>
            Hochschule Landshut<br>
            Am Lurzenhof 1<br>
            84036 Landshut
        </div>
    </div>

    <p>
        <?= t('impressum.vat_id') ?>
        DE 64100/00380 (Finanzamt Heidenheim)
    </p>
    <p>
        <?= t('impressum.responsible') ?><br>
        Prof. Dr.-Ing. Steffen Reichel <?= t('impressum.responsible_note') ?>
    </p>

    <h3 class="h6 mt-4 section-header"><?= t('impressum.disclaimer') ?></h3>
    <p><?= t('impressum.disclaimer_p1') ?></p>
    <p><?= t('impressum.disclaimer_p2') ?></p>
    <p><?= t('impressum.disclaimer_p3') ?></p>
    <p><?= t('impressum.disclaimer_p4') ?></p>

    <h3 class="h6 mt-4 section-header"><?= t('impressum.copyright') ?></h3>
    <p><?= t('impressum.copyright_p1') ?></p>
    <p><?= t('impressum.copyright_p2') ?></p>
</div>
