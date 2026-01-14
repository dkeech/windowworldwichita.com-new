<?php

namespace TrimarkDigital\Framework\Setup;

/**
 * Theme setup
 */
function setup() {
	// Make theme available for translation
	// Community translations can be found at https://github.com/roots/sage-translations
	load_theme_textdomain('trimark', get_template_directory() . '/lang');

	// Enable plugins to manage the document title
	// http://codex.wordpress.org/Function_Reference/add_theme_support#Title_Tag
	add_theme_support('title-tag');

	// Register wp_nav_menu() menus
	// http://codex.wordpress.org/Function_Reference/register_nav_menus
	register_nav_menus([
		'primary_navigation' => __('Primary Navigation', 'trimark'),
		'utility_navigation'  => __('Utility Navigation', 'trimark'),
		'footer_navigation'  => __('Footer Navigation', 'trimark'),
	]);

	// Enable post thumbnails
	// http://codex.wordpress.org/Post_Thumbnails
	// http://codex.wordpress.org/Function_Reference/set_post_thumbnail_size
	// http://codex.wordpress.org/Function_Reference/add_image_size
	add_theme_support('post-thumbnails');

	// Enable HTML5 markup support
	// http://codex.wordpress.org/Function_Reference/add_theme_support#HTML5
	add_theme_support('html5', ['caption', 'comment-form', 'comment-list', 'gallery', 'search-form']);

	// Head cleanup + Security
	remove_action('wp_head', 'wp_generator'); // WP version
	remove_action('wp_head', 'rsd_link'); // EditURI link
	remove_action('wp_head', 'wlwmanifest_link'); // windows live writer
}
add_action('after_setup_theme', __NAMESPACE__ . '\\setup');

/**
 * Move Yoast Metabox to Bottom.
 */
add_filter('wpseo_metabox_prio', function () {
	return 'low';
});

/**
 * Add sitemap to default WP robots.txt
 */
add_filter('robots_txt', function ($output, $public) {
	$site_url = parse_url(site_url());
	$output .= "Sitemap: {$site_url['scheme']}://{$site_url['host']}/sitemap_index.xml\n";
	return $output;
}, 99, 2);

/**
 * Read more link for excerpts.
 *
 * @param  string $more
 * @return string
 */
function excerpt_more($more) {
	return sprintf(
		'... <a href="%1$s" class="read-more-link">%2$s</a>',
		esc_url(get_permalink(get_the_ID())),
		sprintf(__('Continue reading %s', 'wpdocs'), '<span class="screen-reader-text">' . get_the_title(get_the_ID()) . '</span>')
	);
}
add_filter('excerpt_more', __NAMESPACE__ . '\\excerpt_more');


/**
 * Override native WP PHPMailer credentials with SendGrid API.
 *
 * @param  object $phpmailer
 * @return void
 */
function phpmailer_init($phpmailer) {
	if (wp_get_environment_type() === 'production' || wp_get_environment_type() === 'staging') {
		global $sendgrid_sent_successfully;
		if ($sendgrid_sent_successfully) {
			$phpmailer->ClearAllRecipients();
		}
	}
}
add_action('phpmailer_init', __NAMESPACE__ . '\\phpmailer_init', 999);

/**
 * Send email via SendGrid API instead of SMTP
 *
 * @param  array $args
 * @return array
 */
function send_via_sendgrid_api($args) {
	if (wp_get_environment_type() !== 'production' && wp_get_environment_type() !== 'staging') {
		return $args;
	}
	
	$api_key = getenv('SENDGRID_API_KEY');
	
	// Format recipients
	$to_emails = is_array($args['to']) ? $args['to'] : explode(',', $args['to']);
	$to_formatted = array_map(function($email) {
		return ['email' => trim($email)];
	}, $to_emails);
	
	// Handle BCC from headers
	$bcc_formatted = [];
	if (!empty($args['headers'])) {
		$headers = is_array($args['headers']) ? $args['headers'] : explode("\n", str_replace("\r\n", "\n", $args['headers']));
		foreach ($headers as $header) {
			if (stripos($header, 'Bcc:') === 0) {
				$bcc_emails = trim(substr($header, 4));
				$bcc_list = explode(',', $bcc_emails);
				foreach ($bcc_list as $bcc_email) {
					$bcc_formatted[] = ['email' => trim($bcc_email)];
				}
			}
		}
	}
	
	// Check if message contains HTML
	$is_html = (stripos($args['message'], '<html') !== false || stripos($args['message'], '<!doctype') !== false);
	$content_type = $is_html ? 'text/html' : 'text/plain';
	
	$data = [
		'personalizations' => [
			[
				'to' => $to_formatted
			]
		],
		'from' => ['email' => 'noreply@trimarkleads.com', 'name' => get_bloginfo('name')],
		'subject' => $args['subject'],
		'content' => [
			['type' => $content_type, 'value' => $args['message']]
		]
	];
	
	// Add BCC if present
	if (!empty($bcc_formatted)) {
		$data['personalizations'][0]['bcc'] = $bcc_formatted;
	}
	
	$response = wp_remote_post('https://api.sendgrid.com/v3/mail/send', [
		'headers' => [
			'Authorization' => 'Bearer ' . $api_key,
			'Content-Type' => 'application/json'
		],
		'body' => json_encode($data),
		'timeout' => 30
	]);
	
	if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) == 202) {
		global $sendgrid_sent_successfully;
		$sendgrid_sent_successfully = true;
	}
	
	return $args;
}
add_filter('wp_mail', __NAMESPACE__ . '\\send_via_sendgrid_api', 10, 1);

/**
 * Override wp_mail return value when SendGrid API succeeds
 */
function override_wp_mail_return($result) {
	global $sendgrid_sent_successfully;
	if ($sendgrid_sent_successfully) {
		$sendgrid_sent_successfully = false;
		return true;
	}
	return $result;
}
add_filter('wp_mail', __NAMESPACE__ . '\\override_wp_mail_return', 9999);