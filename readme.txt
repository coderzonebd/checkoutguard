=== CheckoutGuard ===
Contributors: coderzonebd, devrkb21
Tags: woocommerce, abandoned cart, checkout, invoice, fraud blocker
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.5.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Track incomplete checkouts, recover lost sales, generate invoices & labels, block fraud, and analyze courier success rates.

== Description ==

CheckoutGuard is a WooCommerce plugin that helps store owners recover lost sales by tracking incomplete checkouts and providing tools for follow-up. It also includes fraud prevention, courier analytics, and an automatic success-ratio blocker to protect your store from high-risk orders.

Checkout records and customer data are stored locally in your WordPress database. Limited site, administrator, environment, license, and plugin-usage telemetry is sent to CheckoutGuard Headquarters for licensing, usage limits, settings synchronization, and plugin analytics. Appsero diagnostic tracking is separate and is sent only after an administrator opts in. Customer checkout records are not sent to our licensing service; courier phone-number lookups are sent to the selected courier provider only when Courier Analytics is used.

= Incomplete Checkout Tracking =

CheckoutGuard captures customer information and cart contents in real-time as shoppers fill in the checkout form, even if they never click "Place Order". Data is collected via debounced AJAX calls (800ms delay) to avoid excessive requests. Tracked fields include:

* Billing name, email, phone, and full address
* Order comments
* Cart items (product name, quantity, line total) and cart total
* Customer IP address and WooCommerce session ID

Once captured, each incomplete checkout appears in the **Incomplete Checkouts** list where you can:

* **View full details** in a modal popup (customer info, cart items, timestamps)
* **Recover** the checkout by creating a real WooCommerce order with one click
* **Mark as Hold** for future follow-up, with an optional follow-up date (YYYY-MM-DD)
* **Mark as Cancelled** if the checkout is no longer relevant
* **Re-open** a cancelled checkout back to incomplete status
* **Edit follow-up dates** on held checkouts

Completed orders are automatically cleaned up: when a customer finishes checkout, their incomplete record is deleted.

= Dashboard Overview =

The main dashboard gives you a bird's-eye view of your checkout performance with:

* **Pie chart** showing the distribution of incomplete, recovered, hold, and cancelled checkouts
* **Count cards** for each status with clickable links to the detail pages
* **Value cards** showing the total monetary value for incomplete, recovered, hold, and cancelled checkouts
* **Date range filters**: Today, Last 7 Days, Last 30 Days, or a custom date range picker
* **WordPress Dashboard Widget** - a compact summary widget on the main WP dashboard showing the same stats and chart

= Fraud Blocker =

Prevent unwanted orders by maintaining blocklists for:

* **IP Addresses** - block by customer IP
* **Email Addresses** - block by billing email
* **Phone Numbers** - block by billing phone

When a blocked customer attempts to checkout, WooCommerce displays an error message and the order is not processed. Each blocklist supports:

* Adding items with an optional reason
* Searching/filtering the list
* Deleting items
* Custom block message (configurable in Settings)

You can also **block or unblock directly from a WooCommerce order page** using the "Fraud Blocker Actions" meta box in the order sidebar, which shows the customer's IP, email, and phone with one-click Block/Unblock buttons.

= Success Ratio Blocker (Automatic) =

This feature automatically blocks checkout attempts from customers with a poor delivery success history. It works by:

1. Checking the customer's phone number against courier delivery data during checkout
2. If their success ratio falls at or below the configured threshold, the order is blocked
3. The blocked attempt is logged with full details (cart, ratio, threshold, IP)

Configurable settings:

* **Block Threshold (%)** - orders are blocked if the success ratio is at or below this value (default: 20%)
* **Grace Period** - new customers are not blocked until they have more than this many total orders (default: 5)
* **Custom Blocked Order Notice** - the message shown to the customer, supports `{ratio}` placeholder

All blocked attempts are logged in the **Blocked Orders Log** page where you can view details and delete log entries.

= Courier Analytics =

Check the delivery success and return ratio for any customer phone number across different courier services (Pathao, RedX, Steadfast, Paperfly, Carrybee). This feature provides:

* **Standalone Courier Analytics page** - enter a phone number and get a full breakdown
* **WooCommerce Order List column** - "Success Ratio" column added to the orders table showing a compact ratio badge per order
* **Order Detail meta box** - "Customer Success Ratio" sidebar box on individual order pages with a "Check Full Ratio" button
* **Live ratio check on checkout** - real-time feedback shown below the phone field as the customer types (triggers after 10+ digits)
* **Doughnut chart visualization** for the overall success rate
* **Per-courier breakdown** by courier service

