# EMI Widget - Files Added/Modified

## Summary
This document lists all files added or modified for the Pinelabs EMI Widget feature.

**Date:** February 17, 2026  
**Feature:** Product Detail Page EMI Widget with Modal Popup

---

## Files Added/Modified

### 1. PHP Backend Files

#### Helper Class
- **File:** `PinePGGateway/Helper/EmiOffers.php`
- **Status:** Created
- **Purpose:** Handles EMI offers API integration, data processing, and bank logo mapping
- **Key Features:**
  - Fetches EMI offers from Pinelabs API
  - Filters no-cost EMI offers
  - Groups all EMI offers by issuer
  - Maps bank names to logo filenames
  - Formats amounts from paise to rupees

#### Block Class
- **File:** `PinePGGateway/Block/Product/EmiWidget.php`
- **Status:** Created
- **Purpose:** Bridge between product data and EMI widget template
- **Key Features:**
  - Retrieves current product information
  - Passes product SKU to helper
  - Provides data to template
  - Gets minimum no-cost EMI offer

---

### 2. Frontend Template

#### Template File
- **File:** `PinePGGateway/view/frontend/templates/product/emi-widget.phtml`
- **Status:** Created
- **Purpose:** Renders EMI widget on product detail page
- **Key Features:**
  - Main widget with "Powered by Pinelabs" branding
  - Displays bank logo and name
  - Shows minimum no-cost EMI offer
  - Modal popup with all EMI options
  - Responsive design

---

### 3. Frontend Assets

#### JavaScript
- **File:** `PinePGGateway/view/frontend/web/js/emi-widget.js`
- **Status:** Created
- **Purpose:** Handles modal popup interactions
- **Key Features:**
  - Opens/closes modal
  - Body scroll lock when modal is open
  - Event delegation for dynamic content
  - ESC key to close modal

#### CSS
- **File:** `PinePGGateway/view/frontend/web/css/emi-widget.css`
- **Status:** Created
- **Purpose:** Styles for EMI widget and modal
- **Key Features:**
  - #003434 color theme
  - Responsive design
  - Bank logo styling
  - Modal overlay and container
  - No-cost EMI card styles
  - Tenure detail badges

---

### 4. Admin Configuration

#### System Configuration
- **File:** `PinePGGateway/etc/adminhtml/system.xml`
- **Status:** Modified
- **Changes:** Added EMI widget enable/disable toggle
- **Config Path:** `payment/pinepgpaymentmethod/emi_widget_enabled`

---

### 5. Bank Logo Images

#### Images Directory
- **Path:** `PinePGGateway/view/frontend/web/images/banks/`
- **Status:** Created
- **Total Files:** 15 PNG images

**Bank Logos:**
1. `amex.png` - American Express
2. `axis.png` - Axis Bank
3. `bob.png` - Bank of Baroda
4. `hdfc.png` - HDFC Bank
5. `icici.png` - ICICI Bank
6. `idfc.png` - IDFC First Bank
7. `indian-overseas.png` - Indian Overseas Bank
8. `indian.png` - Indian Bank (alternate)
9. `indusind.png` - IndusInd Bank
10. `kotak.png` - Kotak Bank
11. `onecard.png` - OneCard
12. `pnb.png` - Punjab National Bank
13. `rbl.png` - RBL Bank
14. `standard-chartered.png` - Standard Chartered Bank
15. `yes.png` - Yes Bank

---

## File Structure in Repository

```
Pinelabs/PinePGGateway/
├── Helper/
│   └── EmiOffers.php
├── Block/
│   └── Product/
│       └── EmiWidget.php
├── view/
│   └── frontend/
│       ├── templates/
│       │   └── product/
│       │       └── emi-widget.phtml
│       └── web/
│           ├── css/
│           │   └── emi-widget.css
│           ├── js/
│           │   └── emi-widget.js
│           └── images/
│               └── banks/
│                   ├── amex.png
│                   ├── axis.png
│                   ├── bob.png
│                   ├── hdfc.png
│                   ├── icici.png
│                   ├── idfc.png
│                   ├── indian-overseas.png
│                   ├── indian.png
│                   ├── indusind.png
│                   ├── kotak.png
│                   ├── onecard.png
│                   ├── pnb.png
│                   ├── rbl.png
│                   ├── standard-chartered.png
│                   └── yes.png
└── etc/
    └── adminhtml/
        └── system.xml (modified)
```

---

## Key Features Implemented

### Main Widget
- ✅ "Powered by Pinelabs" branding with logo
- ✅ Bank logo display
- ✅ Bank name display
- ✅ No Cost EMI badge
- ✅ Starting EMI amount
- ✅ "View All EMI Options" button
- ✅ #003434 color theme
- ✅ Responsive design

### Modal Popup
- ✅ No-Cost EMI cards grid with bank logos
- ✅ All EMI options grouped by bank
- ✅ Bank logos for each issuer
- ✅ Interest rate display
- ✅ Interest amount display
- ✅ Processing fee display
- ✅ Total amount display
- ✅ EMI type badges (No Cost, Low Cost)
- ✅ Enlarged close button (42px)
- ✅ Click outside to close
- ✅ ESC key to close

### Integration
- ✅ Uses existing `PinePGPaymentMethod->getAccessToken()`
- ✅ Dynamic product SKU from product page
- ✅ API endpoint based on environment (UAT/LIVE)
- ✅ Proper error handling and logging
- ✅ Admin configuration toggle

---

## Configuration

### Admin Settings
Path: **Stores > Configuration > Sales > Payment Methods > Pinelabs Payment Gateway > EMI Widget Settings**

- **Enable EMI Widget:** Yes/No toggle

### API Integration
- **Endpoint:** Pinelabs Affordability API v1
- **Authentication:** OAuth2 Bearer token
- **Request:** Product code (SKU) and amount
- **Response:** Issuers with tenure details

---

## Testing Checklist

- [ ] Widget displays on product detail page
- [ ] Bank logos load correctly
- [ ] Modal opens on button click
- [ ] Modal closes on X button click
- [ ] Modal closes on overlay click
- [ ] Modal closes on ESC key
- [ ] No-cost EMI cards show correct data
- [ ] All EMI options grouped by bank
- [ ] Interest rate and fees display correctly
- [ ] Responsive design works on mobile
- [ ] Admin configuration toggle works
- [ ] Cache cleared after deployment

---

## Deployment Instructions

1. Copy all files to Magento installation
2. Run `php bin/magento setup:upgrade`
3. Run `php bin/magento setup:di:compile`
4. Run `php bin/magento setup:static-content:deploy`
5. Clear cache: `php bin/magento cache:flush`
6. Enable in Admin: Stores > Configuration > Sales > Payment Methods > Pinelabs > EMI Widget Settings

---

## Dependencies

- **Existing Model:** `PinePGGateway/Model/PinePGPaymentMethod.php` (uses `getAccessToken()` method)
- **Magento Version:** 2.x
- **PHP Version:** 7.4+
- **jQuery:** Required for modal functionality

---

## Notes

- All bank logos are in PNG format
- Logo filenames match bank display names from API
- Widget only displays when enabled in admin
- Requires valid Pinelabs merchant credentials
- Uses existing payment method configuration
