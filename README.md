# WC Chat

A custom WordPress plugin that adds a buyer - merchant/designer/agent chat to WooCommerce product pages and anywhere via shortcode. It's role-aware, WooCommerce awayre, ships with a REST API, AJAX polling, read/unread receipts, typing indicator, online presence, file uploads and email notifications.

## Setup & Installation

1. **Install the plugin**
    - Copy the folder `wc-chat/` into `/wp-content/plugins/`
    - Activate the plugin in the WordPress admin
2. **Flush permalinks**
   - Go to **Settings -> Permalinks -> Save**
3. **Create test roles/users**
4. **Create a product**
5. **Place the widget on a page**
    - It is automatically injected on product pages
    - Or use shortcode anywhere:
   ```
   [wc_chat product_id="123"]
    ```
6. **Configure email (SMTP)**
    - Use an SMTP plugin (WP Mail SMTP)
7. **Settings screen (Optional**
    - Go to **Settings -> WC Chat**
    - Agent assignment: **Robin-round / Random / None**
    - Auto-assign: **merchant** (product author)
    - Auto-assign: **designer** (via meta key, default `_wcchat_designer_user_id`)
    - Max upload size (MB) for chat attachments
    - Auto-join agents/admins (optional)

## How It Works

- Opening a product page as a **Customer** creates (or reuses) a **session** tied to that product.
- **Participants auto-join**:
    - Buyer (current user)
    - Merchant (product author)
    - Optional designer (via product meta)
    - One agent (robin-round or random)—if enabled
- **Find or create logic** ensures the same user reuses their session per product.
- Frontend **polls** for new messages and typing status; presence heartbeats every ~20s.

## Technologies Used

- **WordPress** (CPT, REST API, Shortcode, Roles/Capabilities)
- **WooCommerce** (product context & single product hook)
- **PHP 8** (plugin codebase)
- **MySQL** (custom tables via `dbDelta`)
- **AJAX / JavaScript** (polling + UI)
- **CSS** (responsive, dark/light theming)
- **Transients API** (typing + presence TTL)
- **wp_mail** (email notifications)

## Challenges Encountered & How They Were Addressed

1. **REST 404 (`rest_no_route)**
   - Cause: The routes were not getting registered.
   - Solution: Ensured `\WCChat\Rest::register_routes()` runs on `rest_api_init` and localized `rest_url('wcchat/v1/')`.
2. **DB insert error: `Unknown column 'role'`**
    - Cause: using `role` instead of schema's `role_slug`.
    - Solution: within the `Utils::add_participant()` method, replace `role` with `role_slug`
3. **The typing indicator was not working as expected**
    - Cause: A single endpoint was being used for both set & check.
    - Solution: split `/typing` into `POST` (set) and `GET` (peek).
4. **Online presence was not working as expected**
    - Cause: only heartbeats; no participant updates.
    - Solution: added `/participants` route and client-side `presenceLookup()` polling.
5. **Duplicate sessions per user/product**
   - Cause: no "find-or-create" logic.
   - Solution: Look up an existing session (user and product) before inserting.
6. **File uploads**
   - Cause: no file upload endpoint.
   - Solution: added `/files` endpoint.
7. **Busy merchant scenario**
    - Cause: merchant might be offline/occupied.
    - Solution: auto-assign to an **agent** by policy (round-robin/random) and escalation hook on new buyer message.
