<?php

/**
 * @link              https://github.com/Jacques-Murray
 * @since             1.0.0
 * @package           Syntech_Importer
 *
 * @wordpress-plugin
 * Plugin Name:       Syntech Product Importer
 * Plugin URI:        https://github.com/Jacques-Murray/syntech-product-importer
 * Description:       A production-ready plugin to import products from Syntech JSON feed into WooCommerce.
 * Version:           1.0.0
 * Author:            Jacques Murray
 * Author URI:        https://github.com/Jacques-Murray/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       syntech-importer
 * Domain Path:       /languages
 */

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

/**
 * Main Importer Class
 * Handles fetching, parsing, and saving product data.
 */
class Syntech_Product_Importer
{

	/**
	 * The feed URL provided by the supplier.
	 * * @var string
	 */
	private const FEED_URL = 'https://www.syntech.co.za/feeds/feedhandler.php?key=FE23CA30-8BC3-45F0-9E12-0AA3BDA10BD7&feed=syntech-json-full';

	/**
	 * Initialize the class and hooks.
	 */
	public function __construct()
	{
		add_action('admin_menu', [$this, 'register_admin_menu']);
		add_action('admin_post_syntech_run_import', [$this, 'handle_manual_import']);
	}

	/**
	 * Register a sub-menu under Products to trigger the import.
	 */
	public function register_admin_menu(): void
	{
		add_submenu_page(
			'edit.php?post_type=product',
			'Syntech Import',
			'Syntech Import',
			'manage_options',
			'syntech-importer',
			[$this, 'render_admin_page']
		);
	}

	/**
	 * Render the admin interface for manual triggering.
	 */
	public function render_admin_page(): void
	{
?>
		<div class="wrap">
			<h1>Syntech Product Importer</h1>
			<p>Click the button below to fetch the feed and update products. This process may take several minutes.</p>
			<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
				<input type="hidden" name="action" value="syntech_run_import">
				<?php wp_nonce_field('syntech_import_verify', 'syntech_import_nonce'); ?>
				<button type="submit" class="button button-primary">Run Import Now</button>
			</form>
		</div>
<?php
	}

	/**
	 * Handle the manual form submission.
	 */
	public function handle_manual_import(): void
	{
		if (! isset($_POST['syntech_import_nonce']) || ! wp_verify_nonce($_POST['syntech_import_nonce'], 'syntech_import_verify')) {
			wp_die('Security check failed');
		}

		if (! current_user_can('manage_options')) {
			wp_die('Insufficient permissions');
		}

		// Increase limits for large feed processing
		set_time_limit(0);
		ini_set('memory_limit', '1024M');

		$result = $this->process_feed();

		if (is_wp_error($result)) {
			wp_die($result->get_error_message());
		}

		wp_redirect(admin_url('edit.php?post_type=product&page=syntech-importer&import=success'));
		exit;
	}

	/**
	 * Fetch the feed and iterate through products.
	 * 
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	private function process_feed()
	{
		$response = wp_remote_get(self::FEED_URL, ['timeout' => 120]);

		if (is_wp_error($response)) {
			return $response;
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (empty($data) || ! isset($data['syntechstock']['products'])) {
			return new WP_Error('invalid_feed', 'Invalid JSON structure or empty feed.');
		}

		$products = $data['syntechstock']['products'];

		foreach ($products as $item) {
			$this->create_or_update_product($item);
		}

		return true;
	}

	/**
	 * Create or update a single product in WooCommerce.
	 * 
	 * @param array $item The raw product data from the JSON feed.
	 */
	private function create_or_update_product(array $item): void
	{
		$sku = sanitize_text_field($item['sku']);

		// Try to find existing product ID by SKU
		$product_id = wc_get_product_id_by_sku($sku);

		if ($product_id) {
			$product = wc_get_product($product_id);
		} else {
			$product = new WC_Product_Simple();
			$product->set_sku($sku);
		}

		// 1. Basic Data
		$product->set_name(sanitize_text_field($item['name']));
		// Description typically contains HTML in feeds, so we use wp_kses_post
		$product->set_description(wp_kses_post($item['description']));

		if (! empty($item['shortdesc'])) {
			$product->set_short_description(wp_kses_post($item['shortdesc']));
		}

		// 2. Pricing Logic
		// 'rrp_incl' is the Recommended Retail Price -> Mapped to WooCommerce Regular Price
		// 'price' is Cost Price (ex VAT) -> Mapped to custom meta field
		$rrp = isset($item['rrp_incl']) ? (float) $item['rrp_incl'] : 0;
		$cost_price = isset($item['price']) ? (float) $item['price'] : 0;

		if ($rrp > 0) {
			$product->set_regular_price($rrp);
		}

		// Handle promo pricing
		if (!empty($item['promo_price']) && $item['promo_price'] > 0) {
			$product->set_sale_price((float) $item['promo_price']);
		}
		update_post_meta($product_id, '_cost_price', $cost_price);
		// Sum up stock from all branches
		$total_stock = (int) $item['cptstock'] + (int) $item['jhbstock'] + (int) $item['dbnstock'];
		$product->set_manage_stock(true);
		$product->set_stock_quantity($total_stock);

		// 4. Dimensions & Weight
		if (isset($item['weight'])) $product->set_weight($item['weight']);
		if (isset($item['length'])) $product->set_length($item['length']);
		if (isset($item['width'])) $product->set_width($item['width']);
		if (isset($item['height'])) $product->set_height($item['height']);

		// 5. Categories
		// Feed example: "Networking & security > Routers & mesh"
		if (! empty($item['categorytree'])) {
			$this->assign_categories($product, $item['categorytree']);
		}

		// 6. Attributes (Brand, Warranty, etc)
		if (! empty($item['attributes'])) {
			$this->assign_attributes($product, $item['attributes']);
		}

		// Save initially to get an ID (required for image attachment)
		$product_id = $product->save();

		// Save Cost Price to meta for internal reference
		update_post_meta($product_id, '_cost_price', $cost_price);
		// Only process images if the product doesn't have one, or to update gallery.
		// Note: Image downloading is resource-intensive.
		if (! empty($item['featured_image'])) {
			$this->set_image($product_id, $item['featured_image'], true);
		}

		if (! empty($item['all_images'])) {
			// all_images is often a pipe-separated string in Syntech feeds
			$gallery_urls = explode(' | ', $item['all_images']);
			$gallery_ids = [];
			foreach ($gallery_urls as $url) {
				// Skip if it's the same as featured image
				if (trim($url) === trim($item['featured_image'])) continue;

				$image_id = $this->set_image($product_id, trim($url), false);
				if ($image_id) {
					$gallery_ids[] = $image_id;
				}
			}
			$product->set_gallery_image_ids($gallery_ids);
			$product->save();
		}
	}

