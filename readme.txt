=== CheckoutGuard ===
Contributors: coderzonebd, devrkb21
Donate link: https://donate.coderzonebd.com/
Tags: woocommerce, checkout, incomplete orders, abandoned cart, tracker
Requires at least: 5.6
Requires PHP: 7.4
WC requires at least: 5.0
WC tested up to: 10.4.2
Tested up to: 6.9
Stable tag: 1.1.2
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Track and manage incomplete WooCommerce checkouts, protect your store with fraud prevention, and check courier success rates.

== Description ==

Ever wonder how many sales you're losing from abandoned carts? CheckoutGuard is a lightweight and powerful WooCommerce plugin that helps you understand *why*.

It automatically captures customer details (like email, phone, and cart items) in real-time *as they type* on the checkout page. This gives you valuable insights into who your customers are and what they're leaving behind, helping you identify potential issues and gain a clear view of your sales funnel.

All data is stored securely in your *own* WordPress database, not on an external server, ensuring maximum privacy and security.

= Key Features =

* **Track Incomplete Checkouts:** Automatically capture customer name, email, phone, and cart details for checkouts that aren't completed.
* **Courier Success Rate Checker:** NEW! Check customer courier success rates across multiple courier services (Pathao, Steadfast, RedX) to assess delivery risk.
* **Local Caching System:** Smart 6-hour caching reduces API calls and improves performance.
* **No Limits:** See a complete list of all incomplete checkouts with no limits on the amount of data you can store.
* **Simple Fraud Blocker:** Protect your store by blocking specific phone numbers from placing orders. You can block an unlimited number of phone numbers.
* **Dashboard Widget:** Get a quick summary of incomplete checkouts from the last 24 hours directly on your WordPress dashboard.
* **Order Page Integration:** View and block a customer's phone number directly from the WooCommerce order edit page.
* **100% Local Data:** All your customer and cart data stays on your server.

Looking for more features? A Pro version with advanced analytics, IP/email blocking, and one-click recovery is available on our website.

== Installation ==

1.  Download the plugin .zip file from the WordPress directory.
2.  Go to your WordPress admin dashboard, navigate to **Plugins > Add New**.
3.  Click on "Upload Plugin" and choose the downloaded .zip file.
4.  Click "Install Now" and then "Activate Plugin".
5.  After activation, you will find "CheckoutGuard" in your WordPress admin menu. No further configuration is needed for the plugin to start working.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =
Yes, CheckoutGuard is an extension for WooCommerce and requires it to be installed and active.

= What is the Courier Check feature? =
The Courier Check feature allows you to verify a customer's courier success rate by checking their phone number against multiple courier services (Pathao, Steadfast, RedX). This helps you assess the risk of delivery failure before accepting an order.

= How does the caching system work? =
When you check a phone number, the results are cached locally in your WordPress database for 6 hours. If you search for the same number within this period, the results are retrieved from the local cache instead of making a new API call. You can bypass the cache if you need fresh data.

= Where is the data stored? =
All data collected by this plugin (incomplete checkouts, blocked phone numbers, courier search history) is stored locally in your WordPress database.

= Can I delete the courier search history? =
Yes! You can delete individual search entries by clicking the trash icon on each item, or use the "Clear All" button to delete all search history at once.

= Is there a premium version? =
Yes, a Pro version with advanced features is available on our website. This free version provides fully functional checkout tracking, phone blocking, and courier checking.

== Screenshots ==

1.  Dashboard overview showing incomplete checkout statistics. (screenshot-1.png)
2.  The "Incomplete Checkouts" page showing all captured carts. (screenshot-2.png)
3.  The "Fraud Blocker" page where you can block unlimited phone numbers. (screenshot-3.png)
4.  The "Courier Checker" page where you can check unlimited courier results. (screenshot-4.png)

== Changelog ==

= 1.1.0 =
* Feature: NEW Courier Check - Check customer courier success rates across multiple services (Pathao, Steadfast, RedX).
* Feature: Risk assessment system with 4 levels (Safe, Mid-safe, Risk, High Risk) based on courier performance.
* Feature: Local caching system (6-hour cache) reduces API server load and improves performance.
* Feature: Recent search history with individual delete option - track and manage your courier checks.
* Feature: "Clear All" button to delete all search history at once.
* Feature: Modern, animated UI with smooth transitions and interactive elements.
* Feature: Phone number validation for Bangladesh format.
* Feature: Detailed statistics including total orders, failed deliveries, and success rates.
* Feature: Cache indicators showing whether results are from local cache or API server.
* Enhancement: Improved admin interface styling with CSS3 animations and gradients.
* Enhancement: Better user feedback with loading states, error messages, and success notifications.
* Enhancement: Responsive design for mobile and tablet devices.
* Enhancement: Accessibility improvements with ARIA labels and focus states.
* Security: Nonce verification and capability checks for all AJAX operations.
* Security: Input sanitization and output escaping throughout.
* Tweak: Added new database table for courier search history.
* Tweak: Updated plugin description to include courier checking features.

= 1.0.2 =
* Tweak: Updated and tested for WordPress 6.8.3 and WooCommerce 8.9.

= 1.0.1 =
* Initial public release of CheckoutGuard!
* Feature: Track unlimited incomplete checkouts.
* Feature: Block an unlimited number of phone numbers in the Fraud Blocker.
* Feature: Full phone numbers are now visible for all incomplete checkouts.
* Tweak: Streamlined admin interface and dashboard.

## Privacy Policy 
CheckoutGuard uses [Appsero](https://appsero.com) SDK to collect some telemetry data upon user's confirmation. This helps us to troubleshoot problems faster & make product improvements.

Appsero SDK **does not gather any data by default.** The SDK only starts gathering basic telemetry data **when a user allows it via the admin notice**. We collect the data to ensure a great user experience for all our users. 

Integrating Appsero SDK **DOES NOT IMMEDIATELY** start gathering data, **without confirmation from users in any case.**

Learn more about how [Appsero collects and uses this data](https://appsero.com/privacy-policy/).