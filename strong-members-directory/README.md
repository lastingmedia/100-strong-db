# Strong Members Directory

WordPress plugin for a nonprofit member directory with:

- A `Members` custom post type
- Member fields for first name, last name, email, and occupation
- Featured image support for profile photos
- Bulk member import from CSV
- Stripe subscription import and billing management
- Member application workflow with admin review
- Elementor-friendly shortcode support
- A native Elementor widget when Elementor is active
- Optional members-only access control
- Automatic WordPress login creation for new members

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

## Stripe Integration

The plugin includes a Stripe module for importing subscription data and syncing membership status:

- Go to **Members > Import Stripe Subscriptions** to preview and commit a subscription import
- Active Stripe subscribers are linked to their member record by email
- Members can manage billing from the frontend via a billing action URL

A Stripe secret key must be configured under **Members > Settings** for this feature to work.

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
