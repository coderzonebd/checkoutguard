# Changelog

All notable changes to CheckoutGuard will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.3] - 2025-12-15

### Fixed
- **Settings Page Compatibility**
  - Fixed settings page functionality for all WordPress installations
  - Resolved potential conflicts with other plugins
  - Improved settings initialization on first install

- **Invoice System Stability**
  - Fixed invoice generation for orders with special characters
  - Improved error handling in bulk print operations
  - Enhanced print window compatibility across browsers

- **Performance Optimization**
  - Optimized database queries for large order lists
  - Reduced memory usage during bulk operations
  - Faster page load times on admin pages

### Changed
- Updated plugin version to 1.1.2.1
- Improved error messages for better user feedback
- Enhanced compatibility checks on activation

---

## [1.1.2] - 2025-12-15

### Added - Invoice & Shipping Slip System
- **NEW: Invoice & Shipping Slip Generator**
  - Complete invoice generation system with professional templates
  - Shipping slip creation with order details
  - Print-optimized layouts for both document types
  - Single and bulk printing capabilities
  - AJAX-based document generation for fast performance

- **Invoice Features**
  - Professional invoice template with store branding
  - Custom logo support
  - Store and customer billing information
  - Itemized product list with quantities and prices
  - Subtotal, tax, and shipping calculations
  - Total amount prominently displayed
  - Order number and date
  - Portrait A4 print layout (one per page)
  - Branding footer on printed documents

- **Shipping Slip Features**
  - Compact shipping slip design
  - From/To address blocks
  - Product list with SKU and quantities
  - Order total value display
  - Customer notes section
  - Signature line for delivery confirmation
  - Landscape A4 print layout (2 slips per page)
  - Optimized for efficient printing

- **Print System**
  - Dynamic print styles based on document type
  - Separate page orientations (Portrait for invoices, Landscape for slips)
  - Print-friendly CSS with proper page breaks
  - Window-based printing system
  - Clean print output without browser UI

- **Orders Management**
  - Display ALL WooCommerce orders (not limited by status)
  - Pagination system (50 orders per page)
  - Order statistics: Total Orders, Processing, Completed
  - Customer avatars and contact information
  - Order status badges with color coding
  - Bulk selection with checkboxes
  - Bulk print operations for multiple orders

- **Settings Page**
  - Complete settings management interface
  - Feature toggle system for all plugin features
  - General Settings section (Incomplete Checkout, Fraud Blocker, Courier Check, Invoice & Shipping)
  - Feature Management section (Dashboard Widget, Branding Footer)
  - Tracking Settings section (Data Expiry, Max Recent Searches)
  - Dynamic menu visibility based on enabled features
  - About plugin information box
  - Upgrade to Pro promotion box
  - Modern responsive design with grid layout
  - WordPress Settings API integration
  - Form validation and sanitization
  - Success/error notifications

- **Feature Management System**
  - Enable/disable features via settings page
  - Menu items automatically hide when features disabled
  - All features enabled by default
  - Dashboard and Settings always visible
  - Warning notice about menu visibility behavior
  - Helper function: `checkoutguard_get_setting()`
  - Helper function: `checkoutguard_show_branding()`

### Enhanced
- **Modern Design System**
  - Consistent CSS framework across all admin pages
  - CSS variables for design tokens (--cg-primary, --cg-success, etc.)
  - Modern page headers with gradient backgrounds
  - Color-coded stat cards (Primary/Success/Info/Warning)
  - Professional button system (cg-btn with variants)
  - Card-based layouts with shadows
  - Utility classes for spacing and layout
  - Responsive design with mobile support

- **Branding Integration**
  - "Powered by Coder Zone BD" footer on all admin pages
  - Gradient background with color accents
  - Purple brand color scheme
  - Hover effects on brand name
  - Smooth fade-in animations
  - Non-removable in free version (locked setting)
  - Appears on printed documents (invoices and slips)

- **Dashboard Widget**
  - Controllable via settings (can be enabled/disabled)
  - Cleaner integration with WordPress dashboard

### Fixed
- **CSS Improvements**
  - Fixed CSS across all plugin pages
  - Unified modern styling throughout
  - Fixed Recent Searches card styling
  - Proper hover effects and transitions
  - Balanced braces validation (337/337 for admin-styles.css)
  - No syntax errors

- **Print Layout Fixes**
  - Fixed shipping slip 2-up printing for landscape
  - Resolved page break issues for even-numbered pages
  - Changed from float to inline-block layout
  - Added flexbox body for better positioning
  - Conditional page breaks for document types
  - Proper handling of bulk print operations

### Changed
- Removed bypass cache checkbox from Courier Check page (free version)
- Removed non-functional email notifications setting
- Updated plugin version to 1.1.2
- All features now enabled by default on first install
- Menu items dynamically hide based on settings

### Technical
- **New Files Created**
  - `includes/admin/invoice-page.php` - Invoice/shipping functionality (500+ lines)
  - `includes/admin/settings-page.php` - Settings management (480+ lines)
  - `assets/js/invoice.js` - Print and AJAX handling (520+ lines)

