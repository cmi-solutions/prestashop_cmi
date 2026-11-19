# CMI payment module for PrestaShop

A PrestaShop payment module for [CMI](https://www.cmi.co.ma/) (Centre Monétique Interbancaire, Morocco). Customers pay on CMI's secure hosted page, and merchants can confirm, void, refund and check payments directly from the PrestaShop order page.

## Which version do I need?

There is one module package per PrestaShop version. Check your version in your back office (bottom of the menu, or **Advanced Parameters > Information**), then download the matching package. PrestaShop refuses to install a package made for another version.

| Your PrestaShop | Module | Download | Code and documentation |
| --- | --- | --- | --- |
| **9.x** (9.0, 9.1, 9.2...) | 2.0.0 | Release **[CMI 2.0.0 for PrestaShop 9](https://github.com/cmi-solutions/prestashop_cmi/releases/tag/v2.0.0-ps9)**: file `cmi_prestashop_v2.0.0-ps9.zip` | branch [`version_9`](https://github.com/cmi-solutions/prestashop_cmi/tree/version_9) |
| **8.x** (8.0, 8.1, 8.2...) | 2.0.0 | Release **[CMI 2.0.0 for PrestaShop 8](https://github.com/cmi-solutions/prestashop_cmi/releases/tag/v2.0.0-ps8)**: file `cmi_prestashop_v2.0.0-ps8.zip` | branch [`version_8`](https://github.com/cmi-solutions/prestashop_cmi/tree/version_8) |
| **10.x** | not available yet | | |
| 1.7 and older | not supported | | |

All releases: **[Releases](https://github.com/cmi-solutions/prestashop_cmi/releases)**. Newer module versions for the same PrestaShop version are released under the same naming: `vX.Y.Z-ps9`, `vX.Y.Z-ps8`.

## Install or update

1. Open the release for your PrestaShop version (table above).
2. Under **Assets**, download the `cmi_prestashop_...zip` file.
   - Don't download **Source code (zip)** or **Source code (tar.gz)**: they contain the whole project, not an installable module.
   - **Don't unzip the file:** PrestaShop installs the zip as it is. Safari on Mac may unzip downloads automatically; if you get a `cmi` folder instead of a zip, download it again with another browser.
3. In your back office, go to **Modules > Module Manager**, click **Upload a module**, and select the zip.
4. Click **Configure** and enter the details provided by CMI.

Updating from version 1.x? Upload the new zip the same way: your settings are kept. Read the changelog of your version first ([PrestaShop 9](https://github.com/cmi-solutions/prestashop_cmi/blob/version_9/CHANGELOG.md), [PrestaShop 8](https://github.com/cmi-solutions/prestashop_cmi/blob/version_8/CHANGELOG.md)): version 2.0.0 changes how some things work. Then test a payment, a confirmation and a refund on CMI's test environment.

The configuration, the order statuses and the payment management are described in the README of each version ([PrestaShop 9](https://github.com/cmi-solutions/prestashop_cmi/blob/version_9/README.md), [PrestaShop 8](https://github.com/cmi-solutions/prestashop_cmi/blob/version_8/README.md)).

## Features (2.0.0)

- Secure hosted payment on CMI's 3-D Secure page; automatic capture, or pre-authorization confirmed later.
- Confirm, void, refund (full or partial) and check payments from the order page (Complete Kit), after checking with CMI that the payment belongs to the order.
- Standard (MAD) and offshore CMI stores; orders in other currencies converted to MAD, refunded with the rate applied at payment.
- Sync with the CMI back office, automatic status checks of unpaid orders, "Pay again" for customers.
- English and French; multistore.

## Older versions

Version 1.x is no longer maintained. Its code remains available in the releases [Prestashop version 9](https://github.com/cmi-solutions/prestashop_cmi/releases/tag/prestashop_v9) and [Prestashop version 8](https://github.com/cmi-solutions/prestashop_cmi/releases/tag/prestashop_v8).

## For developers

- Each PrestaShop version has its own branch (`version_8`, `version_9`, later `version_10`). The module code is the same in all of them; only the PrestaShop versions they accept and their documentation differ. A fix must be applied to every branch.
- Releases are tagged `vX.Y.Z-ps8` / `vX.Y.Z-ps9` on their branch. Publishing a release attaches the installable zip automatically (GitHub Actions).
- Each branch holds unit tests and a Docker test bench (PrestaShop 8 and 9, and a mock of CMI): see `tests/e2e/README.md` in the branch.
- This `main` branch only holds this page.
