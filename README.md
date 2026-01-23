# IVO Marketplace - Magento 2 Extension

Integrate your Magento 2 store with the IVO Marketplace platform. Automatically sync products, manage offers, and track listing status directly from the Magento admin panel.

## Features

- **Automatic Product Sync**: Products are automatically synced to IVO Marketplace when saved
- **Status Tracking**: View real-time IVO listing status (Active/Inactive/Draft) in product edit page
- **Force Sync**: Manually trigger product synchronization with one click
- **Multi-language Support**: Admin interface available in English, Romanian, and Russian

## Requirements

- Magento 2.4.x or higher
- PHP 7.4 or higher

## Installation

### Method 1: Composer (Recommended)

This is the official Magento-recommended way to install extensions.

```bash
# Add the IVO repository (run once)
composer config repositories.ivo composer https://packages.ivo.md

# Install the extension
composer require ivo/module-marketplace

# Enable and set up
bin/magento module:enable Ivo_Marketplace --clear-static-content
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:clean
```

### Method 2: Manual Installation

If you don't use Composer, you can install manually:

1. Download the latest `Ivo_Marketplace-x.x.x.zip` file
2. Extract the ZIP file to your Magento root directory (the `app/` folder will merge automatically)

That's it! The files will be placed in `app/code/Ivo/Marketplace/`

Then run:
```bash
bin/magento module:enable Ivo_Marketplace --clear-static-content
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:clean
```

## Configuration

1. Go to **Stores > Configuration > IVO > Configuration**
2. Click the **"Configure Plugin"** button
3. You will be redirected to IVO to authorize your store
4. After authorization, you'll return to Magento
5. Select your **Merchant Point** from the dropdown (this links your store to a specific IVO selling point)
6. Click **Save Config**

That's it! Your products will now sync automatically with IVO Marketplace.

## Usage

### Viewing Product Status

1. Go to **Catalog > Products**
2. Edit any product
3. Click on the **IVO Marketplace** tab
4. View the current sync status and marketplace URL

### Force Sync

If a product needs to be re-synced manually:

1. Open the product edit page
2. Go to the **IVO Marketplace** tab
3. Click **Force Sync with IVO**
4. Wait for confirmation message

## Changelog

### Version 1.0.0
- Initial release
- Automatic product sync on save
- IVO Status tab in product edit page
- Force Sync button
- Multi-language support (EN, RO, RU)

## Support

For support, please contact:
- Email: support@ivo.md
- Website: https://ivo.md

## License

Proprietary - All rights reserved by IVO.
