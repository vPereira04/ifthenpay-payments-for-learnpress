=== ifthenpay | Payments for LearnPress ===
Contributors: ifthenpay
Tags: ifthenpay, learnpress, payments, multibanco, mb way
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell LearnPress courses with ifthenpay Pay by Link: Multibanco, MB WAY, cards, Apple Pay, Google Pay and more. No card data stored.

== Description ==

This plugin adds ifthenpay as a payment method to LearnPress checkout. Students are sent to ifthenpay's secure hosted page, pay with any method enabled on your Gateway Key, and are enrolled as soon as ifthenpay confirms the payment.

In plain terms you get:

* An "ifthenpay" option at LearnPress checkout, for one-off course payments in euros.
* Automatic enrolment when the payment is confirmed, including Multibanco and Payshop references paid hours or days later.
* The ifthenpay payment on each LearnPress order: status, amount, method, references, payment link and any earlier attempts.
* Refunds from LearnPress's own refund flow, sent to ifthenpay for card, MB WAY, Apple Pay and Google Pay payments.

== Key Features ==

1. Pay by Link checkout for LearnPress orders (full redirect to ifthenpay's hosted page).
2. Payment confirmation by server-to-server callback, validated with an anti-phishing key and the amount.
3. Any method on your Gateway Key: Multibanco, MB WAY, Payshop, credit card, Cofidis, Apple Pay, Google Pay, Pix.
4. One open payment link per student and course: a second checkout reuses the open link instead of creating another payable one.
5. The order-received page shows where the payment stands and updates on its own once it's paid.
6. "Pay now" in My Account › Orders for pending ifthenpay orders, and "Try again" after a failed or cancelled payment.
7. All settings in one place: LearnPress › Settings › Payments › ifthenpay (connection, Gateway Key, payment methods, hosted page, checkout title and description).
8. Payment details on LearnPress's own order screen: status, amount, method, request and transaction IDs, payment link and earlier attempts.
9. Request activation of a payment method that isn't on your Gateway Key yet, straight from the settings.
10. Multi-language payment page (PT, ES, FR, EN).

== Requirements ==

* An active ifthenpay merchant account with a Backoffice Key.
* A Gateway Key for LearnPress on that account (ask ifthenpay support to create one).
* WordPress 6.4+ and PHP 7.4+, with LearnPress installed and activated.
* LearnPress set to sell in euros (EUR).
* HTTPS on your site, and the WordPress REST API reachable from the internet (ifthenpay calls it back).

== Installation ==

1. Install and activate the plugin. LearnPress must be active first.
2. Go to **LearnPress › Settings › Payments › ifthenpay** and click **Connect**. Paste your Backoffice Key.
3. Tick **Enable**, set the checkout title and description, pick the Gateway Key, enable the payment methods you want to offer, star the one the payment page opens on, and click **Save settings**.
4. Make a small test purchase.

The callback URL is registered with ifthenpay automatically when you connect, switch Gateway Key or save.

== Frequently Asked Questions ==

= Does this plugin support recurring payments or subscriptions? =
No. It handles one-off course payments. LearnPress subscription plans can't be paid with ifthenpay.

= Are payment details stored? =
No card or bank details are stored. The plugin keeps a record of each payment attempt (reference, amount, method, status, the student's name and email, and the link) so you can follow it up.

= Which payment methods are supported? =
Any method enabled on your ifthenpay Gateway Key (e.g. Multibanco, MB WAY, Payshop, Credit Card, Cofidis, Google Pay, Apple Pay, Pix).

= How does the payment process work? =
At checkout LearnPress creates a pending order and the student is sent to ifthenpay's page. Once ifthenpay confirms the payment, it calls your site back, the order is completed and the student is enrolled. The student lands on LearnPress's order page, which updates on its own.

= What happens if a payment fails or is cancelled? =
The order stays pending, so the student can try again from the order page or from My Account › Orders. The failed attempt shows in the ifthenpay block on the LearnPress order.

= What if the student has two checkout tabs open? =
ifthenpay links can't be cancelled once created, so the plugin never creates a second open link for the same student and course. A second checkout reuses the open link, or points the student to it.

= Can I refund through ifthenpay? =
Yes, for card, MB WAY, Apple Pay and Google Pay payments, through LearnPress's refund flow (LearnPress › Settings › Payments › Refund must allow refund requests). Multibanco and Payshop payments have to be refunded another way.

= Is there a sandbox? =
ifthenpay may provide test entities; if not, use a low-value live test.

= How secure is the integration? =
All requests use HTTPS. The callback is checked against a secret anti-phishing key registered with ifthenpay and against the order's amount before anything changes, and only the callback ever marks a payment as paid.

== External Services ==

This plugin connects to ifthenpay, a third-party payment service, to take payments for LearnPress orders.

- **ifthenpay API (api.ifthenpay.com)**
  - **What it is and what it is used for**: ifthenpay's API creates the hosted payment pages, lists the Gateway Keys and payment methods on your account, registers the payment callback, and processes refunds.
  - **What data is sent and when**:
    - When you connect or sync: your Backoffice Key and the integration type, to list your Gateway Keys; and a request for the public list of payment methods.
    - When you connect, switch Gateway Key or save: the Gateway Key, the callback URL of your site and a random anti-phishing key, to register the callback.
    - When a student pays (checkout, "Pay now" or "Try again"): the payment reference, the amount, the description, your enabled methods' accounts, the success/error/cancel return URLs of your site, the language, the default method and the link's expiry date.
    - When a refund is made: your Backoffice Key, the payment's request ID and the amount.
    - When you request a method activation: an email to ifthenpay support with your Backoffice Key, Gateway Key, the method, your email, site address and versions.
    - ifthenpay calls your site back with the payment reference, amount, method and request ID.
  - **End-User License Agreement (EULA)**: [EULA](https://ifthenpay.com/eula/)
  - **Privacy Policy**: [Privacy Policy](https://ifthenpay.com/politica-de-privacidade/)

- **ifthenpay hosted payment page (pinpay.pt)**
  - **What it is and what it is used for**: the page students are sent to, to pay.
  - **What data is sent and when**: the student's browser opens the link; ifthenpay collects the payment details there, not this site.
  - **End-User License Agreement (EULA)**: [EULA](https://ifthenpay.com/eula/)
  - **Privacy Policy**: [Privacy Policy](https://ifthenpay.com/politica-de-privacidade/)

All network requests from this site are made server-side over HTTPS. The Backoffice Key is stored on your site and never shown again after you connect.

== Privacy ==

The plugin stores, per payment attempt, the student's name and email, the LearnPress order and course, the amount, method, status and reference. They're included in WordPress's personal data export and erased by its eraser (the payment record itself is kept, without the personal data).

== Security ==

Please report security issues privately to [suporte@ifthenpay.com](mailto:suporte@ifthenpay.com), with "Security" in the subject line, not on the public support forum. See SECURITY.md in the plugin folder for details.

== Screenshots ==

1. (Admin) LearnPress › Settings › Payments › ifthenpay: connection, checkout title and description, Gateway Key, payment methods and hosted page settings.
2. (Admin) The ifthenpay payment on a LearnPress order: status, amount, method, references and earlier attempts.
4. (Student) ifthenpay at LearnPress checkout.
5. (Student) ifthenpay's secure payment page.

== Changelog ==

Releases that fix a security issue say so in their entry, prefixed with **Security:**.

= 1.0.0 =
* Initial release: ifthenpay Pay by Link for LearnPress, callback confirmation, payment details on LearnPress orders, and refunds.

== Upgrade Notice ==

= 1.0.0 =
Initial release. Connect your Backoffice Key and enable at least one payment method before going live.

== Support ==

For assistance use the [WordPress.org support forum](https://wordpress.org/support/plugin/ifthenpay-payments-for-learnpress).

Before posting, check that:
* the payment method is enabled on your Gateway Key and in LearnPress › Settings › Payments › ifthenpay;
* LearnPress sells in euros;
* your site's REST API is reachable from the internet;
* you're running current versions of WordPress, PHP and LearnPress.

Commercial helpdesk: [helpdesk.ifthenpay.com](https://helpdesk.ifthenpay.com/)
* ifthenpay support: [suporte@ifthenpay.com](mailto:suporte@ifthenpay.com)
* LearnPress docs: [docs.thimpress.com/learnpress](https://docs.thimpress.com/learnpress/)