- **Modified Files**
  - `checkoutguard.php` - Added invoice and settings integration
  - `includes/admin/admin-menus.php` - Dynamic menu registration
  - `includes/admin/dashboard-widget.php` - Settings integration
  - `includes/enqueue.php` - Asset loading for new features
  - `assets/css/admin-styles.css` - Modern design system (43KB, 1,724 lines)
  - `assets/css/courier-check.css` - Updated styling (33KB, 1,641 lines)

- **Database**
  - No new tables required
  - Settings stored in wp_options (checkoutguard_settings)

### Security
- WordPress Settings API implementation
- Nonce verification on all forms
- Capability checks (manage_options, manage_woocommerce)
- Input sanitization and output escaping
- Branding footer locked in free version

---

## [1.1.1] - 2025-12-10

### Enhanced
- **Complete CSS Overhaul**
  - Modern design system implemented across all pages
  - Unified styling with consistent color scheme
  - Professional gradient headers on all admin pages
  - Color-coded stat cards for better visual hierarchy
  - Modern card-based layouts with shadows and hover effects

- **Admin Interface Improvements**
  - Dashboard page with modern gradient header
  - Incomplete Checkouts page with 4 color-coded stat cards
  - Fraud Blocker page with shield icon and modern styling
  - Courier Check page with updated form design
  - All pages now use consistent modern classes

- **Courier Check Enhancements**
  - Fixed Recent Searches card styling
  - Modern search item cards with hover effects
  - Color-coded risk badges
  - Icon backgrounds with circular gradients
  - Delete buttons appear on hover
  - Improved form input styling

- **Branding Footer**
  - Added "Powered by Coder Zone BD" footer to all pages
  - Gradient background (light blue to white)
  - Purple brand color with hover effect
  - Smooth fade-in animation
  - Rounded corners and professional appearance
  - Consistent placement across all admin pages

### Fixed
- CSS syntax errors across plugin files
- Inconsistent styling between pages
- Recent Searches display issues
- Button and form alignment problems
- Missing hover states and transitions

### Changed
- Removed bypass cache checkbox from free version
- Updated modern class naming convention
- Improved spacing and padding throughout
- Better color contrast for accessibility

### Technical
- **CSS Files Updated**
  - `assets/css/admin-styles.css` - 40KB to 43KB (modern design system)
  - `assets/css/courier-check.css` - 28KB to 33KB (recent searches styling)

- **Modified Files**
  - `includes/admin/admin-pages.php` - Modern headers and stat cards
  - `includes/admin/dashboard-page.php` - Updated with modern styling
  - `includes/admin/courier-check-page.php` - Removed bypass cache, modern design
  - All admin pages updated with branding footer

- **Design System**
  - CSS variables for colors (--cg-primary, --cg-success, etc.)
  - Modern component classes (cg-page-header-modern, cg-stat-card-modern)
  - Utility classes for spacing and layout
  - Consistent animation framework

---

## [1.1.0] - 2025-11-05

### Added - Courier Check Feature
- **NEW: Courier Success Rate Checker**
  - Check customer courier performance across multiple services
  - Supports Pathao, Steadfast, and RedX courier integration
  - Real-time API integration with Laravel backend
  - Displays total orders, failed deliveries, and success rates per courier

- **Risk Assessment System**
  - Automatic risk calculation based on courier performance
  - Four risk levels: Safe, Mid-safe, Risk, High Risk
  - Visual risk badges with color-coded indicators
  - Helps merchants make informed decisions before accepting orders

- **Local Caching System**
  - 6-hour local cache in WordPress database
  - Reduces API server load and improves performance
  - Cache age indicator shows how old the cached data is
  - Bypass cache option for fresh data when needed
  - Dual cache indicators (local cache vs API server cache)

- **Search History Management**
  - Recent searches sidebar showing last 5 searches
  - Click on any recent search to re-run the check
  - Individual delete button for each search entry
  - "Clear All" button to delete all search history at once
  - Auto-hide/show functionality for empty states

- **Modern UI/UX**
  - 12+ CSS3 animations (fadeIn, slideInDown, bounceIn, etc.)
  - Interactive hover effects on all interactive elements
  - Loading states with spinning icons
  - Smooth fade-out animations on deletion
  - Gradient backgrounds and modern color palette
  - Responsive design for mobile and tablet

- **Phone Validation**
  - Bangladesh phone number format validation
  - Real-time format checking
  - Clear error messages for invalid formats

- **Database Structure**
  - New table: `wp_checkoutguard_courier_searches`
  - Stores search history with full JSON response
  - Automatic table creation on plugin activation
  - Efficient indexing for fast queries

### Enhanced
- **Admin Interface Styling**
  - Professional gradient-rich design
  - Enhanced card layouts with shadows
  - Better spacing and typography
  - Improved color contrast for accessibility
  - Modern button designs with ripple effects

- **User Feedback**
  - Success/error messages with animations
  - Loading spinners during AJAX requests
  - Confirmation dialogs for destructive actions
  - Toast-style notifications
  - Progress indicators

