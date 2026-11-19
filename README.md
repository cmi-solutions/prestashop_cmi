# CMI payment module for PrestaShop

A PrestaShop payment module for [CMI](https://www.cmi.co.ma/) (Centre Monétique Interbancaire, Morocco). Customers pay on CMI's secure hosted page, and merchants can confirm, void, refund and check payments directly from the PrestaShop order page.

Updating from version 1.x? Read the [changelog](CHANGELOG.md) first: version 2.0.0 changes how some things work.

## Supported versions

| Component | Minimum | Tested |
| --- | --- | --- |
| PrestaShop | 8.0 | 8.2.9 and 9.2.1 |
| PHP | 8.1 | 8.1 (PrestaShop 8) and 8.3 (PrestaShop 9) |
| PHP extensions | cURL, SimpleXML | |

PrestaShop 8 runs on PHP up to 8.1; PrestaShop 9 runs on PHP 8.1 to 8.4. The module's code is the same for both.

## Features

- **Secure hosted payment:** customers enter their card details on CMI's 3-D Secure page, never on your shop.
- **Automatic or manual capture:** capture the payment immediately, or pre-authorize it and confirm later.
- **Manage payments from PrestaShop** (Complete Kit): confirm, void, refund (full or partial) and check a payment's status at CMI, from the **CMI payment** panel of the order page.
- **Standard and offshore CMI stores:** payments in MAD on your standard store, and in EUR, USD, GBP, AED, CHF or SAR on your offshore store.
- **Other currencies:** orders in a currency your CMI store doesn't accept are converted to MAD with PrestaShop's exchange rates, show both amounts, and are refunded using the rate applied at payment.
- **Order number known by CMI:** each order is sent as a prefix followed by its reference, shown on the order page and in a **CMI** column of the orders list (with the payment's state).
- **Safe with several websites:** if one CMI account is used by several websites, the module never acts on another website's payment.
- **Stays in sync with the CMI back office:** a payment confirmed, voided or refunded in the CMI back office updates the order when you click **Check CMI**.
- **Automatic status checks** (Complete Kit) of orders left awaiting payment.
- **Old pre-authorization warning:** before confirming a pre-authorization older than 5 days, you're advised to inform the customer, to reduce the risk of chargebacks.
- **"Pay again"** for customers whose payment was declined or not completed.
- **English and French.**
- **Multistore:** each shop has its own settings.

## Installation

### 1. Download the module

1. Open the **[latest release](https://github.com/cmi-solutions/prestashop_cmi/releases/latest)**.
2. Under **Assets**, click **`cmi_prestashop_vX.Y.Z.zip`** (for example `cmi_prestashop_v2.0.0.zip`) to download it.

> [!IMPORTANT]
> - Don't download **Source code (zip)** or **Source code (tar.gz)**: they contain the whole project, not an installable module.
> - **Don't unzip the file:** PrestaShop installs the zip as it is. Safari on Mac may unzip downloads automatically; if you get a `cmi` folder instead of a zip, download it again with another browser.

### 2. Install it in PrestaShop

1. In your back office, go to **Modules > Module Manager**.
2. Click **Upload a module** at the top of the page, and select the zip you downloaded.
3. When PrestaShop shows **Module installed!**, click **Configure**.

To **update** from an earlier version, upload the new zip the same way: PrestaShop updates the module and keeps your settings.

## Configuration

In **Modules > Module Manager**, find **CMI** and click **Configure**.

### Checkout

| Setting | |
| --- | --- |
| Payment title | Name of the payment method at checkout, per language. Empty: "Pay securely by bank card with CMI". |
| Payment description | Optional text shown when the customer chooses CMI. |

### Payment setup

| Setting | |
| --- | --- |
| CMI kit | **Standard Kit:** customers pay on CMI's page; you manage payments in the CMI back office. **Complete Kit:** you can also confirm, void, refund and check payments from the order page, and orders left awaiting payment are checked automatically. It needs the API credentials provided by CMI. |
| Payment confirmation | **Automatic:** the payment is captured as soon as the customer pays. **Manual:** the payment is only pre-authorized; you confirm it later to capture the funds. |
| Order status after capture | Status given to an order once its payment is captured (default: Payment accepted). |
| Payment result | **Server to server** (recommended): CMI calls your shop after each payment. Your shop must be reachable from the internet. **Browser return only:** the result comes back with the customer, for shops CMI's servers cannot reach. In this mode, automatic confirmation captures the payment through the API, so it needs the Complete Kit. |
| 3-D Secure | Asks the cardholder to authenticate. Only turn it off if CMI asked you to. |
| Automatic redirect | Sends customers back to the shop as soon as the payment is finished. |
| Payment page language | The customer's language, or always French, English or Arabic. |
| Order ID prefix | Generated at installation. See [Order number](#order-number). |

### Connection to CMI

| Setting | |
| --- | --- |
| Currencies of your CMI store | **MAD:** your standard CMI store. **Other currencies:** your offshore CMI store, which charges them in their own currency. |
| Client ID (MAD store) | Provided by CMI, for example `600XXXXXX`. |
| Client ID (offshore store) | Only when your CMI store accepts foreign currencies. |
| Payment page URL | `https://payment.cmi.co.ma/fim/est3Dgate` (test: `https://testpayment.cmi.co.ma/fim/est3Dgate`). |
| Store key (hash key) | The secret key set in your CMI merchant center. It must not contain the word "SKS". Once saved, it is never shown again: leave the field empty to keep it. |

### API credentials (Complete Kit)

| Setting | |
| --- | --- |
| CMI API URL | Provided by CMI, for example `https://payment.cmi.co.ma/fim/api`. It is **not** the payment page URL. |
| API username, API password | Provided by CMI. |

Your shop must use **HTTPS** (Shop Parameters > General > Enable SSL).

## How orders and payments work

When the customer chooses CMI, the order is created with the status **Awaiting CMI payment** and the customer is sent to CMI's page. Then:

| What happens | Automatic confirmation | Manual confirmation |
| --- | --- | --- |
| Payment approved | **Payment accepted** (or the status you chose): CMI captured it | **CMI payment authorized (to confirm)**: confirm it to capture the funds, or void it |
| Payment declined | **Payment error**; the customer can pay again | same |
| Customer clicks Cancel on CMI's page | **Canceled**, only if CMI shows no payment | same |
| No result from CMI | stays **Awaiting CMI payment**; the automatic checks (Complete Kit) update it | same |

The order page shows a **CMI payment** panel with the OrderId known by CMI, the amount CMI charged, the refunds, the payment's history, and, with the Complete Kit, the **Confirm**, **Void**, **Refund** and **Check CMI** buttons.

- **Confirm** captures a pre-authorization. If it is older than 5 days, you're advised to inform the customer first.
- **Void** cancels a pre-authorization: the customer is not charged and the order is cancelled.
- **Refund** returns all or part of a captured payment to the customer's card. The amount is in the currency CMI charged. No credit slip is created; create one in PrestaShop if your accounting needs it, without refunding again.
- **Check CMI** reads the payment's state at CMI and updates the order, including refunds made in the CMI back office.

Before any of these, the module checks with CMI that the payment belongs to this order. Refunds made from PrestaShop's own **Partial refund** or **Return products** buttons are not sent to CMI: use the panel's **Refund** button.

### Order number

Orders are sent to CMI as the **Order ID prefix** followed by the order reference, for example `PS7F3A-XKBKNABJK`, and appear this way in the CMI back office. If several websites use the same CMI account, give each a different prefix. Changing the prefix only affects new orders. To find an order from the OrderId shown in the CMI back office, type the part after the prefix (the order reference) in the **Reference** filter of the orders list. Orders paid with version 1.x keep their old OrderId (the cart number).

### Other currencies

- **MAD orders** are charged in MAD on your standard CMI store.
- **Orders in a currency your offshore CMI store accepts** are charged in that currency on the offshore store.
- **Other orders** are converted to MAD with PrestaShop's exchange rates (**International > Localization > Currencies**) and charged on your standard store; CMI's page shows both amounts. The rate is saved with the order, and refunds use it. If the rate is missing, or still set to 1, CMI is not offered for that currency.

You are responsible for the accuracy of the exchange rates.

## Automatic checks

With the Complete Kit, an order left **Awaiting CMI payment** (the CMI callback failed, or the customer was declined and left) is checked with CMI about 15 minutes, 1 h 15 and 7 h 15 after the customer was sent to CMI. A pre-authorized payment moves the order to **CMI payment authorized (to confirm)**, a captured one marks it paid, a payment still declined at the last check marks it **Payment error**, and an order with no payment at all at the last check is cancelled. These checks only read CMI's records: they never confirm, void or refund.

PrestaShop has no built-in scheduler: ask your host to call the **cron address** shown on the settings page every 5 minutes, or add it to the **Cron tasks manager** module. In a multistore, add the address of each shop.

## Troubleshooting

- **CMI is not shown at checkout:** the connection settings are incomplete, the customer's currency is not allowed for the module (**Payment > Preferences**), or the exchange rate to MAD is missing.
- **"To pay by card with CMI, please enter your name in Latin letters":** CMI only receives names in Latin letters; the customer must edit their invoice address.
- **The order stays Awaiting CMI payment:** CMI could not reach your shop. Make sure it is reachable from the internet over HTTPS (also in maintenance mode, the module's addresses stay open to CMI), then use **Check CMI**.
- **The actions are not shown:** choose the Complete Kit and enter the API credentials.
- Technical messages are written to **Advanced Parameters > Logs**.

## Development

```
php tests/run-tests.php
```

runs the unit tests of the signature, API, payment-state, ownership and currency rules.

`tests/e2e` holds a test bench with PrestaShop 8.2 and 9.2 and a mock of CMI (payment page and XML API); see [tests/e2e/README.md](tests/e2e/README.md).

The release zip is built by GitHub Actions when a release is published (`git archive`, without tests and tooling).