Ratio data is cached on each order via post meta to avoid redundant API calls.

= Invoicing, Packing Slips & Shipping Labels =

CheckoutGuard generates professional PDF invoices and printable courier shipping labels directly from your WooCommerce orders:

* **Automatic PDF invoices** - generated when an order reaches Processing or Completed, with sequential invoice numbering, your company name/logo header, and the invoice attached to the customer's order emails
* **Invoices admin page** - a searchable, paginated list of every invoiced order with quick access to view, print, or download each invoice
* **Packing slips** - the same invoice layout with all pricing and totals omitted, for including in the shipping package
* **Interactive Print page** - preview and print an A4 invoice or a courier shipping label (A4, 2x3 in, or 3x3 in) with barcode, QR code, a Cash-on-Delivery box, and a courier badge, sized to match the physical paper exactly
* **Blank manual invoices (Pro)** - create a fully custom invoice and shipping label with no WooCommerce order behind it, using freeform line items or products picked from your catalog; these never touch stock, the Orders list, or sales reports
* **Pro invoice options** - custom invoice numbering prefix and reset period (never/yearly/monthly), Bangladesh VAT/TIN/BIN tax fields, editable footer text, bulk ZIP download of invoices, and courier shipping labels
* Customers can always download a plain PDF copy of their invoice from the My Account page

= Order Status Manager =

Create and manage custom WooCommerce order statuses from a dedicated admin page. This feature allows you to:

* **View status overview cards** showing live order counts for every status (default and custom)
* **Create custom statuses** with a label, color, description, and icon from a visual picker with 30+ order-management icons
* **Edit any status** including default WooCommerce statuses (Pending, Processing, On Hold, etc.) — change labels, colors, and icons
* **Delete any status** with automatic order migration — if orders exist on a status being deleted, you are prompted to choose a target status
* **Reset to defaults** from Settings to restore the original 7 WooCommerce statuses if any were accidentally deleted
* Custom statuses automatically appear in the WooCommerce order status dropdown
* Toggle the feature on/off from Settings > Module Toggles

= Checkout Status Management =

Each incomplete checkout flows through a clear lifecycle:

* **Incomplete** - captured but not completed
* **Recovered** - converted into a WooCommerce order
* **Hold** - flagged for follow-up with an optional date
* **Cancelled** - dismissed by admin

Each status has its own dedicated list page with date range filters (Today, 7 Days, 30 Days, All Time, or Custom Range).

= WooCommerce Integration =

CheckoutGuard integrates deeply with WooCommerce:

* **Order list column** - adds "Success Ratio" column to both classic and HPOS order lists
* **Order sidebar meta boxes** - "Customer Success Ratio" and "Fraud Blocker Actions" boxes on every order
* **HPOS compatible** - works with both classic post-based orders and High-Performance Order Storage
* **Checkout hooks** - integrates at `woocommerce_checkout_process` for fraud blocking and `woocommerce_thankyou` for cleanup

= Admin Pages =

CheckoutGuard adds the following pages under its own top-level menu:

1. **Dashboard** - overview with chart and stats
2. **Follow Up** - checkouts marked as hold, ordered by follow-up date
3. **Incomplete** - all incomplete checkouts
4. **Recovered** - checkouts that were converted to orders
5. **Blocked Orders** - log of automatically blocked checkout attempts
6. **Cancelled** - dismissed checkouts
7. **Fraud Blocker** - manage IP, email, and phone blocklists
8. **Courier Analytics** - check delivery success ratios
9. **Invoices** - searchable list of invoiced orders, with print/download access and (Pro) blank manual invoices
10. **Order Statuses** - manage all WooCommerce order statuses with overview cards
11. **Settings** - configuration and account management

= Third-Party Services =

This plugin connects to the following external services:

**CheckoutGuard Headquarters (li.czbd.app / Coder Zone BD)**
Used for license validation, usage tracking, settings synchronization, and plugin analytics. The plugin sends the site domain, a stable domain hash, administrator email, site name, WordPress/WooCommerce/PHP/MySQL versions, active theme, plugin version, selected plugin settings, feature-usage summaries, license status, and timestamps.

