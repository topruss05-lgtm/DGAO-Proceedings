<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/helpers.php';

// ============================================================
// SECTION A — Pure-ASCII-Alphanumeric Invariant
//
// For every input below the function MUST return a string that
// matches /^[a-z0-9]*$/ — lowercase ASCII letters and digits
// only, nothing else. No apostrophes, no punctuation, no spaces,
// no non-ASCII bytes.
// ============================================================

$pureAsciiInputs = [
    // German
    'Müller', 'Schöck', 'Größere', 'Pruß',
    // French
    'François', 'Élise', 'Béatrice', 'Joël',
    // Spanish
    'García', 'Núñez', 'José María',
    // Portuguese
    'São Paulo', 'Conceição',
    // Italian (apostrophes — the critical case)
    "D'Angelo", "dell'Università",
    // Polish
    'Łukasz', 'Kraków', 'Wałęsa',
    // Czech/Slovak
    'Žižka', 'Čech', 'Dvořák',
    // Hungarian
    'Erdős', 'Lőrinc',
    // Turkish
    'Şahin', 'Yılmaz', 'İlhan', 'Güneş',
    // Scandinavian (incl. Icelandic Þ)
    'Bjørn', 'Åse', 'Þórður', 'Søren',
    // Vietnamese
    'Nguyễn', 'Trần', 'Lê Văn',
    // Romanian
    'Țărancu', 'Mănescu',
    // Russian/Cyrillic
    'Иванов', 'Достоевский',
    // Greek letters used in lab/institute names
    'μ-Lab', 'Π-Institut', 'α-test', 'Σχολή',
    // Chinese
    '李', '中村', '北京大学',
    // Japanese kana
    'さくら', 'カタカナ',
    // Korean
    '김', '이순신',
    // Hebrew
    'שלום',
    // Arabic
    'محمد',
    // Apostrophes: straight, curly right, curly left
    "O'Brien", "O\u{2019}Brien", "O\u{2018}Brien",
    // Dashes: hyphen, en-dash, em-dash
    'Müller-Schmidt', 'Müller–Schmidt', 'Müller—Schmidt',
    // Unicode whitespace: NBSP U+00A0, Narrow-NBSP U+202F, ZWSP U+200B
    "Müller\u{00A0}Schmidt",
    "Müller\u{202F}Schmidt",
    "Müller\u{200B}Schmidt",
    // Combining diacritics — NFD-decomposed ü (u + combining umlaut)
    "Mu\u{0308}ller",
    // Mixed: title, NBSP, dashes, curly apostrophe, footnote markers
    "Dr.\u{00A0}Müller-O\u{2019}Brien*** ***",
];

foreach ($pureAsciiInputs as $input) {
    $out = normalizeForAliasMatch($input);
    assert_true(
        preg_match('/^[a-z0-9]*$/', $out) === 1,
        "pure-ASCII-alnum invariant: " . json_encode($input, JSON_UNESCAPED_UNICODE)
    );
}

// ============================================================
// SECTION B — HTML Entity (documented exception)
//
// HTML entities are decoded BEFORE this function is called.
// If an entity reaches this function, '&' and ';' are stripped
// (non-alphanumeric) and the remaining letters pass through.
// Example: 'Mu&uuml;ller' → 'muuumlller'  (NOT 'muller').
// This is intentional and documented — NOT a bug.
// The result is still pure-ASCII-alnum (passes the invariant).
// ============================================================

assert_true(
    preg_match('/^[a-z0-9]*$/', normalizeForAliasMatch('Mu&uuml;ller')) === 1,
    'HTML entity: result is pure-ASCII-alnum'
);
assert_equals(
    'muuumlller',
    normalizeForAliasMatch('Mu&uuml;ller'),
    'HTML entity: exact documented output (& and ; stripped, letters kept)'
);

// ============================================================
// SECTION C — Determinism
//
// Calling the function twice on the same input must yield
// identical results (tests referential transparency / no
// global state).
// ============================================================

$determinismInputs = array_merge($pureAsciiInputs, ['Mu&uuml;ller']);

foreach ($determinismInputs as $input) {
    $a = normalizeForAliasMatch($input);
    $b = normalizeForAliasMatch($input);
    assert_true(
        $a === $b,
        "determinism: " . json_encode($input, JSON_UNESCAPED_UNICODE)
    );
}

// ============================================================
// SECTION D — Equivalence Pairs
//
// These pairs MUST produce the same normalized output.
// ============================================================

// D1. NFD-decomposed vs NFC-precomposed ü
assert_equals(
    normalizeForAliasMatch("Mu\u{0308}ller"),   // NFD: u + combining umlaut
    normalizeForAliasMatch('Müller'),             // NFC: precomposed ü
    'equivalence: NFD "Mu\u{0308}ller" == NFC "Müller"'
);

// D2. ß vs ss (both spellings must resolve to same key)
assert_equals(
    normalizeForAliasMatch('Pruß'),
    normalizeForAliasMatch('Pruss'),
    'equivalence: "Pruß" == "Pruss"'
);

// D3. Curly vs straight apostrophe
assert_equals(
    normalizeForAliasMatch("O\u{2019}Brien"),  // curly right '
    normalizeForAliasMatch("O'Brien"),           // straight '
    "equivalence: curly O\u{2019}Brien == straight O'Brien"
);