	/**
	 * Handle recursive category creation and assignment.
	 * 
	 * @param WC_Product $product The product object.
	 * @param string $category_string The category string (e.g., "Parent > Child").
	 */
	private function assign_categories(WC_Product $product, string $category_string): void
	{
		// Clean up the string and explode by '>'
		$categories = array_map('trim', explode('>', $category_string));
		$term_ids = [];
		$parent_id = 0;

		foreach ($categories as $cat_name) {
			if (empty($cat_name)) continue;

			// Check if term exists under the specific parent
			$term = term_exists($cat_name, 'product_cat', $parent_id);

			if (! $term) {
				$term = wp_insert_term($cat_name, 'product_cat', ['parent' => $parent_id]);
			}

			if (! is_wp_error($term) && isset($term['term_id'])) {
				$parent_id = $term['term_id'];
				$term_ids[] = $parent_id;
			}
		}

		if (! empty($term_ids)) {
			$product->set_category_ids($term_ids);
		}
	}

	/**
	 * Assign attributes like Brand or Color to the product.
	 * 
	 * @param WC_Product $product The product object.
	 * @param array $attributes_data Associative array of attributes.
	 */
	private function assign_attributes(WC_Product $product, array $attributes_data): void
	{
		$wc_attributes = [];

		foreach ($attributes_data as $name => $value) {
			if (empty($value)) continue;

			$attribute = new WC_Product_Attribute();
			$attribute->set_name(ucfirst($name)); // e.g., "Brand"
			$attribute->set_options([$value]);
			$attribute->set_position(0);
			$attribute->set_visible(true);
			$attribute->set_variation(false);

			$wc_attributes[] = $attribute;
		}

		$product->set_attributes($wc_attributes);
	}

	/**
	 * Download image from URL and attach to product.
	 * 
	 * @param int $product_id The product ID.
	 * @param string $image_url The external image URL.
	 * @param bool $is_featured Whether this is the featured image.
	 * @return int|false Attachment ID on success, false on failure.
	 */
	private function set_image(int $product_id, string $image_url, bool $is_featured)
	{
		// Validate URL
		if (! filter_var($image_url, FILTER_VALIDATE_URL)) return false;

		// Check if image is already attached to avoid duplicates (basic check by filename)
		// For a more robust check, you might store the source URL in meta.
		$image_name = basename($image_url);

		// Requires WordPress media includes
		require_once(ABSPATH . 'wp-admin/includes/media.php');
		require_once(ABSPATH . 'wp-admin/includes/file.php');
		require_once(ABSPATH . 'wp-admin/includes/image.php');

		// Download the image
		$temp_file = download_url($image_url);

		if (is_wp_error($temp_file)) {
			return false;
		}

		$file = [
			'name' => $image_name,
			'type' => mime_content_type($temp_file),
			'tmp_name' => $temp_file,
			'error' => 0,
			'size' => filesize($temp_file),
		];

		// Upload to Media Library
		$sideload = wp_handle_sideload($file, ['test_form' => false]);

		if (! empty($sideload['error'])) {
			@unlink($temp_file);
			return false;
		}

		$attachment_id = wp_insert_attachment(
			[
				'guid' => $sideload['url'],
				'post_mime_type' => $sideload['type'],
				'post_title' => preg_replace('/\.[^.]+$/', '', $image_name),
				'post_content' => '',
				'post_status' => 'inherit',
			],
			$sideload['file'],
			$product_id
		);

		if (is_wp_error($attachment_id) || ! $attachment_id) {
			return false;
		}

		// Generate attachment metadata
		$attach_data = wp_generate_attachment_metadata($attachment_id, $sideload['file']);
		wp_update_attachment_metadata($attachment_id, $attach_data);

		if ($is_featured) {
			set_post_thumbnail($product_id, $attachment_id);
		}

		return $attachment_id;
	}
}

// Initialize the plugin
new Syntech_Product_Importer();
