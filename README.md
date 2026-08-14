# WooCommerce Edge Payments Gateway

## Installation

To install the WooCommerce Edge Payments Gateway plugin, follow these steps:

1. Download the plugin ZIP from the WooCommerce Edge Payments Gateway repository's releases.
2. Upload it through 'Plugins' > 'Add New' > 'Upload Plugin', or unzip it into `/wp-content/plugins/`.

The plugin has no third-party PHP dependencies, so there is nothing to install alongside it.
Developers building from a source checkout need `composer install` for the test and linting tools,
and `npm install && npx wp-scripts build` for the Blocks checkout bundle.

## Activation in WordPress and WooCommerce

After installing the WooCommerce Edge Payments Gateway plugin, follow these steps to activate it in WordPress and WooCommerce:

1. In WordPress, navigate to the 'Plugins' section.
2. Find 'WooCommerce Edge Payments Gateway' in the list of plugins and click 'Activate'.
3. In WooCommerce, go to 'WooCommerce' > 'Settings' > 'Payments'.
4. Enable 'WooCommerce Edge Payments Gateway' from the list of available payment methods.

Now, the WooCommerce Edge Payments Gateway is installed, activated, and ready to use for processing payments on your WooCommerce store.


### Development Building Instructions

To build the js in this project, run: 

```
nvm use
npm install
npm run packages-update
npm run build
```