// D4. Different dash types
assert_equals(
    normalizeForAliasMatch('Müller-Schmidt'),   // hyphen U+002D
    normalizeForAliasMatch('Müller–Schmidt'),   // en-dash U+2013
    'equivalence: hyphen == en-dash in "Müller-Schmidt"'
);
assert_equals(
    normalizeForAliasMatch('Müller-Schmidt'),
    normalizeForAliasMatch('Müller—Schmidt'),   // em-dash U+2014
    'equivalence: hyphen == em-dash in "Müller-Schmidt"'
);

// D5. Regular space vs NBSP
assert_equals(
    normalizeForAliasMatch('Müller Schmidt'),
    normalizeForAliasMatch("Müller\u{00A0}Schmidt"),
    'equivalence: regular space == NBSP in "Müller Schmidt"'
);

// D6. Left and right curly apostrophe (both must produce same result as straight)
assert_equals(
    normalizeForAliasMatch("O\u{2018}Brien"),  // curly left '
    normalizeForAliasMatch("O'Brien"),           // straight '
    "equivalence: curly-left O\u{2018}Brien == straight O'Brien"
);

// ============================================================
// SECTION E — Specific Expected-Value Tests
//
// ICU output locked to the values observed on this system.
// If ICU version changes and these fail, update the expected
// values and document the ICU version in a comment.
// ============================================================

// Cyrillic — ICU romanization
assert_equals('ivanov',      normalizeForAliasMatch('Иванов'),      'Cyrillic: Иванов -> ivanov');
assert_equals('dostoevskij', normalizeForAliasMatch('Достоевский'), 'Cyrillic: Достоевский -> dostoevskij');

// Chinese — ICU Pinyin (simplified/traditional)
assert_equals('li',           normalizeForAliasMatch('李'),       'Chinese: 李 -> li (Pinyin)');
assert_equals('zhongcun',     normalizeForAliasMatch('中村'),     'Chinese: 中村 -> zhongcun (Pinyin)');
assert_equals('beijingdaxue', normalizeForAliasMatch('北京大学'), 'Chinese: 北京大学 -> beijingdaxue (Pinyin)');

// Japanese kana — ICU romaji
assert_equals('sakura',   normalizeForAliasMatch('さくら'),   'Japanese: さくら -> sakura');
assert_equals('katakana', normalizeForAliasMatch('カタカナ'), 'Japanese: カタカナ -> katakana');

// Korean — ICU romanization
assert_equals('gim',     normalizeForAliasMatch('김'),     'Korean: 김 -> gim');
assert_equals('isunsin', normalizeForAliasMatch('이순신'), 'Korean: 이순신 -> isunsin');

// Hebrew — consonantal romanization (no vowels in unpointed text)
assert_equals('slwm', normalizeForAliasMatch('שלום'), 'Hebrew: שלום -> slwm');

// Arabic — consonantal romanization
assert_equals('mhmd', normalizeForAliasMatch('محمد'), 'Arabic: محمد -> mhmd');

// Vietnamese — diacritics removed, tones dropped
assert_equals('nguyen', normalizeForAliasMatch('Nguyễn'), 'Vietnamese: Nguyễn -> nguyen');
assert_equals('tran',   normalizeForAliasMatch('Trần'),   'Vietnamese: Trần -> tran');

// Turkish — dotted capital I -> i (handled by ICU + mb_strtolower)
assert_equals('ilhan', normalizeForAliasMatch('İlhan'), 'Turkish: İlhan -> ilhan');
assert_equals('sahin', normalizeForAliasMatch('Şahin'), 'Turkish: Şahin -> sahin');

// Icelandic Þ -> th
assert_equals('thordur', normalizeForAliasMatch('Þórður'), 'Icelandic: Þórður -> thordur');

// Greek letters in institute names
assert_equals('mlab',     normalizeForAliasMatch('μ-Lab'),     'Greek: μ-Lab -> mlab');
assert_equals('pinstitut', normalizeForAliasMatch('Π-Institut'), 'Greek: Π-Institut -> pinstitut');
assert_equals('atest',    normalizeForAliasMatch('α-test'),    'Greek: α-test -> atest');
assert_equals('schole',   normalizeForAliasMatch('Σχολή'),   'Greek: Σχολή -> schole');

// Romanian with comma-below letters
assert_equals('tarancu', normalizeForAliasMatch('Țărancu'), 'Romanian: Țărancu -> tarancu');
assert_equals('manescu', normalizeForAliasMatch('Mănescu'), 'Romanian: Mănescu -> manescu');

// Apostrophe stripping (the key fix in this hardening)
assert_equals('obrien',        normalizeForAliasMatch("O'Brien"),             "apostrophe: straight O'Brien -> obrien");
assert_equals('obrien',        normalizeForAliasMatch("O\u{2019}Brien"),      "apostrophe: curly-right O\u{2019}Brien -> obrien");
assert_equals('dangelo',       normalizeForAliasMatch("D'Angelo"),            "apostrophe: D'Angelo -> dangelo");
assert_equals('delluniversita', normalizeForAliasMatch("dell'Università"),    "apostrophe: dell'Università -> delluniversita");

// Mixed complex input
assert_equals(
    'drmullerschmidt',
    normalizeForAliasMatch("Dr. Müller-Schmidt"),
    'mixed: "Dr. Müller-Schmidt" -> drmullerschmidt'
);
