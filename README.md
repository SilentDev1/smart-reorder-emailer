# Smart Reorder Emailer Pro

A custom WordPress and WooCommerce plugin built by **Hung Cao / Cao-Tech LLC** for purchase-history analysis, reorder reminders, AI-assisted email writing, and customer follow-up workflows.

The plugin brings customer analysis, campaign preparation, sending controls, and performance reporting into WordPress administration. It combines **OpenAI-generated email content** with **rule-based purchase-cycle analysis and segmentation**, giving store administrators a way to review customer activity and manage follow-up communication from one interface.

## My contribution

I built the plugin's purchase-analysis logic, OpenAI integration, email-generation and delivery workflows, customer segmentation, prediction routines, campaign tools, reporting screens, and WooCommerce order integration.

The implementation also includes signed tracking and unsubscribe links, recipient suppression, loyalty points, automated follow-ups, license management, and plugin update integration. The supplied build is packaged as a single PHP plugin file with named functions for each subsystem.

## AI features

### Personalized email content

The OpenAI integration generates an HTML email body using the customer's name, product, segment, time since the last order, average reorder cycle, and optional discount information. Store name and tone settings help shape the result.

The code records AI generation summaries and token usage for administration. Email preview and previewed-send handlers support reviewing a message before sending it.

### Subject-line generation

A separate OpenAI request generates subject-line alternatives for a product and customer segment. The implementation supports customer and product placeholders and supplies fallback subjects if the AI request fails. These alternatives can support the plugin's A/B testing workflow.

### Analysis versus generation

OpenAI supplies the email-writing functionality. Reorder dates, customer segments, churn-risk scores, and cross-sell relationships are calculated by the plugin's own rules and purchase-history logic; they are not separate trained machine-learning models.

## Customer analysis

| Feature | Implementation |
| --- | --- |
| Purchase patterns | Analyzes WooCommerce orders to identify customer/product purchase activity and reorder intervals. |
| Segmentation | Assigns new, loyal, VIP, at-risk, or churned segments using order count, recency, frequency, and revenue. |
| Reorder estimates | Estimates the next date from the last order and average order frequency, with handling for overdue cycles. |
| Risk scoring | Calculates an overdue-ratio and recency-based score. |
| Real-time updates | Order hooks refresh customer segmentation after a purchase. |
| Cross-sell associations | Counts product pairs in recent completed orders to identify items purchased together. |

The estimates and scores help organize follow-up work. They are heuristic indicators rather than guaranteed predictions of customer behavior.

## Campaign and email workflows

- **Reorder reminders:** scheduled processing and manual sending for eligible customer/product records.
- **Review controls:** test email, preview, selected-recipient sending, previewed-message sending, and bulk handlers.
- **Win-back campaigns:** campaign creation and activation controls with scheduled processing.
- **Discount codes:** WooCommerce coupon generation using customer and segment context.
- **A/B tests:** subject/body variants, send and click counters, and winner selection based on implemented thresholds. This is a click-rate rule, not a formal statistical-significance test.
- **Post-purchase follow-ups:** scheduled thank-you and review-request messages.
- **Loyalty:** points awarded through completed-order hooks, tier handling, tier-upgrade emails, and an account-facing widget.
- **Churn alerts:** administrator notifications for identified customer-risk conditions.

## Tracking, attribution, and unsubscribe handling

The plugin includes email open/click tracking and stores sent-message content for later review. Order attribution logic connects eligible orders to email activity, and the WooCommerce admin shows source indicators, filters, and an email viewer.

The mail wrapper validates recipient addresses, checks a suppression list, and adds unsubscribe headers. Signed unsubscribe URLs and administrator-managed unsubscribe records support recipient opt-out handling.

Tracking and revenue attribution are the plugin's recorded indicators; they should not be interpreted as proof that an email caused a purchase.

## WordPress administration

The interface groups customer, campaign, and analytics views into tabbed screens. It includes:

- A dashboard and WordPress dashboard widget.
- Purchase-pattern, customer-segment, and reorder-prediction views.
- Email preparation, preview, and sending tools.
- Discount, A/B test, win-back, and loyalty screens.
- Send logs, AI-generation logs, analytics, and unsubscribe management.
- Settings, licensing, and update-service integration.

The plugin declares WooCommerce High-Performance Order Storage compatibility and implements both legacy and HPOS order-list hooks. This declaration and code support are present in the source; runtime compatibility was not retested during the portfolio upload.

## Architecture and code guide

All implementation is in [smart-reorder-emailer.php](smart-reorder-emailer.php).

| Subsystem | Representative functions |
| --- | --- |
| Lifecycle and storage | `sre_activate`, `sre_boot`, `sre_create_tables`, `sre_set_defaults` |
| Scheduled processing | `sre_daily_job`, `sre_maybe_schedule` |
| Customer analysis | `sre_run_analysis`, `sre_run_segmentation`, `sre_calculate_segment`, `sre_calculate_risk` |
| Reorder estimates | `sre_run_predictions` |
| AI writing | `sre_openai_request`, `sre_generate_ai_email`, `sre_ai_generate_subject_lines` |
| Email delivery | `sre_process_and_send`, `sre_send_one`, `sre_safe_mail` |
| Campaigns | `sre_generate_discount`, `sre_create_ab_test`, `sre_process_winback_campaigns` |
| Tracking and attribution | `sre_add_tracking`, `sre_handle_tracking`, `sre_attribute_revenue` |
| Unsubscribe handling | `sre_is_unsubscribed`, `sre_unsub_signature`, `sre_handle_unsubscribe` |
| Follow-ups and loyalty | `sre_schedule_followup`, `sre_award_loyalty_points`, `sre_check_churn_alerts` |

The daily job runs analysis and enabled Pro routines before processing reminders. Named functions separate responsibilities within the single-file distribution, while custom WordPress tables and options store runtime state.

## Technology and installation

**WordPress · WooCommerce · PHP · JavaScript · HTML email · OpenAI API · WP-Cron · WordPress database APIs**

The plugin header declares WordPress **5.8+**, PHP **7.2+**, and WooCommerce **5.0+**. These are declared minimums rather than a verified compatibility matrix.

1. Prepare a WordPress and WooCommerce development installation with test orders and a test mail destination.
2. Upload [dist/smart-reorder-emailer.zip](dist/smart-reorder-emailer.zip) through **Plugins → Add New → Upload Plugin**, or place the source in `wp-content/plugins/smart-reorder-emailer/`.
3. Activate **Smart Reorder Emailer Pro** and open its settings.
4. Configure sender information, enabled workflows, OpenAI credentials, and any required license.
5. Review analysis results and preview/test messages before enabling routine delivery.

Activation registers a daily scheduled job. Outgoing mail uses WordPress `wp_mail`; mail transport is configured separately. Provider accounts and the external Cao-Tech license/update service are not included.

## Repository scope and validation

This repository contains the custom source and an installable archive. It contains no production customer database, order export, configured API credentials, or sent-email logs.

Portfolio preparation checked for common embedded credential patterns, verified documentation links, and compared the deployment archive with the source. No live emails were sent and no WordPress, OpenAI, or mail-delivery integration tests were run during this upload.

## Author

**Hung Cao — Cao-Tech LLC**

Custom WordPress plugins, WooCommerce automation, AI-assisted applications, and customer lifecycle tooling.
