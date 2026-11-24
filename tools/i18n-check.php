<?php

/**
 * Checks that every text of the module has a French translation, and that every other language
 * (translations/<locale>/) translates every French text with the same %placeholders%.
 *
 *   php tools/i18n-check.php            list the texts missing from translations/fr-FR/*.xlf
 *   php tools/i18n-check.php unused     also list translations no longer used by the code
 *
 * Texts are read from $this->trans() / ->t() / ->admin() calls (PHP) and {l s='' d=''} (Smarty).
 * Add a missing text to the matching .xlf file (source in English, target in French).
 */
$root = dirname(__DIR__);
$found = ['Modules.Cmi.Admin' => [], 'Modules.Cmi.Shop' => []];
$code = '';
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    $path = str_replace('\\', '/', (string) $file);
    if (preg_match('#/(\.git|tests|tools|vendor)/#', $path)) {
        continue;
    }
    $source = (string) file_get_contents($path);
    if (preg_match('/\.(php|tpl|js)$/', $path)) {
        $code .= $source;
    }
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
    if (($argv[1] ?? '') === 'unused') {
        foreach (array_keys($known) as $text) {
            // Order state names are translated through a variable (Installer::stateName()).
            // Texts passed through a variable or nested in another call: look for the literal.
            if (!isset($texts[$text]) && !in_array($text, ['Awaiting CMI payment', 'CMI payment authorized (to confirm)'], true)
                && strpos($code, "'" . str_replace("'", "\'", $text) . "'") === false) {
                echo "unused  [$domain] $text
";
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
// Other languages (Arabic...): every French text translated, with the same %placeholders%.
$load = static function (string $file): array {
    $units = [];
    $xml = is_file($file) ? simplexml_load_file($file) : false;
    if ($xml) {
        $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
        foreach ($xml->xpath('//x:trans-unit') as $unit) {
            $units[(string) $unit->source] = trim((string) $unit->target);
        }
    }

    return $units;
};
foreach (glob($root . '/translations/*', GLOB_ONLYDIR) as $dir) {
    $locale = basename($dir);
    if ($locale === 'fr-FR') {
        continue;
    }
    foreach (['ModulesCmiAdmin', 'ModulesCmiShop'] as $base) {
        $french = $load($root . "/translations/fr-FR/$base.fr-FR.xlf");
        $other = $load("$dir/$base.$locale.xlf");
        foreach (array_keys($french) as $text) {
            if (($other[$text] ?? '') === '') {
                ++$missing;
                echo "missing [$locale $base] $text\n";
                continue;
            }
            preg_match_all('/%[a-z_]+%/', $text, $expected);
            preg_match_all('/%[a-z_]+%/', $other[$text], $found);
            sort($expected[0]);
            sort($found[0]);
            if ($expected[0] !== $found[0]) {
                ++$missing;
                echo "placeholders differ [$locale $base] $text\n";
            }
        }
    }
}
echo $missing ? "$missing translation problem(s)\n" : "All texts are translated (French and every other language).\n";
exit($missing ? 1 : 0);
