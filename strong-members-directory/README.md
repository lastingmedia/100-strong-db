# Strong Members Directory

WordPress plugin for a nonprofit member directory with:

- A `Members` custom post type
- Member fields for first name, last name, email, and occupation
- Featured image support for profile photos
- Bulk member import from CSV
- Elementor-friendly shortcode support
- A native Elementor widget when Elementor is active
- Optional members-only access control
- Automatic WordPress login creation for members with email addresses

## Installation

1. Copy the `strong-members-directory` folder into `wp-content/plugins/`
2. Activate **Strong Members Directory** in WordPress
3. Go to **Members** in the WordPress admin

## Managing Members

- Add individual members under **Members > Add New**
- Use the featured image as the member profile picture
- Use the main content editor for a short bio if needed
- When a member has a valid email address, the plugin creates or links a WordPress user account for them

## Member Login + Privacy

- A **Member Directory** page is created automatically on activation
- Members are redirected to that page after login
- By default, the directory page and member profile views are restricted to logged-in users
- You can change the landing page or toggle protection under **Members > Settings**
- You can also customize the message shown to logged-out visitors under **Members > Settings**
- New member accounts receive a WordPress password setup email when they are first created

## Bulk Import

Go to **Members > Import Members** and upload a CSV with headers like:

```csv
first_name,last_name,email,occupation,bio,profile_image_url
Jane,Doe,jane@example.org,Board Chair,"Short member bio",https://example.org/jane.jpg
John,Smith,john@example.org,Volunteer Coordinator,,
```

Behavior:

- `first_name` and `last_name` are required
- Existing members are updated by matching email first, then full name
- `profile_image_url` is optional and will be sideloaded as the featured image when possible
- Header names are not case-sensitive, so `First Name` and `first_name` both work
- New members get a WordPress login. The **Email new members a link to set their password** box is off by default, so an import is quiet unless you tick it

### Importing a Stripe export

Upload the Stripe **Subscriptions** CSV (Stripe Dashboard > Billing > Subscriptions > Export) on the same screen. It is recognized automatically by its columns, so no mapping is needed:

- **Customer Name** is split into first and last name. ALL CAPS and all-lowercase names are fixed, and middle initials are dropped. A one-word name is imported with no last name and flagged in the preview.
- **Customer Email** becomes the member email and login.
- The Stripe customer ID, subscription ID and status are saved on the member, so the billing panel and the billing page work straight away.
- Re-importing a later export is safe. Members are matched by Stripe customer ID first, then email, then name. Existing members only get their billing details refreshed. Their name, occupation, bio and contact details are never overwritten.
- Canceled and incomplete subscriptions are skipped.
- If one person pays for several subscriptions (for example a member sponsoring others), they stay one member. The first row is their main subscription, and the others are saved on the same member as additional subscriptions. The preview shows them as "Attach to member".
- Review the dry-run preview before choosing **Apply Import**.

## Stripe Integration

The plugin includes a Stripe module for importing subscription data and syncing membership status:

- Go to **Members > Import Stripe Subscriptions** to preview and commit a subscription import
- Active Stripe subscribers are linked to their member record by email
- Members can manage billing from the frontend via a billing action URL

A Stripe secret key must be configured under **Members > Settings** for this feature to work.

## Self-Serve Billing Page

A public **Membership Billing** page (`/billing`, shortcode `[strong_billing]`) is created on activation, so members and approved applicants can handle billing on the site instead of being sent Stripe links by hand:

- Logged-in members see their status and a **Start Membership Subscription** or **Manage Billing** button.
- Anyone else enters their email and is emailed a secure link (valid 24 hours). The page never reveals whether an address matched, and requests are rate limited.
- Approved applicants who open the link get the same billing setup step as the onboarding page.

Link the 100 Strong site menu, emails, or QR codes to `/billing`. The page ID is stored in the `smd_billing_page_id` option.
## Board Voting

The board votes in person; the site records the result. On an applicant record, the **Board Vote** box takes the Yes / No / Abstain counts:

- A majority of the whole board (more than half of **Members > Settings > Board Size**, default 8, so 5 votes) decides the application.
- Majority yes: status moves to approved and the applicant is emailed the approval, with their billing link and a note that the 100 Strong team will be in touch.
- Majority no: status moves to declined and the denial email is sent.
- No majority yet: the tally is saved and the applicant stays in Board Review. Record again when the vote is confirmed.
- The vote cannot be re-recorded once a final decision is made, and the totals cannot exceed the board size.

Edit the approval and denial wording under **Settings > Emails**.
## Failed Payments and Grace Period

- The first failed Stripe payment starts a grace clock (**Settings > Grace Period**, default 30 days). Later Stripe retries do not restart it.
- The member is emailed the date their access ends, and the workflow admin email is notified so the team can follow up.
- **Members > Billing Follow-up** lists everyone in grace (days left) or lapsed, with a **Reinstate** button.
- If the grace period ends with no payment, the member is lapsed automatically (a daily check): they are logged out and cannot log in, they are removed from the directory, and their profile page is hidden. Administrators are never locked out.
- A successful payment (Stripe `invoice.paid` or an active subscription) restores access automatically. **Reinstate** restores it manually; if the card is still failing, a new failure starts a new grace period.
## Managing Billing from WordPress

Administrators can run billing without opening the Stripe dashboard. On any member record, the **Stripe Billing** panel loads live from Stripe:

- Subscription status, plan and price, next charge date, and the card on file
- Payment history: every invoice with its result (paid, failed with the decline reason and next retry, open, void) and any refunds
- Actions: email the member an update-card link, pause or resume billing, cancel at period end (or undo it), cancel immediately, refund a payment in full or part, and email a receipt
- A **Staff actions** log shows who did what and when

The **Members** list has a **Billing** column and a filter for Active, In grace period, Past due, Lapsed, Canceled and No subscription.

All actions require an administrator account. Refunds and receipts are checked to belong to that member's Stripe customer, and refunds use an idempotency key so a double click cannot refund twice. Members must be linked to a Stripe customer (by subscribing or via the Stripe import).
## Member Profile Details and Privacy

Members edit their own profile from their profile page (**Edit my contact details, bio and privacy**):

- Phone, LinkedIn, website and an "About you" bio (up to 2,000 characters). LinkedIn must be a linkedin.com address.
- Each member chooses what other members see: email, phone, LinkedIn, website. **Email and phone are private until the member opts in**; LinkedIn and website are shown by default. Members and administrators always see everything on their own record.
- Hidden details are also left out of directory search, so they cannot be found by searching for them.
- Administrators can see and change these choices on the member record in WordPress. CSV import and export already include phone, website, LinkedIn and bio.

## Elementor Usage

Use either approach:

- Add the **Members Directory** Elementor widget
- Or drop a shortcode into Elementor's shortcode widget
- Directory cards link to each member's own condensed profile page

### Shortcodes

```text
[strong_members columns="3" limit="-1" order="ASC"]
[strong_member id="123"]
```
