## WooCommerce Loyalty Cards
This plugin let you manage loyalty cards with percent-based discount:
- add/edit single card
- import/export multiple cards
- generate digit/letter-based cards
- set personal discount for any card
- exclude products from discount calculation 
- set single or multiple card per customer

## Contents

- [Installation](#installation)
- [Usage](#usage)
- [Filters](#filters)

## Installation

Clone this repo or upload and unzip archive to `wp-content/plugins` directory. Enable plugin.

## Usage

This plugin use woocommerce coupons as a base, which compatible with classic/block themes without an extra fields. Enable coupons in woocommerce settings. Now, when user add card to coupon field, card discount adds to your total. 
To avoid coincidences between the coupon and the card, plugin add prefix before every card. By default prefix is `CARD-`, but it can be replaced on Settings page.

### Import/export

For simple integration with clients DB, plugin let you import data in csv files. Export and fill with your data. CSV file include following fields:
- **ID**: Card ID in wordpress DB. If ID are empty, create new card, otherwise update card with given ID (if you want to change existing cards)
- **Card number**: If you want to set existing card name/change card name or use different logic. Prefix `CARD-` appends automatically.
- **User email**: Unique customer email in woocommerce store. If `Allow multiple cards per user` on Settings page are checked, one user can use multiple cards, otherwise other cards imports without user.
- **Discount**:  Percent-based discount for current card

### Excluding products from discounts

If you want to exclude some products from discount calculation, go to product edit page, switch to tab `Loyalty card`, check the `Exclude from discount` checkbox and save product. This product will be excluded from final discount calculation.

## Filters

**sha_wclc_cpt_labels**
- Override labels in `register_post_type()`.

**sha_wclc_product_tab_classes**
- Type of products, which can be excluded from discount calculation. In woocommerce it depends on css classes of custom tab. Default: `array( 'wc_loyalty_cards', 'show_if_grouped', 'show_if_simple', 'show_if_variable' )`



