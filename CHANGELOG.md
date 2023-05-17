# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

***

## 1.0.2
Release date: May, 17th 2023

### Fixed
+ PLGVIR4-44: Rectify the solution implemented in PLGVIR4-41 by ensuring its functionality across all scenarios, and prevent the insertion of HTML tags in the payment name database field.

***

## 1.0.1
Release date: May, 3rd 2023

### Fixed
+ PLGVIR4-41: Fix an issue related to 'VMuikit X' where payment methods won't be shown in this third party checkout.

***

## 1.0.0
Release date: March, 29th 2023

### Added
+ Payment Methods
    - Alipay
    - Alipay+ ™ Partner
    - Amazon Pay
    - American Express
    - Apple Pay
    - Bancontact
    - Bank transfer
    - Belfius
    - CBC
    - Credit Cards
    - Dotpay
    - E-Invoicing
    - EPS
    - Giropay
    - Google Pay
    - iDEAL
    - iDEAL QR
    - in3
    - KBC
    - Klarna - Pay in 30 days
    - Maestro
    - Mastercard
    - MultiSafepay
    - MyBank - Bonifico Immediato
    - Pay After Delivery
    - PayPal
    - Paysafecard
    - Request to Pay powered by Deutsche Bank
    - Riverty
    - SEPA Direct Debit
    - Santander Consumer Finance | Pay per month
    - Sofort
    - Trustly
    - Visa

+ Gift Cards
    - Baby Cadeaubon
    - Beauty & Wellness
    - Boekenbon
    - Fashioncheque
    - Fashiongiftcard
    - Fietsenbon
    - Gezondheidsbon
    - GivaCard
    - Good4fun Giftcard
    - Good Card
    - Nationale Tuinbon
    - Parfum Cadeaukaart
    - Podium
    - Sport & Fit
    - VVV Cadeaukaart
    - Webshop Giftcard
    - Wellness gift card
    - Wijncadeau
    - Winkelcheque
    - YourGift

+ Features:
    - Process payments with all the MultiSafepay payment methods and gift cards.
    - Added API Key as transitional authentication method.
    - Simple switch between test and live mode.
    - Easy default settings for all the order statuses.
    - List of configurable params per payment method:
        - Assignable image logo.
        - Payment link lifetime.
        - Restrictions per country.
        - Maximum and minimum amount.
        - Fee setting per transaction.
        - Fee or cashback in percent of the total amount.
        - Easy tax assignment.
    - Order status updated via webhook.
