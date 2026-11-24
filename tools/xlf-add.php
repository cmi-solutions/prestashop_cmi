<?php

/**
 * Adds or updates translations in a module XLF file, keeping the units sorted by source text.
 *
 *   php tools/xlf-add.php translations/fr-FR/ModulesCmiAdmin.fr-FR.xlf fr-FR texts.json
 *
 * texts.json: {"English source": "translation", ...}; null removes a text. The file is created
 * when missing.
 */
[$script, $file, $locale, $json] = array_pad($argv, 4, '');
if ($file === '' || $locale === '' || !is_file($json)) {
    fwrite(STDERR, "usage: php tools/xlf-add.php <file.xlf> <locale> <texts.json>\n");
    exit(1);
}
$texts = json_decode((string) file_get_contents($json), true);
if (!is_array($texts)) {
    fwrite(STDERR, "invalid JSON in $json\n");
    exit(1);
}
$units = [];
if (is_file($file)) {
    $xml = simplexml_load_file($file);
    $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
    foreach ($xml->xpath('//x:trans-unit') as $unit) {
        $units[(string) $unit->source] = (string) $unit->target;
    }
}
foreach ($texts as $source => $target) {
    if ($target === null) {
        unset($units[(string) $source]);
    } else {
        $units[(string) $source] = (string) $target;
    }
}
ksort($units, SORT_STRING);
$e = static function (string $text): string {
    return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
};
$out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">' . "\n"
    . '  <file original="modules/cmi" source-language="en-US" target-language="' . $e($locale) . '" datatype="plaintext">' . "\n"
    . "    <body>\n";
foreach ($units as $source => $target) {
    $out .= '      <trans-unit id="' . md5($source) . '">' . "\n"
        . '        <source>' . $e($source) . "</source>\n"
        . '        <target>' . $e($target) . "</target>\n"
        . "      </trans-unit>\n";
}
$out .= "    </body>\n  </file>\n</xliff>\n";
if (!is_dir(dirname($file))) {
    mkdir(dirname($file), 0755, true);
}
file_put_contents($file, $out);
echo count($texts) . ' text(s) written, ' . count($units) . " in $file\n";
