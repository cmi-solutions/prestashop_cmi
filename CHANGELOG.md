# Changelog

## 2.0.0

A major update, rewritten from version 1.1.0. Some things now work differently, so please read **Before you update** and **Changes to how the module works** before installing it.

### Before you update

- This package is for **PrestaShop 9.0 to 9.x** (tested on 9.2.1 with PHP 8.3 and 8.4). For PrestaShop 8, use the `-ps8` package (branch `version_8`). PrestaShop 1.7 is no longer supported.
- Your settings are kept: they are moved to the new settings page during the update.
- After updating, test a payment, a confirmation and a refund using CMI's test environment.
- With the Complete Kit, set up the cron job shown on the settings page (see **Automatic checks** in the README).

### New

- **Manage payments from PrestaShop (Complete Kit):** confirm, void, refund (full or partial) and check a payment's status at CMI, from a **CMI payment** panel on the order page.
- **Safety checks before every action:** before confirming, voiding or refunding, the module checks with CMI that the payment belongs to the order, so it never acts on another website's payment.
- **Stays in sync with the CMI back office:** a payment confirmed, voided or refunded in the CMI back office updates the order when you click **Check CMI**. A refund from PrestaShop is refused until the order is up to date, so it can't use a wrong balance.
- **Automatic status checks** (Complete Kit), about 15 minutes, 1 h 15 and 7 h 15 after the customer was sent to CMI, for orders left awaiting payment: a pre-authorized payment moves the order to **CMI payment authorized (to confirm)**, a captured one marks it paid, and a payment still declined, or never made, at the last check marks it **Payment error** or cancels it.
- **Refunds in other currencies** are calculated in the currency CMI charged, using the rate applied at payment, so they match what CMI charged.
- **Order number known by CMI:** orders are sent to CMI as a prefix followed by the order reference (for example `PS7F3A-XKBKNABJK`), shown in the order page and in a new **CMI** column of the orders list, with the payment's state. To find an order from its CMI OrderId, type the part after the prefix in the list's **Reference** filter.
- **Old pre-authorization warning:** before confirming a pre-authorization older than 5 days, you're advised to inform the customer, to reduce the risk of chargebacks.
- **"Pay again"** for the customer when a card payment is declined or not completed, on the result page and in the order details of their account.
- **Clear payment result for customers** on the order confirmation page ("Payment successful", "Payment authorized"...).
- **Customer cancel on CMI's page:** cancels the order only when CMI shows no payment for it.
- **Order status after capture** can be chosen in the settings, or created: **Custom** creates your own status (name and color), counted as paid.
- **Payment title and description** at checkout can be set per language.
- **Show/hide button** on the store key and API password fields.
- **Settings page warnings:** when CMI is not offered at checkout yet (missing Client ID, store key or payment page URL), when the Complete Kit lacks its API credentials, when MAD is missing, and when the shop doesn't use HTTPS.
- **English and French**, following the language of the back office and of the customer.
- **Order history** of each CMI payment (callbacks, actions, checks) in the order page panel.

### Changes to how the module works

| Change | What you may need to do |
| --- | --- |
| The order is created when the customer is sent to CMI, with the status **Awaiting CMI payment**, instead of after the payment. The customer receives PrestaShop's order confirmation e-mail at that moment, as with a bank transfer | Nothing. Unpaid orders are marked **Payment error** or cancelled by the automatic checks (Complete Kit), or by the customer's **Cancel** on CMI's page |
| The CMI OrderId is the prefix followed by the order reference, no longer the cart ID | If you use one CMI account on several websites, give each a different prefix. Orders paid with 1.x keep their old OrderId |
| In **Manual** confirmation mode, an approved payment moves the order to **CMI payment authorized (to confirm)**, no longer to **Payment accepted** (1.x marked it paid although it was only pre-authorized) | Confirm the payment from the order page (Complete Kit) or in the CMI back office before shipping |
| The **Complete Kit** is a new setting. The API is used only with the Complete Kit and its credentials. Updated shops that had the API credentials start with the Complete Kit | Nothing, or choose the Standard Kit if you don't want the module to call the CMI API |
| The **Callback** setting is now **Payment result**: *Server to server* (was *Server-to-server*) or *Browser return only* (was *Redirection automatique*) | Nothing |
| **Automatic redirect** is an on/off switch (was Automatique/Manuelle) | Nothing |
| The **3-D Secure** setting (Authentification du titulaire de la carte) is removed: every payment goes through CMI's 3-D Secure page, which CMI requires (without it CMI refuses the payment: "Marchand ne supporte pas ce modèle de paiement") | Nothing |
| Payments are made in MAD only, on your standard CMI store: orders in other currencies are converted to MAD. The **offshore store** settings (Client ID offshore, CMI store currencies) are no longer offered; values saved with 1.x are kept but not used | If you used an offshore CMI store, payments now go to your MAD store: check with CMI before updating |
| CMI is not offered for an order in another currency when its exchange rate is missing, or still set to 1 (never set up) | Set up your rates in **International > Localization > Currencies** |
| A store key containing the word "SKS" can't be saved | Ask CMI for a new key if yours contains it |
| A customer whose name has no Latin letter (for example typed in Arabic) is asked to enter it in Latin letters: CMI receives names in Latin letters only | Nothing |
| Uninstalling the module deletes the CMI connection settings (credentials). Orders, their payment history and the order statuses are kept | Enter them again if you reinstall the module |
| The CMI logo is no longer shown with an icon font; the module no longer loads Font Awesome | Nothing |
| Texts are in English with a French translation, using PrestaShop's translation system | Custom translations made for 1.x need to be redone in **International > Translations** |

### Fixes and security

- A callback with a wrong signature is refused and no longer marks the order as failed: anyone could previously fail any order by posting a fake callback.
- Signatures are compared in constant time, and the payment form is built only on the server: 1.x signed whatever fields the browser sent.
- The CMI API is called over verified HTTPS only, with every XML value escaped, and its answer is checked: 1.x ignored it.
- The amount approved by CMI is checked against the amount sent before an order is marked paid.
- A late or repeated message from CMI can no longer change an order that was already paid, authorized, voided or refunded.
- A callback and the customer's return arriving at the same time are processed one after the other.
- The settings page is protected by PrestaShop's token, validates every value, and never writes the store key or the API password back into the page.
- The back-office actions require the employee's right to edit orders.
- The customer's name, address and phone are taken from the invoice address of the order (1.x used the customer's last address, and never sent the country). The `+` of international phone numbers is kept.
- The card number saved on the order payment is the last four digits only.
- The unused file `confirmOf.php`, which could be opened directly in a browser, is deleted during the update, together with the other files of 1.x.
- Payments are no longer rejected by CMI because another module added hidden fields to the payment form.
- The old table `bpi_secuaccess`, created but never used by 1.x, is removed.
