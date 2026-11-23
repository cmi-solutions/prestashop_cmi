<?php

/**
 * Test bench: start PrestaShop from the command line the way bin/console does, Symfony kernel
 * included (modules need it on PrestaShop 9, and for some services on 8).
 */
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once '/var/www/html/autoload.php';
if (is_file('/var/www/html/.env') && class_exists(Symfony\Component\Dotenv\Dotenv::class)) {
    (new Symfony\Component\Dotenv\Dotenv())->usePutenv(false)->loadEnv('/var/www/html/.env');
}
require_once '/var/www/html/config/config.inc.php';

global $kernel;
$kernelClass = class_exists('AdminKernel') ? 'AdminKernel' : 'AppKernel';
$kernel = new $kernelClass('prod', false);
$kernel->boot();

$context = Context::getContext();
$context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
$context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
$context->link = new Link();
