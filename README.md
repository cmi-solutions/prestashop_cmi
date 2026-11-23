# CMI payment module for PrestaShop

A PrestaShop payment module for [CMI](https://www.cmi.co.ma/) (Centre Monétique Interbancaire, Morocco). Cardholders pay on CMI's secure hosted page, and merchants can confirm, void, refund and check payments directly from the PrestaShop order page.

Updating from version 1.x? Read the [changelog](CHANGELOG.md) first: version 2.0.0 changes how some things work.

## Which version do I need?

There is one package per PrestaShop version. Download the one matching your shop from the **[releases](https://github.com/cmi-solutions/prestashop_cmi/releases)**; PrestaShop refuses to install a package made for another version.

| Your PrestaShop | Branch | Release | File to download |
| --- | --- | --- | --- |
| 9.x | [`version_9`](https://github.com/cmi-solutions/prestashop_cmi/tree/version_9) | `v2.0.0-ps9` | `cmi_prestashop_v2.0.0-ps9.zip` |
| 8.x | [`version_8`](https://github.com/cmi-solutions/prestashop_cmi/tree/version_8) | `v2.0.0-ps8` | `cmi_prestashop_v2.0.0-ps8.zip` |
| 10.x | not available yet | | |

This branch is the **PrestaShop 9** package.

## Requirements

| Component | Supported | Tested |
| --- | --- | --- |
| PrestaShop | 9.0 to 9.x | 9.2.1 |
| PHP | 8.1 to 8.4 (the versions PrestaShop 9 supports) | 8.3 and 8.4 |
| PHP extensions | cURL, SimpleXML | |
| Shop | HTTPS (**Shop Parameters > General > Enable SSL**), the Moroccan dirham (MAD) installed | |
| CMI | A CMI merchant account: Client ID and store key; for the Complete Kit, the API credentials | |

## Features

- **Secure hosted payment:** cardholders enter their card details on CMI's 3-D Secure page, never on your shop.
- **Test and production platforms:** one setting switches between CMI's test platform and real payments; the settings page and every order show when the shop is in test mode.
- **Automatic or manual capture:** capture the payment immediately, or pre-authorize it and confirm later.
- **Manage payments from PrestaShop** (Complete Kit): confirm, void, refund (full or partial) and check a payment's status at CMI, from the **CMI payment** panel of the order page.
- **Payments in MAD:** orders in other currencies are converted to MAD with PrestaShop's exchange rates, show both amounts, and are refunded using the rate applied at payment.
- **Order number known by CMI:** each order is sent as a prefix followed by its reference, shown on the order page and in a **CMI** column of the orders list (with the payment's state).
- **Safe with several websites:** if one CMI account is used by several websites, the module never acts on another website's payment.
- **Stays in sync with the CMI back office:** a payment confirmed, voided or refunded in the CMI back office updates the order when you click **Check CMI**.
- **Automatic status checks** (Complete Kit) of orders left awaiting payment.
- **Old pre-authorization warning:** before confirming a pre-authorization older than 5 days, you're advised to inform the cardholder, to reduce the risk of chargebacks.
- **"Pay again"** for cardholders whose payment was declined or not completed.
- **English, French and Arabic.**
- **GDPR:** works with PrestaShop's GDPR module (export and deletion of a customer's data).
- **Multistore:** each shop has its own settings.
- **CMI logo** in the right column of your theme (if it has one); move or remove it in **Design > Positions**.

## Installation

### 1. Download the module

1. Open the **[releases](https://github.com/cmi-solutions/prestashop_cmi/releases)** and pick the newest one for your PrestaShop version: its name ends with **-ps9** for PrestaShop 9 (for example `v2.0.0-ps9`), **-ps8** for PrestaShop 8.
2. Under **Assets**, click **`cmi_prestashop_vX.Y.Z-ps9.zip`** (for example `cmi_prestashop_v2.0.0-ps9.zip`) to download it.

> [!IMPORTANT]
> - Don't download **Source code (zip)** or **Source code (tar.gz)**: they contain the whole project, not an installable module.
> - **Don't unzip the file:** PrestaShop installs the zip as it is. Safari on Mac may unzip downloads automatically; if you get a `cmi` folder instead of a zip, download it again with another browser.

### 2. Install it in PrestaShop

1. In your back office, go to **Modules > Module Manager**.
2. Click **Upload a module** at the top of the page, and select the zip you downloaded.
3. When PrestaShop shows **Module installed!**, click **Configure**.

To **update** from an earlier version, upload the new zip the same way: PrestaShop updates the module and keeps your settings.

## Configuration

In **Modules > Module Manager**, find **CMI** and click **Configure**. A new installation starts on CMI's **test** platform: see [Test mode](#test-mode) before taking real orders.

### Checkout

| Setting | |
| --- | --- |
| Payment title | Name of the payment method at checkout, per language. Empty: "Pay securely by bank card with CMI". |
| Payment description | Optional text shown when the cardholder chooses CMI. |

### Payment setup

| Setting | |
| --- | --- |
| CMI kit | **Standard Kit:** cardholders pay on CMI's page; the merchant manages payments in the CMI back office. **Complete Kit:** the merchant can also confirm, void, refund and check payments from the order page, and orders left awaiting payment are checked automatically. It needs the API credentials provided by CMI. |
| Payment confirmation | **Automatic:** the payment is captured as soon as the cardholder pays. **Manual:** the payment is only pre-authorized; the merchant confirms it later to capture the funds. |
| Order status after capture | Status given to an order once its payment is captured (default: Payment accepted). Choose **Custom** to create your own status: give it a name and a color; it counts as paid, gives an invoice and sends the "payment accepted" e-mail. Choose **Custom** again to rename it. |
| Payment result | **Server to server** (recommended): CMI calls your shop after each payment ([callback](#callback-address)); your shop must be reachable from the internet. The page the cardholder comes back to only shows the result. **Browser return only:** the result comes back with the cardholder, for shops CMI's servers cannot reach. In this mode, automatic confirmation captures the payment through the API, so it needs the Complete Kit. |
| Automatic redirect | Sends cardholders back to the shop as soon as the payment is finished. |
| Payment page language | The customer's language, or always French, English or Arabic. |
| Order ID prefix | Generated at installation. See [Order number](#order-number). |
| Delete all CMI data on uninstall | Off by default. See [Uninstallation](#uninstallation). |

### Connection to CMI

| Setting | |
| --- | --- |
| Client ID | Provided by CMI, for example `600XXXXXX`. |
| CMI platform | **Test** (no real payment) or **Production** (real payments). Choosing a platform fills in its payment page and API addresses. |
| Payment page URL | Filled in from the platform: `https://payment.cmi.co.ma/fim/est3Dgate` (production) or `https://testpayment.cmi.co.ma/fim/est3Dgate` (test). Change it only if CMI gives you another address. |
| Store key (hash key) | The secret key set in your CMI merchant center. It must not contain the word "SKS". Once saved, it is never shown again: leave the field empty to keep it. |

### API credentials (Complete Kit)

| Setting | |
| --- | --- |
| CMI API URL | Provided by CMI, for example `https://payment.cmi.co.ma/fim/api` (filled in from the platform when empty). It is **not** the payment page URL. |
| API username, API password | Provided by CMI. |

## Test mode

Test the module on CMI's test platform before taking real orders. No card is charged.

1. Ask CMI for **test credentials** (test Client ID, store key and, for the Complete Kit, API credentials) and the **test cards** to use with them. Use only the test cards CMI gives you.
2. In **Connection to CMI**, choose **CMI platform: Test** and enter the test credentials. The settings page then shows a "Test mode" warning, and the **CMI payment** panel of each order shows a **Test mode** badge.
3. Place orders on your shop and pay with the test cards: an approved payment, a declined payment, and a click on **Cancel** on CMI's page. With the Complete Kit, try **Confirm**, **Void**, **Refund** and **Check CMI** from the order page.
4. When everything works, choose **CMI platform: Production**, enter your production credentials, and save. The test-mode warning disappears.

> [!WARNING]
> Orders paid in test mode were not really paid. Cancel them, or delete them, before opening the shop.

## Callback address

In **Server to server** mode, CMI calls your shop after each payment to report the result (the callback). The module sends its address to CMI with every payment, so there is nothing to type in the CMI merchant center. The addresses are:

| Address | Used for |
| --- | --- |
| `https://<your-shop>/module/cmi/callback` | CMI's callback (server to server) |
| `https://<your-shop>/module/cmi/return` | The cardholder's return from CMI's page (shows the result) |
| `https://<your-shop>/module/cmi/cancel` | The **Cancel** link on CMI's page |
| `https://<your-shop>/module/cmi/cron?token=…` | The automatic checks (the full address is on the settings page) |

Replace `<your-shop>` with your shop's address. With several languages the address may start with the language, for example `https://<your-shop>/fr/module/cmi/callback`; without friendly URLs it is `https://<your-shop>/index.php?fc=module&module=cmi&controller=callback`.

For the callback to work, your shop must use **HTTPS** and be **reachable from the internet** (not only from your office network, nor behind a login or an IP filter). The callback, return and cron addresses stay open in maintenance mode. If CMI cannot reach your shop, use **Browser return only** mode, or ask CMI which addresses they need you to allow.

## How orders and payments work

When the cardholder chooses CMI, the order is created with the status **Awaiting CMI payment** and the cardholder is sent to CMI's page. Then:

| What happens | Automatic confirmation | Manual confirmation |
| --- | --- | --- |
| Payment approved | **Payment accepted** (or the status you chose): CMI captured it | **CMI payment authorized (to confirm)**: confirm it to capture the funds, or void it |
| Payment declined | **Payment error**; the cardholder can pay again | same |
| Cardholder clicks Cancel on CMI's page | **Canceled**, only if CMI shows no payment | same |
| No result from CMI | stays **Awaiting CMI payment**; the automatic checks (Complete Kit) update it | same |

In **Server to server** mode, only CMI's callback (or the automatic checks) updates the order. If the cardholder comes back before the callback has arrived, the page says the payment is being confirmed, and doesn't offer to pay again.

The order page shows a **CMI payment** panel with the OrderId known by CMI, the amount CMI charged, the refunds, the payment's history, and, with the Complete Kit, the **Confirm**, **Void**, **Refund** and **Check CMI** buttons.

- **Confirm** captures a pre-authorization. If it is older than 5 days, you're advised to inform the cardholder first.
- **Void** cancels a pre-authorization: the cardholder is not charged and the order is cancelled.
- **Refund** returns all or part of a captured payment to the cardholder's card. The amount is in the currency CMI charged. No credit slip is created; create one in PrestaShop if your accounting needs it, without refunding again.
- **Check CMI** reads the payment's state at CMI and updates the order, including refunds made in the CMI back office.

Before any of these, the module checks with CMI that the payment belongs to this order. Refunds made from PrestaShop's own **Partial refund** or **Return products** buttons are not sent to CMI: use the panel's **Refund** button.

Every result from CMI is checked before it changes an order: its signature (made with your store key), its amount and its currency must match what the shop sent.

### Order number

Orders are sent to CMI as the **Order ID prefix** followed by the order reference, for example `PS7F3A-XKBKNABJK`, and appear this way in the CMI back office. If several websites use the same CMI account, give each a different prefix. Changing the prefix only affects new orders. To find an order from the OrderId shown in the CMI back office, type the part after the prefix (the order reference) in the **Reference** filter of the orders list. Orders paid with version 1.x keep their old OrderId (the cart number).

### Other currencies

CMI charges in MAD. Payments always go through CMI's 3-D Secure page.

- **MAD orders** are charged as they are.
- **Orders in another currency** are converted to MAD with PrestaShop's exchange rates (**International > Localization > Currencies**); CMI's page shows both amounts. The rate is saved with the order, and refunds use it. If the rate is missing, or still set to 1, CMI is not offered for that currency.

You are responsible for the accuracy of the exchange rates.

## Automatic checks

With the Complete Kit, an order left **Awaiting CMI payment** (the CMI callback failed, or the cardholder was declined and left) is checked with CMI about 15 minutes, 1 h 15 and 7 h 15 after the cardholder was sent to CMI. A pre-authorized payment moves the order to **CMI payment authorized (to confirm)**, a captured one marks it paid, a payment still declined at the last check marks it **Payment error**, and an order with no payment at all at the last check is cancelled. These checks only read CMI's records: they never confirm, void or refund.

PrestaShop has no built-in scheduler: ask your host to call the **cron address** shown on the settings page every 5 minutes, or add it to the **Cron tasks manager** module. In a multistore, add the address of each shop.

## Troubleshooting

- **CMI is not shown at checkout:** the connection settings are incomplete, the customer's currency is not allowed for the module (**Payment > Preferences**), or the exchange rate to MAD is missing.
- **"To pay by card with CMI, please enter your name in Latin letters":** CMI only receives names in Latin letters; the cardholder must edit their invoice address.
- **CMI's page shows "Marchand ne supporte pas ce modèle de paiement" (3D-1007):** your CMI account does not accept this payment model. The module always uses CMI's 3-D Secure hosted page; ask CMI to enable it for your Client ID.
- **CMI's page refuses the payment, or the order logs "invalid signature":** the store key in the module is not the one set in your CMI merchant center, or the Client ID and store key are from different platforms (test and production). Enter the store key again.
- **The order stays Awaiting CMI payment:** CMI could not reach your shop. Check the [callback address](#callback-address) requirements, then use **Check CMI**.
- **The actions are not shown:** choose the Complete Kit and enter the API credentials.
- Technical messages are written to **Advanced Parameters > Logs**, and each payment's history is in the **CMI payment** panel of its order.

## Uninstallation

1. In **Modules > Module Manager**, find **CMI**, open its menu and click **Uninstall**.
2. Confirm.

By default, uninstalling deletes the module's settings (credentials included) and keeps:

- the CMI payment history of your orders (the `cmi_transaction` and `cmi_transaction_log` tables);
- the order statuses the module created (**Awaiting CMI payment**, **CMI payment authorized (to confirm)**, and your **Custom** status), still used by your orders;
- the Order ID prefix, so a reinstalled module finds the payments of past orders again.

To delete everything, turn on **Delete all CMI data on uninstall** in **Payment setup** and save before uninstalling: the payment history and every CMI setting are deleted, and the order statuses are marked deleted (orders that had them keep their history). This cannot be undone.

Orders themselves always stay in PrestaShop.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for what changed in each version.

## Support

- **Your CMI merchant account** (credentials, test cards, store key, the payment models enabled for your shop): contact CMI through [www.cmi.co.ma](https://www.cmi.co.ma/).
- **The module** (installation, a problem, a question): open an issue on [github.com/cmi-solutions/prestashop_cmi](https://github.com/cmi-solutions/prestashop_cmi/issues), with your PrestaShop and PHP versions, the module version and the messages of **Advanced Parameters > Logs** (never send your store key or API password).

## License

Released under the [Academic Free License 3.0 (AFL-3.0)](LICENSE), like PrestaShop's own modules.

## Development

```
php tests/run-tests.php
php tools/i18n-check.php
```

run the unit tests of the signature, API, payment-state, ownership and currency rules, and check that every text is translated (French and Arabic, same placeholders).

`tests/e2e` holds a test bench with PrestaShop 8.2 and 9.2 and a mock of CMI (payment page and XML API); see [tests/e2e/README.md](tests/e2e/README.md).

The release zip is built by GitHub Actions when a release is published (`git archive`, without tests and tooling).
