# ifthenpay | Payments for LearnPress

ifthenpay Pay by Link for [LearnPress](https://wordpress.org/plugins/learnpress/): students pay for courses on ifthenpay's hosted page (Multibanco, MB WAY, Payshop, cards, Cofidis, Apple Pay, Google Pay, Pix) and are enrolled once ifthenpay confirms the payment.

- WordPress 6.4+, PHP 7.4+, LearnPress active, store currency EUR.
- Namespace `Ifthenpay\LearnPress\`, PSR-4 via Composer (`vendor/` is shipped).
- The admin parts are unstyled on purpose for now: plain markup with `iftp-lp-` BEM classes, native `<dialog>`s and the `hidden` attribute. The only CSS is a checkout rule that hides LearnPress's guest "Please login in to enroll" box.

## How it works

1. LearnPress checkout creates a pending order; `IfthenpayGateway::process_payment()` asks `PaymentService` for a link and returns it as the redirect.
2. `PaymentService::link_for_order()` keeps one open link per student and course: it reuses the order's live link, refuses while another order for the same course has one, and creates a new one only under an atomic lock.
3. ifthenpay calls `GET /wp-json/iftp-lp/v1/callback`. The anti-phishing key is checked before anything else, then the amount; only then is the attempt marked paid (atomically) and the LearnPress order completed, which enrols the student.
4. The payer's return only records declines and cancels, then lands on LearnPress's order-received page, where a small status block watches for the payment.

## Admin

- **LearnPress › Settings › Payments › ifthenpay** is the only settings screen. `IfthenpayGateway::admin_option_settings()` prints our account panel (Connect → Backoffice Key popup → Disconnect), then LearnPress's own Enable / Checkout title / Checkout description, then the Gateway Key, the methods table with the default star and activation requests, and the hosted page description and expiry days (`Admin\SettingsSection`). Everything saves with LearnPress's own Save: our fields are stored on `learn-press/update-settings/updated`, after LearnPress's nonce check. The popups are printed in `admin_footer`, because LearnPress wraps the section in one `<form>`.
- **LearnPress order edit screen**: the order's ifthenpay payment (`Admin\OrderPanel`): status, amount, method, request and transaction IDs, payment link, expiry, refunds, dates, and earlier attempts.
- There's no ifthenpay menu or entries screen: LearnPress's orders are where payments are looked at.

## Development

```bash
composer dump-autoload -o
# from /workspace/repo
vendor/bin/phpcs --runtime-set text_domain ifthenpay-payments-for-learnpress <plugin path>
```

## Security

Please report security issues privately to [suporte@ifthenpay.com](mailto:suporte@ifthenpay.com), with "Security" in the subject line. See [SECURITY.md](SECURITY.md).

## Changelog

Releases that fix a security issue say so in their entry, prefixed with **Security:**.

### 1.0.0

- Initial release: ifthenpay Pay by Link for LearnPress, callback confirmation, payment details on LearnPress orders, and refunds.