- **Performance**
  - Optimized CSS with hardware-accelerated animations
  - Efficient AJAX requests with proper caching
  - Minimal database queries
  - Fast page load times

- **Accessibility**
  - ARIA labels for screen readers
  - Keyboard navigation support
  - Focus indicators on all interactive elements
  - High contrast ratios
  - Semantic HTML structure

### Security
- **AJAX Security**
  - Nonce verification on all AJAX endpoints
  - Capability checks (`manage_woocommerce`)
  - Input sanitization with `sanitize_text_field()` and `intval()`
  - Output escaping with `esc_html()`, `esc_attr()`, `esc_url()`
  - Prepared SQL statements via `$wpdb` methods

- **API Security**
  - Hardcoded API credentials (test environment)
  - X-API-Key header authentication
  - Server-side rate limiting (5 requests/minute per IP)
  - Error handling for failed API requests

### Technical
- **New Files Created**
  - `includes/admin/courier-check-page.php` - Admin UI (260 lines)
  - `includes/courier-check-ajax.php` - AJAX handlers (395 lines)
  - `assets/js/courier-check.js` - Frontend JavaScript (400 lines)
  - `assets/css/courier-check.css` - Styling (1000+ lines)

- **Modified Files**
  - `checkoutguard.php` - Added AJAX hooks and version bump
  - `includes/admin/admin-menus.php` - Added Courier Check submenu
  - `includes/enqueue.php` - Asset enqueuing for courier check
  - `includes/activation.php` - Database table creation
  - `readme.txt` - Updated description and changelog

- **Documentation**
  - `COURIER_CHECK_FEATURE.md` - Complete feature documentation
  - `API_INTEGRATION.md` - API integration guide
  - `STYLING_IMPROVEMENTS.md` - CSS enhancements documentation
  - `CSS_ANIMATION_REFERENCE.md` - Animation reference guide
  - `DELETE_SEARCH_FEATURE.md` - Delete functionality documentation
  - `CHANGELOG.md` - This file

### Changed
- Plugin version bumped from 1.0.2 to 1.1.0
- Plugin description updated to include courier checking
- Admin menu structure enhanced with new submenu item

---

## [1.0.2] - 2025-11-01

### Changed
- Updated and tested for WordPress 6.8.3
- Updated and tested for WooCommerce 8.9
- Minor bug fixes and improvements

---

## [1.0.1] - 2025-11-01

### Added - Initial Public Release
- **Incomplete Checkout Tracking**
  - Automatic capture of customer details during checkout
  - Real-time data saving as customers type
  - Track name, email, phone, and cart items
  - No limits on stored checkout data

- **Dashboard Widget**
  - Quick summary of last 24 hours
  - Shows incomplete checkout count
  - Direct link to full checkout list

- **Fraud Blocker**
  - Block unlimited phone numbers
  - Prevent orders from blocked numbers
  - Easy management interface
  - Order page integration for quick blocking

- **Admin Interface**
  - Clean, intuitive design
  - Incomplete Checkouts listing page
  - Fraud Blocker management page
  - Quick view of checkout details

- **Privacy & Security**
  - 100% local data storage
  - No external server communication
  - GDPR compliant
  - Secure data handling

- **WordPress & WooCommerce Integration**
  - Seamless WooCommerce integration
  - Compatible with WordPress 5.6+
  - Compatible with WooCommerce 5.0+
  - HPOS (High-Performance Order Storage) compatible

---

## Version Numbering

CheckoutGuard follows Semantic Versioning:
- **MAJOR** version (1.x.x) - Incompatible API changes
- **MINOR** version (x.1.x) - New features, backwards compatible
- **PATCH** version (x.x.1) - Bug fixes, backwards compatible

---

## Upgrade Notice

### 1.1.2
Major update! This version adds a complete Invoice & Shipping Slip system with professional templates and print optimization. New Settings page allows full control over plugin features with dynamic menu management. All features are now enabled by default. Includes modern design improvements and bug fixes. Automatic update recommended!

### 1.1.1
This version brings a complete CSS overhaul with modern design system, branding footer on all pages, and improved user interface. All admin pages now have consistent, professional styling. Bypass cache removed from free version. Update for better visual experience!

### 1.1.0
This version adds a powerful new Courier Check feature with local caching, search history management, and modern UI improvements. The update includes a new database table that will be created automatically on upgrade. No action required - just update and enjoy the new features!

---

## Support & Feedback

- **Plugin Support:** [WordPress.org Support Forum](https://wordpress.org/support/plugin/checkoutguard/)
- **Website:** [https://coderzonebd.com/](https://coderzonebd.com/)
- **Documentation:** See included .md files in plugin directory
- **Report Bugs:** Use the WordPress.org support forum

---

## Credits

**Development Team:**
- Coder Zone BD - [https://coderzonebd.com/](https://coderzonebd.com/)
- Lead Developer: devrkb21

**Special Thanks:**
- WordPress.org community
- WooCommerce team for excellent documentation
- Beta testers and early adopters

---

**Last Updated:** November 5, 2025
**Current Stable Version:** 1.1.0