* Service URL: [https://li.czbd.app](https://li.czbd.app)
* Privacy Policy: [https://coderzonebd.com/privacy-policy](https://coderzonebd.com/privacy-policy)
* Terms of Service: [https://coderzonebd.com/terms](https://coderzonebd.com/terms)

**Appsero**
Appsero provides optional diagnostic and usage insights. It may receive the site name and URL, administrator name and email, server environment details, WordPress version, site language, user count, active and inactive plugin information, and plugin version. Appsero tracking is disabled by default and is sent only when an administrator selects **Allow** in the Appsero insights notice. It can be declined from that notice.

* Service URL: [https://appsero.com](https://appsero.com)
* Privacy Policy: [https://appsero.com/privacy-policy/](https://appsero.com/privacy-policy/)

**Courier APIs (Pathao, RedX, Steadfast, Paperfly, Carrybee)**
When the Courier Analytics feature is enabled, customer phone numbers are sent to third-party courier service APIs to retrieve delivery success statistics. This only occurs when explicitly triggered by an admin or during checkout (if the live ratio check is enabled).

== Privacy Policy ==

CheckoutGuard stores personal data locally in your WordPress database. The following data is collected:

* **Customer Details:** First name, last name, email, phone, billing address (address 1, address 2, city, state, postcode, country)
* **Technical Information:** Customer IP address, WooCommerce session ID
* **Cart Information:** Product ID, name, quantity, line total for each item, and total cart value
* **Tracking Status:** Checkout status (incomplete/recovered/hold/cancelled), follow-up dates, admin notes, recovered order ID
* **Blocked Items:** IP addresses, email addresses, and phone numbers on the blocklists, with reason and the admin who added them
* **Blocked Order Logs:** Phone number, email, cart details, cart value, success ratio, threshold at time of block, IP address, customer name, and timestamp

This data is used exclusively within your WordPress admin area for checkout recovery and fraud prevention purposes.

**Data is not transmitted to external servers** except in the following cases:
* Limited site, administrator, environment, license, and plugin-usage telemetry is sent to the CheckoutGuard Headquarters API (li.czbd.app)
* Optional diagnostic and usage data is sent to Appsero after administrator opt-in
* Courier delivery ratio checks when the Courier Analytics feature is enabled (phone numbers are sent to courier APIs)

**GDPR Compliance:**
CheckoutGuard supports the WordPress personal data export and erasure tools (Tools > Export Personal Data / Erase Personal Data). When a valid request is made, all incomplete checkout records and blocklist entries associated with the requested email address will be included in exports or deleted upon erasure.

Data retention is configurable in Settings (30 days, 90 days, 1 year, or indefinite). If the "Delete all data on uninstall" option is enabled, all plugin tables and options are permanently removed when the plugin is deleted.

A suggested privacy policy text is automatically added to your site's Privacy Policy page (Settings > Privacy) when the plugin is active.

== Installation ==

= From the WordPress Admin =

1. Go to **Plugins > Add New** in your WordPress admin
2. Click **Upload Plugin** and select the CheckoutGuard .zip file
3. Click **Install Now**, then **Activate Plugin**

= Manual Installation =

1. Download the plugin .zip file
2. Extract it to your `/wp-content/plugins/` directory
3. Go to **Plugins** in your WordPress admin and activate **CheckoutGuard**

= After Activation =

1. Navigate to **CheckoutGuard > Settings** in your admin menu
2. On the **Account & Usage** tab, click **Sync Now** to sync your license
3. On the **Settings** tab, configure the following:

**General Settings:**

* **Data Retention Policy** - how long to keep incomplete checkout records (30 days, 90 days, 1 year, or never delete)
* **Exclude User Roles** - select user roles whose checkouts should not be tracked (e.g., Administrator, Shop Manager)

**Module Toggles:**

* **Enable Checkout Tracking** - turn real-time checkout data capture on/off
* **Enable Fraud Blocker** - turn the IP/email/phone blocklist system on/off
* **Enable Courier Service** - turn the courier success ratio features on/off
* **Enable Order Statuses** - turn the custom order status manager on/off

**Fraud Blocker Settings:**

* **Custom Block Message** - the message shown to blocked customers at checkout

**Success Ratio Blocker:**

* **Block Threshold (%)** - block orders at or below this success ratio (default: 20%)
* **New Customer Grace Period** - number of orders before the ratio check kicks in (default: 5)
* **Blocked Order Notice** - the message shown when a checkout is blocked by ratio (supports `{ratio}` placeholder)

**Advanced Settings:**

* **Delete all plugin data upon uninstallation** - when enabled, all database tables and options are permanently removed when you delete the plugin

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =
Yes. CheckoutGuard is a WooCommerce extension and requires WooCommerce to be installed and active. If WooCommerce is not detected, the plugin will display an admin notice and will not load its functionality.

= Is it compatible with HPOS (High-Performance Order Storage)? =
Yes. CheckoutGuard supports both the classic post-based order system and the newer HPOS storage. The Success Ratio column and meta boxes work on both `edit-shop_order` and `woocommerce_page_wc-orders` screens.

= Where is the data stored? =
All data is stored locally in your WordPress database in custom tables prefixed with `cg_` (e.g., `wp_cg_incomplete_checkouts`, `wp_cg_blocked_ips`, `wp_cg_blocked_emails`, `wp_cg_blocked_numbers`, `wp_cg_blocked_orders`).

= Will it slow down my checkout page? =
No. Checkout data is captured using debounced AJAX calls (800ms delay after the last input) to minimize server requests. The live ratio check uses a 600ms debounce. Scripts are only loaded on the checkout page and relevant admin pages.

= Can I exclude certain user roles from tracking? =
Yes. Go to **CheckoutGuard > Settings > Settings tab > Exclude User Roles** and check the roles you want to exclude (e.g., Administrator, Shop Manager).

= What happens to incomplete records when a customer completes their order? =
They are automatically deleted. When a customer reaches the "Thank You" page, CheckoutGuard removes their incomplete checkout record and decrements the usage count.

= Can I customize the message shown to blocked customers? =
Yes. There are two customizable messages in the Settings:
1. **Custom Block Message** - shown when a customer is blocked by IP, email, or phone
2. **Blocked Order Notice** - shown when blocked by the success ratio system (supports `{ratio}` placeholder)

= What courier services are supported? =
The Courier Analytics feature checks delivery data from Pathao, RedX, Steadfast, Paperfly, and Carrybee courier services.

= Does this plugin comply with GDPR? =
Yes. CheckoutGuard integrates with the WordPress personal data export and erasure tools. A suggested privacy policy text is also provided for your Privacy Policy page. Data retention is configurable in the plugin settings.

= Do invoices generate automatically? =
Yes. A PDF invoice is generated automatically once an order reaches the Processing or Completed status, and it is attached to the order emails sent to the customer. You can also view, print, or download any invoice at any time from the Invoices admin page or the order screen.

= What paper sizes are supported for shipping labels? =
The Print page supports a full A4 invoice/label layout as well as 2x3 in and 3x3 in courier label sizes. Each size is pinned to the exact physical paper dimensions so what you see in the preview matches what comes out of the printer.

= Can I create an invoice without a WooCommerce order? =
Yes, with the Pro version. Blank manual invoices let you build a custom invoice and shipping label using freeform line items or products from your catalog, with no underlying order. They are fully isolated from stock, the Orders list, and sales reports.

= Is the invoice and shipping label feature free? =
The core invoicing and printing features (automatic PDF invoices, packing slips, and the Print page) are available on the free plan with usage limits. Blank manual invoices, custom numbering/tax fields, bulk ZIP download, and higher limits are part of the Pro plan.

= Is there a free version? =
Yes. The free plan includes a limited number of courier success-ratio checks per month. Your current limit and usage are shown on the plugin's Settings page. The Courier Analytics page is a paid feature. The Pro plan includes unlimited courier checks.

When a limit is reached, the plugin tells you clearly. Pricing and plan details: https://coderzonebd.com/products/checkoutguard#pricing

= How do I get my license activated? =
After installation, go to **CheckoutGuard > Settings** and click **Sync Now**. If your site shows as inactive, contact support via the WhatsApp group link provided on the settings page or visit [coderzonebd.com/contact](https://coderzonebd.com/contact).

== Screenshots ==

1. Dashboard Overview with pie chart and stat cards (screenshot-1.png)
2. Incomplete Checkouts list with action buttons (screenshot-2.png)	
3. Checkout detail modal popup (screenshot-3.png)
4. Fraud Blocker management page (screenshot-4.png)
5. Courier Analytics page with ratio results (screenshot-5.png)
6. WooCommerce order list with Success Ratio column (screenshot-6.png)
7. Order detail page with Success Ratio and Fraud Blocker meta boxes (screenshot-7.png)
8. Settings page with module toggles and configuration (screenshot-8.png)
9. WordPress Dashboard widget (screenshot-9.png)

== Changelog ==

= 1.5.0 =
* Security: after updating, CheckoutGuard reconnects to our server automatically. Nothing to do. If it cannot, a notice with a "Reconnect now" button is shown in the admin; orders always go through.
* Security: customer email and phone are no longer written into the page HTML of the block buttons and of masked checkouts.
* Feature: the Free plan shows full contact details for 5 new checkouts per usage period.
* Changed: on paid plans, an order with a low courier success ratio is now placed and put on hold for review instead of being blocked (you can switch back to blocking in Courier Analytics). Free plans block as before.
* Improved: automatic blocks no longer count toward the fraud usage limit, and the courier ratio check runs once per checkout.
* Improved: database updates are retried automatically; the settings page shows a notice if they fail three times in a row.
* Fixed: deleting a blocklist entry now tells a database error apart from an entry that no longer exists.

= 1.4.2 =
* Security: order data shown by the admin JavaScript on CheckoutGuard screens is now escaped (XSS fix).
* Fixed: a repeating key-rotation loop that could make the plugin call our server again and again after a new API key was issued. The plugin now retries once with the new key.
* Fixed: when our server answers "too many requests" (HTTP 429) to a new site key request, the plugin now waits (honouring Retry-After, at most 24 hours) and retries once, instead of asking again in a loop. At most two such requests are made per hour, and only one at a time.
* Improved: when a sync fails, the plugin now keeps its status instead of resetting it.
* Security: the live courier success-ratio lookup on the checkout page is now available to shop staff only (not to guests or logged-in customers).
* Fixed: blocked customers saw an empty error message after the settings were saved; the default message is shown again.
* Improved: settings sync. Changes that our server rejects or ignores are now rolled back with a clear admin notice, changes that cannot be confirmed show a warning and are matched at the next sync, and the settings request now times out after 5 seconds. A "Settings saved." message is shown after a successful save.
* Maintenance: line endings normalised to LF in all text files.

= 1.4.1 =
* Feature: Universal 6-Layer Validation Engine (`CG_Validation_Engine`) — complete fraud, duplicate, and courier protection across Classic WooCommerce, Gutenberg Checkout Block (Twenty Twenty-Five theme / FSE), CartFlows funnels, and Custom Landing Page order systems (`co-landing-pages` etc.).
* Feature: 6 Interception Layers — Early AJAX (`init`), Order Save Lifecycle (`woocommerce_before_order_object_save`), Store API (`woocommerce_store_api_checkout_update_order_from_request`), CartFlows order creation (`woocommerce_checkout_create_order`), Form Validation (`woocommerce_after_checkout_validation`), and Classic Process (`woocommerce_checkout_process`).
* Feature: Universal Incomplete Checkout Tracking — captures customer inputs dynamically across Block checkouts (`#shipping-*`, `#email`), landing page forms (`name="phone"`, `name="mobile"`, `name="address"`), and Classic forms in real-time.
* Feature: Single-product & Landing Page Variation Detection — auto-captures landing page product selections (`input[name="product_id"]`, `select[name="product_id"]`, `input[name="quantity"]`) and calculates exact cart values for direct single-page checkouts.
* Feature: Multi-key Incomplete Checkout Deduplication & Progressive Merge — links customer sessions, device fingerprints, and IPs dynamically to update the existing incomplete record instead of creating duplicate database entries.
* Feature: Lifecycle Cleanup on Order Placement — automatically cleans up incomplete checkout records upon order completion via `woocommerce_thankyou`, `woocommerce_checkout_order_processed`, and `woocommerce_new_order` with multi-key resolution and idempotency tracking (`_cg_incomplete_cleaned`).
* Feature: Universal Live Courier Success Ratio Check — dynamic event delegation across all phone field variants (`#shipping-phone`, `#billing_phone`, `input[name="phone"]`, `input[name="mobile"]`) with automatic feedback container placement and friendly status for new customers without prior delivery history.
* Enhancement: Multi-Platform Device Fingerprint Transport — attaches `X-CG-Device-FP` request header across jQuery AJAX (`$.ajaxPrefilter`), native `XMLHttpRequest`, `fetch()`, and Gutenberg `wp.apiFetch` for seamless fraud validation across REST and AJAX requests.
* Enhancement: Order Device & IP Persistence — guarantees customer device fingerprint and genuine client IP are saved to order metadata across Block and landing page orders for full admin visibility and single-click device blocking.
* Enhancement: Administrative Action Safety Guard — excludes administrative operations (such as "Recover Order" from Incomplete Checkouts dashboard and manual invoice generation) from customer-facing fraud and duplicate blocks.
* Fix: Restored native WooCommerce order bulk actions ("Change status to processing", "Change status to on-hold", "Change status to completed") on both HPOS (`woocommerce_page_wc-orders`) and Classic (`edit-shop_order`) tables while seamlessly appending custom status transitions.
* Fix: Added `get_shipping_phone()` fallback in WooCommerce Orders list Success Ratio column and order details metabox when billing phone is omitted in Block checkouts.

= 1.4.0 =
* Feature: Advanced Multi-Vector Duplicate Order Blocker — prevent rapid duplicate checkout submissions matching by Device Fingerprint, Client Public IP, Phone number, or Email address with configurable time windows (5, 10, 15, 30, 60 minutes) and Smart (exact cart items and quantities) or Strict mode.
* Feature: Pro-gated custom duplicate order notice with dynamic placeholders (`{order_id}`, `{time_window}`, `{phone}`) and interactive Pro hover lock overlay.
* Feature: Cloudflare Real IP Resolution Engine (`cg_get_client_ip`) — reliably captures genuine visitor public IP behind Cloudflare proxies, reverse proxies, and load balancers.
* Security: Protected Gateway & IP Guard (`cg_is_protected_ip`) — safeguards Cloudflare proxy CIDR ranges, server gateways, and admin IPs against being blocked manually or via auto-block.
* Security: "Protected Gateway" badge in order detail modals to prevent accidental administrator blocking of proxy IP nodes.
* Enhancement: Resilient Dual-Tier Device Fingerprinting — cross-synchronizes persistent device identity between browser cookies and LocalStorage with enhanced hardware GPU/audio profile.
* Enhancement: Client-side debounce on checkout submit to eliminate accidental double-click order duplications.
* Fix: Preserved native WooCommerce bulk actions ("Change status to processing", "Change status to on-hold", "Change status to completed", etc.) on order list screens, and added handlers for custom status bulk updates with feedback notices.
* Fix: Automatic database migrations on plugin load (`cg_maybe_run_migrations`) ensuring all fraud and attempt tables are created immediately upon update without requiring manual deactivation.
* Fix: Prevented data wipe on plugin deactivation; preserved all tables and settings unless explicit uninstall is performed.
* Fix: Corrected variable product recovery in incomplete checkouts, eliminating PHP 8 warnings and restoring exact product variations.
* Fix: Static cache clearing in custom order statuses (`cg_clear_order_statuses_cache`) ensuring real-time dropdown and badge updates.
* Fix: Harmonized all fraud velocity and auto-block time lookback queries with site local time.
* Performance: Short-circuited courier ratio check when orders are already blocked by previous fraud filters, avoiding wasted external API quota.
* Refactor: Decomposed monolithic admin and AJAX files into modular feature sub-packages for maintainability and performance.

= 1.3.1 =
* Fix: Updated the plugin release metadata and runtime asset version to 1.3.1.
* Fix: Pointed Headquarters API requests to the active licensing server endpoint.
* Fix: Improved compatibility with the licensing server's hardware-bound verification flow.
* Docs: Updated the stable release tag for the 1.3.1 deployment.

= 1.3.0 =
* Feature: Invoice system — automatic PDF invoices on order Processing/Completed, sequential numbering, company/logo header, and invoice attachment on customer emails.
* Feature: New Invoices admin page with a searchable, paginated list of every invoiced order, plus a dedicated Settings tab.
* Feature: Interactive Print page combining an A4 invoice and courier shipping labels (2x3 in, 3x3 in and A4) with barcode, QR code, COD box and courier badge.
* Feature: Blank manual invoices (Pro) — build a fully custom invoice and shipping label with no WooCommerce order, using freeform line items or items picked from the product catalog. These are fully isolated: they never affect stock, the Orders list, or sales reports.
* Feature: Pro invoice options — custom numbering prefix and reset period, Bangladesh VAT/TIN/BIN tax fields, editable footer text, bulk ZIP download, and courier shipping labels.
* Enhancement: Consolidated the order screen down to a single invoice action button, with the customer-facing My Account page keeping a plain PDF download.
* Enhancement: Declared WooCommerce High-Performance Order Storage (HPOS) compatibility.
* Fix: Order meta is now read and written through the WooCommerce order API. On HPOS stores the customer IP lookup used for the blocked-order indicator silently returned nothing, and plugin order meta was written to postmeta instead of the order tables.
* Fix: Packing slips rendered with prices and totals because the packing-slip flag was never received by the invoice template; they now correctly omit all pricing.
* Fix: Print output produced a blank page, and label sizes did not match the selected paper size. Page size and content are now pinned to the exact physical dimensions for A4, 2x3 in and 3x3 in.
* Fix: Upgrade to Pro buttons on the Invoices, Invoice Settings and Print screens did nothing because the upgrade modal was not rendered on those pages.
* Fix: PRO badges rendered as empty pills inside gradient section headings, which made their text transparent.
* Fix: All printed and displayed timestamps now include seconds, and the printed-time stamp follows the site timezone instead of a hardcoded one.
* Enhancement: Invoice options are removed on uninstall when "delete data on uninstall" is enabled.

= 1.2.1 =
* Feature: Expanded courier analytics to show all five couriers consistently (Pathao, Steadfast, RedX, Paperfly, Carrybee) across admin courier result views.
* Enhancement: Added response shape normalization for courier data to ensure reliable rendering even when some courier payload fields are missing.
* Enhancement: Added support for counts-unavailable courier responses with safe display fallback using dashes for unavailable Delivered, Cancelled, and Rate values.
* Fix: Improved courier check stability by preventing overlapping admin AJAX requests and removing duplicate generic error toasts.
* Fix: Improved courier timeout handling with a clearer user-facing message when upstream providers are slow to respond.
* Enhancement: Increased timeout tolerance for courier proxy requests to reduce premature timeout failures.
* Docs: Updated supported courier service listings in readme for consistency with the current integration.

= 1.2.0 =
* Feature: Free/Pro feature gating system for all plan tiers
* Feature: Phone number masking for free users everywhere numbers are displayed (format: 018*******)
* Feature: Email address masking for free users everywhere emails are displayed (format: cu***@domain.com)
* Feature: Hover-based lock overlays on locked sections — content visible normally, lock icon appears on hover
* Feature: Customer Info card locked in checkout and blocked order detail modals for free users
* Feature: Action buttons (Recover, Hold, Cancel, Re-open) locked in detail modals for free users
* Feature: Follow Up, Cancelled, Recovered, and Blocked Orders tabs locked for free users — only Incomplete tab remains free
* Feature: Fraud Blocking section locked in detail modals for free users (phone and email values masked in block buttons)
* Feature: Domain, Address, and Device blocking tabs locked for free users on Fraud Blocker page
* Feature: Advanced fraud blocker settings locked (Auto-Block, Rate Limiting, Device Blocking, Risk Scoring) for free users
* Feature: Courier Analytics page fully locked for free users
* Feature: "Upgrade to Pro" popup modal with WhatsApp Community, two direct WhatsApp contact buttons, and pricing page link
* Enhancement: All upgrade prompts now open the popup modal instead of navigating directly to pricing page
* Enhancement: WhatsApp contact URLs added as plugin constants (CG_WHATSAPP_CONTACT1, CG_WHATSAPP_CONTACT2)
* Enhancement: Dark mode support for all new lock overlays and upgrade modal

= 1.1.6 =
* Feature: Custom WooCommerce Order Status Manager (create, edit, delete statuses)
* Feature: Status overview cards with live order counts per status
* Feature: Visual icon picker with 30+ order-management icons and hover tooltips
* Feature: Edit and delete default WooCommerce statuses (with order migration support)
* Feature: Reset Order Statuses button in Settings to restore WooCommerce defaults
* Feature: Order Statuses module toggle in Settings (enable/disable the feature)
* Feature: "Show in Bulk Actions" toggle per order status — control which statuses appear in the WooCommerce orders bulk action dropdown
* Enhancement: Usage limits now fail-closed — actions are blocked when license cache is unavailable instead of allowing unlimited usage
* Enhancement: HQ sync frequency upgraded from daily to every 6 hours via cron, plus inline sync on admin page loads every 4 hours
* Enhancement: License cache populated immediately on plugin activation to prevent bypass via deactivate/reactivate
* Enhancement: Usage warning banners on all CheckoutGuard admin pages when approaching or hitting plan limits
* Enhancement: Limit-hit error messages now include Upgrade and WhatsApp contact links
* Enhancement: Page-load toast notifications for at-limit usage items
* Enhancement: 15-minute sync cooldown after failed HQ connection to prevent request hammering
* Enhancement: All statuses (default + custom) managed in a unified table with Type badges
* Enhancement: Custom statuses automatically register with WordPress and appear in WooCommerce order dropdowns
* Enhancement: Slug auto-generation from label with 17-character limit for WooCommerce compatibility
* Enhancement: Order migration prompt when deleting a status that has existing orders
* Enhancement: Bulk actions dropdown now dynamically built from managed statuses instead of relying on WooCommerce's hardcoded list
* Enhancement: "Show in Bulk Actions" defaults to disabled — admin must explicitly enable per status
* Fix: Reserved slug validation prevents creating statuses with slugs that conflict with WooCommerce internal hooks (e.g., "changed")

= 1.1.5 =
* Added proper privacy policy integration via WordPress Privacy Policy page
* Added GDPR-compliant personal data exporter and eraser hooks
* Added proper uninstall.php for clean plugin removal
* Added directory listing protection (index.php files)
* Added Text Domain header for WordPress.org compatibility
* Added Third-Party Services disclosure in readme
* Improved toast notification system for settings save feedback
* Fixed confirmation popups appearing behind detail modals (z-index)
* Fixed fraud blocker settings not persisting after page refresh
* Fixed CSS design issues across dashboard, filter bars, and stat cards
* Improved filter bar layout for checkout and blocked orders tabs
* Code quality improvements for WordPress.org plugin review compliance

= 1.1.0 =
* All internal prefixes updated from `act_` to `cg_`
* Text domain changed from `checkoutguard` to `checkoutguard`
* Complete UI/UX redesign with modern design system
* Dashboard cards in compact 4x2 grid layout
* WooCommerce-style table design on Courier Analytics page
* Enter key support on Courier Analytics form
* Real-time form validation with shake animation
* Critical fix: Resolved "An error occurred while fetching details" modal error
* Fixed padding and spacing issues across all admin pages
* Improved error handling and AJAX response validation
* Better keyboard navigation and accessibility
* Tested with WordPress 6.8.3 and latest WooCommerce

= 1.0.5 =
* Complete UI/UX redesign with modern design system
* Optimized dashboard widget size
* Unified design consistency across all admin pages
* Professional CSS styling with custom variables
* Improved settings page layout
* Code optimization for production

= 1.0.4 =
* Fix: Resolved WooCommerce order page breaking issue
* Fix: Corrected file loading order to prevent errors
* Improved code structure and organization

= 1.0.3 =
* Fix: Blocked order log instant deletion
* Fix: Checkout page block notices styled correctly
* Feature: Customer names in Blocked Orders Log
* Feature: Styled "Powered by" footer
* Enhancement: Robust phone number normalization

= 1.0.2 =
* Fix: Success ratio blocker grace period of 0 handling

= 1.0.1 =
* Initial Release

= 1.0 =
* Incomplete checkout tracking
* Manual order recovery
* Fraud Blocker (IP, Email, Phone)
* Dashboard Overview and detailed lists
* Courier Analytics
* Blocked Orders Log
* Admin settings for data retention and module toggles

== Upgrade Notice ==

= 1.5.0 =
Security and plan updates. After updating, the plugin reconnects automatically; no action needed.

= 1.4.2 =
Security fix for the admin screens and a fix for a connection loop after API key changes. Recommended for all sites.

= 1.4.1 =
Universal multi-platform release: Introduces the Universal 6-Layer Validation Engine protecting Classic WooCommerce, Gutenberg Checkout Blocks (Twenty Twenty-Five theme / FSE), CartFlows, and Custom Landing Pages. Adds dynamic Incomplete Checkout Tracking, Universal Live Courier Ratio checks, and restored native order bulk actions. Highly recommended for all users.

= 1.4.0 =
Major release: Adds Duplicate Order Blocker, Cloudflare Real IP resolution, protected IP gateway safeguards, dual-tier device fingerprinting, monolithic code decomposition, and critical data retention and variable product recovery fixes. Recommended for all users.

= 1.3.0 =
Adds a full invoicing and shipping-label system: automatic PDF invoices, packing slips, an interactive Print page for A4/2x3/3x3 labels, and a new Invoices admin page. Declares HPOS compatibility and fixes an HPOS bug that silently broke the blocked-order IP indicator and plugin order meta. Also fixes packing slips incorrectly showing prices, blank/mis-sized print output, dead Upgrade to Pro buttons, and invisible PRO badges. Recommended for all users.

= 1.2.1 =
Improves courier analytics reliability and UX by expanding full five-courier coverage in plugin views, handling partial courier payloads safely, reducing duplicate AJAX error noise, and adding clearer timeout feedback.

= 1.2.0 =
Introduces Free/Pro feature gating with hover-based lock overlays. Free users see masked phone and email data, locked tabs (Follow Up, Cancelled, Recovered, Blocked Orders), locked Customer Info and action buttons in detail modals, and locked advanced fraud/courier features. Adds an "Upgrade to Pro" popup modal with WhatsApp contact buttons. Recommended for all users.

= 1.1.6 =
New feature: Custom WooCommerce Order Status Manager. Create, edit, and delete order statuses with visual icons and color coding. Includes order migration support and a reset button. Enable it under Settings > Module Toggles. Adds per-status bulk action toggle, strengthened usage enforcement with fail-closed limits, more frequent HQ sync, and admin warning banners. Recommended for all users.

= 1.1.5 =
Privacy and compliance update. Adds GDPR data export/erasure support, privacy policy integration, and proper uninstall handling. Recommended for all users.

= 1.1.0 =
Major release with rebranding, complete UI redesign, and critical bug fixes. Recommended for all users.
