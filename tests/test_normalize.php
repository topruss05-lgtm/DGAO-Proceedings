<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/helpers.php';

assert_equals('cpruss',   normalizeForAliasMatch('C. Pruß'),            'einfacher ß->ss');
assert_equals('cpruss',   normalizeForAliasMatch('C. Pruß*'),           'einzelner Stern');
assert_equals('cpruss',   normalizeForAliasMatch('C. Pruß** ***'),      'multi-Sterne mit Space');
assert_equals('chpruss',  normalizeForAliasMatch('Ch. Pruß'),           'Ch. Initiale');
assert_equals('mullerhp', normalizeForAliasMatch('Müller, H.-P.'),      'Komma + Bindestrich + Umlaut');
assert_equals('cpruss',   normalizeForAliasMatch('C. Pruss'),           'ohne ß identisch');
assert_equals('cpruss',   normalizeForAliasMatch('  C.  Pruß  '),       'Whitespace überall');
assert_equals(
    'institutfurtechnischeoptikuniversitatstuttgart',
    normalizeForAliasMatch('Institut für Technische Optik, Universität Stuttgart'),
    'lange Affiliation'
);
assert_equals('', normalizeForAliasMatch(''),    'leerer Input');
assert_equals('', normalizeForAliasMatch('***'), 'nur Sterne');
assert_equals('mlab',      normalizeForAliasMatch('μ-Lab'),         'Greek mu -> m via Any-Latin');
assert_equals('pinstitut', normalizeForAliasMatch('Π-Institut'),    'Greek capital pi -> p via Any-Latin');
