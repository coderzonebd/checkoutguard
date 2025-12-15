# Changelog

All notable changes to CheckoutGuard will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
