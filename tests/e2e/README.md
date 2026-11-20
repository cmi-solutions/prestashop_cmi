# Test bench

Two shops and a mock of CMI, to test the module end to end without CMI credentials:

| Service | Address | |
| --- | --- | --- |
| `ps8` | http://localhost:8081 (admin: `/admin-dev`) | PrestaShop 8.2.9, PHP 8.1 |
| `ps9` | http://localhost:8082 (admin: `/admin-dev`) | PrestaShop 9.2.1, PHP 8.3 |
| `cmi-app` | http://localhost:8090/fim/est3Dgate | mock payment page (Approve / Decline / Cancel) |
| `cmi` | https://cmi.test/fim/api (inside the network) | mock XML API, HTTPS with a local CA the shops trust |

Back-office login: `admin@example.test` / `Test-admin-2026` (test values of this bench only).

The mock signs callbacks with the module's own signature code and keeps its transactions in a file inside its container, so captures, voids, refunds and status queries behave like CMI's. Never deploy it.

## Use

```
cd tests/e2e
docker compose up -d          # first start installs both shops (a few minutes)
./run.sh                      # deploy the working tree, configure, run every scenario on ps8 (this branch's PrestaShop)
docker compose exec -u www-data ps8 php /cmi-tests/harness.php manual currency   # some scenarios
./upgrade.sh ps8              # upgrade test from 1.x (tag prestashop_v8); replaces the shop's module
CMI_PACKAGE=/path/to/cmi_prestashop_v2.0.0-ps8.zip ./run.sh   # test a release zip instead of the working tree
docker compose down -v        # remove everything
```

This branch's package only installs on PrestaShop 8; the `ps9` shop is there to check that it is refused on 9 (the `version_9` branch tests PrestaShop 9).

`configure.php` sets the module up against the mock (Complete Kit, automatic confirmation, server callback; pass `mode=manual`, `callback=browser`, `kit=standard`, `currencies=504,978` to change it). It also makes sure MAD and EUR exist (1 EUR = 10.8 MAD), lets the demo carriers deliver to Morocco, and disables e-mails.

`upgrade.sh` installs 1.1.0, writes 1.x settings and a 1.x paid order, uploads the working tree over it, runs PrestaShop's upgrade, and checks the settings, order states, tables, hooks, deleted files, the 1.x order, and a 1.x payment answered by CMI after the upgrade.

## Scenarios (`harness.php`)

| Scenario | Checks |
| --- | --- |
| `automatic` | order awaiting payment, signed form accepted by the mock, `ACTION=POSTAUTH`, order paid with TransId and card, return to the order confirmation, repeated / tampered / unsigned callbacks, partial then full refund |
| `manual` | pre-authorization, Confirm, second Confirm, Void |
| `decline` | declined payment, result page with "Pay again", approved retry, late decline ignored |
| `browser-first` | customer back before the callback, then the callback captures |
| `browser-mode` | "Browser return only": capture through the API on return |
| `cancel` | Cancel on CMI's page without and after payment, wrong key |
| `foreign` | another website's transaction under the same OrderId is never confirmed or refunded |
| `pending` | lost callback found by the cron check, wrong cron token, abandoned order cancelled |
| `back-office` | refund made in the CMI back office blocks a refund until Check CMI records it; capture made in the back office |
| `currency` | EUR order charged in MAD with a frozen rate, refund; 1.x offshore settings ignored (still MAD); rate never set up refused |
| `custom-status` | settings form: custom status after capture created (paid, invoice, e-mail), used for a captured order, renamed, then a standard status again |
| `names` | names in Arabic only refused |
| `admin-panel` | order page panel variables and template |
