<?php
/**
 * Protected member profile template.
 *
 * @package StrongMembersDirectory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="primary" class="site-main">
	<div class="smd-protected-template">
		<?php echo SMD_Shortcodes::render_protected_profile_message(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
</main>
<?php
get_footer();
