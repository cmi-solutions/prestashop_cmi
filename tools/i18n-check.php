<?php
/**
 * Checks that every text of the module has a French translation.
 *
 *   php tools/i18n-check.php            list the texts missing from translations/fr-FR/*.xlf
 *
 * Texts are read from $this->trans() / ->t() / ->admin() calls (PHP) and {l s='' d=''} (Smarty).
 * Add a missing text to the matching .xlf file (source in English, target in French).
 */
$root = dirname(__DIR__);
$found = ['Modules.Cmi.Admin' => [], 'Modules.Cmi.Shop' => []];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $path = str_replace('\\', '/', (string) $file);
    if (preg_match('#/(\.git|tests|tools|vendor)/#', $path)) {
        continue;
    }
    $source = (string) file_get_contents($path);
    if (substr($path, -4) === '.php') {
        preg_match_all("/(?:->trans|\\\$this->t|->admin|->t)\(\s*'((?:[^'\\\\]|\\\\.)*)'(.{0,400}?)\)\s*[;,\]\)]/s", $source, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $domain = strpos($match[2], 'Modules.Cmi.Shop') !== false ? 'Modules.Cmi.Shop' : 'Modules.Cmi.Admin';
            $found[$domain][str_replace("\\'", "'", $match[1])] = $path;
        }
    } elseif (substr($path, -4) === '.tpl') {
        preg_match_all("/\{l s='((?:[^'\\\\]|\\\\.)*)'[^}]*d='(Modules\.Cmi\.(?:Admin|Shop))'/", $source, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $found[$match[2]][str_replace("\\'", "'", $match[1])] = $path;
        }
    }
}

$missing = 0;
foreach ($found as $domain => $texts) {
    $xlf = $root . '/translations/fr-FR/' . str_replace('.', '', $domain) . '.fr-FR.xlf';
    $known = [];
    $xml = is_file($xlf) ? simplexml_load_file($xlf) : false;
    if ($xml) {
        $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
        foreach ($xml->xpath('//x:trans-unit') as $unit) {
            if (trim((string) $unit->target) !== '') {
                $known[(string) $unit->source] = true;
            }
        }
    }
    foreach ($texts as $text => $path) {
        if (!isset($known[$text])) {
            ++$missing;
            echo "missing [$domain] $text  (" . substr($path, strlen($root) + 1) . ")\n";
        }
    }
}
echo $missing ? "$missing text(s) without a French translation\n" : "All texts have a French translation.\n";
exit($missing ? 1 : 0);
