<?php
/*
Plugin Name: WP SMS
Plugin URI: http://wp-sms.ir/
Description: A complete wordpress plugin to send sms with a high capability.
Version: 4.0.1
Author: Mostafa Soufi
Author URI: http://mostafa-soufi.ir/
Text Domain: wp-sms
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Plugin defines
define('WP_SMS_VERSION', '4.0.1');
define('WP_SMS_DIR_PLUGIN', plugin_dir_url(__FILE__));
define('WP_SMS_ADMIN_URL', get_admin_url());
define('WP_SMS_SITE', 'http://wp-sms.ir');
define('WP_SMS_MOBILE_REGEX', '/^[\+|\(|\)|\d|\- ]*$/');
define('WP_SMS_CURRENT_DATE', date('Y-m-d H:i:s' ,current_time('timestamp', 0)));

// Get options
$wpsms_option = get_option('wpsms_settings');
include_once dirname( __FILE__ ) . '/includes/class-wp-sms.php';

// SMS Gateway plugin
if( isset($wpsms_option['gateway_name']) ) {
	if(is_file(dirname( __FILE__ ) . '/includes/gateways/'.$wpsms_option['gateway_name'].'.class.php')) {
		include_once dirname( __FILE__ ) . '/includes/gateways/'.$wpsms_option['gateway_name'].'.class.php';
	} else {
		include_once( WP_PLUGIN_DIR . '/wp-sms-pro/includes/gateways/'.$wpsms_option['gateway_name'].'.class.php' );
	}

	if($wpsms_option['gateway_name'] == 'default') {
		$sms = new Default_Gateway();
	} else {
		$sms = new $wpsms_option['gateway_name'];
	}
	
	$sms->username = $wpsms_option['gateway_username'];
	$sms->password = $wpsms_option['gateway_password'];
	
	if($sms->has_key && $wpsms_option['gateway_key']) {
		$sms->has_key = $wpsms_option['gateway_key'];
	}
	
	// Added help to gateway if have it.
	if($sms->help) {
		function wps_gateway_help() {
			global $sms;
			echo '<p class="description">'.$sms->help.'</p>';
		}
		add_action('wp_sms_after_gateway', 'wps_gateway_help');
	}
	
	if($sms->unitrial == true) {
		$sms->unit = __('Credit', 'wp-sms');
	} else {
		$sms->unit = __('SMS', 'wp-sms');
	}
	
	$sms->from = $wpsms_option['gateway_sender_id'];
} else {
	include_once dirname( __FILE__ ) . '/includes/gateways/default.class.php';
	$sms = new Default_Gateway;
}

// Create object of plugin
$WP_SMS_Plugin = new WP_SMS_Plugin;

// Run installer
register_activation_hook( __FILE__, array( 'WP_SMS_Plugin', 'install' ) );

// WP SMS Plugin Class
class WP_SMS_Plugin {
	/**
	 * Wordpress Admin url
	 *
	 * @var string
	 */
	public $admin_url = WP_SMS_ADMIN_URL;
	
	/**
	 * WP SMS gateway object
	 *
	 * @var string
	 */
	public $sms;
	
	/**
	 * WP SMS subscribe object
	 *
	 * @var string
	 */
	public $subscribe;

	/**
	 * Wordpress Database
	 *
	 * @var string
	 */
	protected $db;
	
	/**
	 * Wordpress Table prefix
	 *
	 * @var string
	 */
	protected $tb_prefix;

	/**
	 * Options
	 *
	 * @var string
	 */
	protected $options;
	
	/**
	 * Constructors plugin
	 *
	 * @param  Not param
	 */
	public function __construct() {
		global $sms, $wpdb, $table_prefix, $wpsms_option;
		
		$this->sms = $sms;
		$this->db = $wpdb;
		$this->tb_prefix = $table_prefix;
		$this->options = $wpsms_option;

		// Load text domain
		add_action('init', array(&$this, 'load_textdomain'));
		
		__('WP SMS', 'wp-sms');
		__('A complete wordpress plugin to send sms with a high capability.', 'wp-sms');
		
		$this->includes();
		$this->init();
		
		$this->subscribe = new WP_SMS_Subscriptions();
		
		add_action('admin_enqueue_scripts', array(&$this, 'admin_assets'));
		add_action('wp_enqueue_scripts', array(&$this, 'front_assets'));
		
		add_action('admin_bar_menu', array(&$this, 'adminbar'));
		add_action('dashboard_glance_items', array($this, 'dashboard_glance'));
		add_action('admin_menu', array(&$this, 'admin_menu'));
		add_action('widgets_init', array(&$this, 'register_widget'));

		add_filter('wp_sms_to', array(&$this, 'modify_bulk_send'));
	}

	/**
	 * Load plugin textdomain.
	 *
	 * @since 1.0.0
	 */
	public function load_textdomain() {
		load_plugin_textdomain('wp-sms', false, dirname( plugin_basename( __FILE__ ) ) . '/languages');
	}
	
	/**
	 * Creating plugin tables
	 *
	 * @param  Not param
	 */
	static function install() {
		global $wp_sms_db_version;
		
		include_once dirname( __FILE__ ) . '/install.php';
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		
		dbDelta($create_sms_subscribes);
		dbDelta($create_sms_subscribes_group);
		dbDelta($create_sms_send);
		
		add_option('wp_sms_db_version', WP_SMS_VERSION);
		
		// Delete notification new wp_version option
		delete_option('wp_notification_new_wp_version');
	}
	
	/**
	 * Adding new capability in the plugin
	 *
	 * @param  Not param
	 */
	public function add_cap() {
		// get administrator role
		$role = get_role( 'administrator' );
		
		$role->add_cap( 'wpsms_sendsms' );
		$role->add_cap( 'wpsms_outbox' );
		$role->add_cap( 'wpsms_subscribers' );
		$role->add_cap( 'wpsms_subscribe_groups' );
		$role->add_cap( 'wpsms_setting' );
	}
	
	/**
	 * Includes plugin files
	 *
	 * @param  Not param
	 */
	public function includes() {
		$files = array(
			'version',
			'includes/functions',
			'includes/class-wp-sms-gateway',
			'includes/class-wp-sms-settings',
			'includes/class-wp-sms-features',
			'includes/class-wp-sms-notifications',
			'includes/class-wp-sms-integrations',
			'includes/class-wp-sms-newsletter',
			'includes/class-wp-sms-subscribers',
			'includes/class-wp-sms-widget',
		);
		
		foreach($files as $file) {
			include_once dirname( __FILE__ ) . '/' . $file . '.php';
		}
	}

	/**
	 * Initial plugin
	 *
	 * @param  Not param
	 */
	private function init() {
		if(isset($_GET['action'])) {
			if($_GET['action'] == 'wpsms-hide-newsletter') {
				update_option('wpsms_hide_newsletter', true);
			}
		}
		
		if(!get_option('wpsms_hide_newsletter')) {
			add_action('wp_sms_settings_page', array(&$this, 'admin_newsletter'));
		}
		
		// Check exists require function
		if( !function_exists('wp_get_current_user') ) {
			include(ABSPATH . "wp-includes/pluggable.php");
		}
		
		// Add plugin caps to admin role
		if( is_admin() and is_super_admin() ) {
			$this->add_cap();
		}
	}

	/**
	 * Include admin assets
	 *
	 * @param  Not param
	 */
	public function admin_assets() {
		wp_register_style('wpsms-admin-css', plugin_dir_url(__FILE__) . 'assets/css/admin.css', true, '1.1');
		wp_enqueue_style('wpsms-admin-css');
		
		wp_enqueue_style('wpsms-chosen-css', plugin_dir_url(__FILE__) . 'assets/css/chosen.min.css', true, '1.2.0');
		wp_enqueue_script('wpsms-chosen-js', plugin_dir_url(__FILE__) . 'assets/js/chosen.jquery.min.js', true, '1.2.0');
		wp_enqueue_script('wpsms-admin-js', plugin_dir_url(__FILE__) . 'assets/js/admin.js', true, '1.2.0');
	}
	
	/**
	 * Include front table
	 *
	 * @param  Not param
	 */
	public function front_assets() {
		wp_register_style('wpsms-subscribe', plugin_dir_url(__FILE__) . 'assets/css/subscribe.css', true, '1.1');
		wp_enqueue_style('wpsms-subscribe');
	}

	/**
	 * Admin bar plugin
	 *
	 * @param  Not param
	 */
	public function adminbar() {
		global $wp_admin_bar, $wpsms_option;
		
		if(is_super_admin() && is_admin_bar_showing()) {
			if(get_option('wp_last_credit') && isset($wpsms_option['account_credit_in_menu'])) {
				$wp_admin_bar->add_menu(array(
					'id'		=>	'wp-credit-sms',
					'title'		=>	'<span class="ab-icon"></span>'.get_option('wp_last_credit'),
					'href'		=>	$this->admin_url.'/admin.php?page=wp-sms-settings',
				));
			}
			
			$wp_admin_bar->add_menu(array(
				'id'		=>	'wp-send-sms',
				'parent'	=>	'new-content',
				'title'		=>	__('SMS', 'wp-sms'),
				'href'		=>	$this->admin_url.'/admin.php?page=wp-sms'
			));
		}
	}
	
	/**
	 * Dashboard glance plugin
	 *
	 * @param  Not param
	 */
	public function dashboard_glance() {
		$subscribe = $this->db->get_var("SELECT COUNT(*) FROM {$this->tb_prefix}sms_subscribes");
		echo "<li class='wpsms-subscribe-count'><a href='".$this->admin_url."admin.php?page=wp-sms-subscribers'>".sprintf(__('%s Subscriber', 'wp-sms'), $subscribe)."</a></li>";
		echo "<li class='wpsms-credit-count'><a href='".$this->admin_url."admin.php?page=wp-sms-settings&tab=web-service'>".sprintf(__('%s SMS Credit', 'wp-sms'), get_option('wp_last_credit'))."</a></li>";
	}
	
	/**
	 * Administrator admin_menu
	 *
	 * @param  Not param
	 */
	public function admin_menu() {
		add_menu_page(__('Wordpress SMS', 'wp-sms'), __('Wordpress SMS', 'wp-sms'), 'wpsms_sendsms', 'wp-sms', array(&$this, 'send_page'), 'dashicons-email-alt');
		add_submenu_page('wp-sms', __('Send SMS', 'wp-sms'), __('Send SMS', 'wp-sms'), 'wpsms_sendsms', 'wp-sms', array(&$this, 'send_page'));
		add_submenu_page('wp-sms', __('Outbox', 'wp-sms'), __('Outbox', 'wp-sms'), 'wpsms_outbox', 'wp-sms-outbox', array(&$this, 'outbox_page'));
		add_submenu_page('wp-sms', __('Subscribers', 'wp-sms'), __('Subscribers', 'wp-sms'), 'wpsms_subscribers', 'wp-sms-subscribers', array(&$this, 'subscribe_page'));
		add_submenu_page('wp-sms', __('Subscribers Group', 'wp-sms'), __('Subscribers Group', 'wp-sms'), 'wpsms_subscribe_groups', 'wp-sms-subscribers-group', array(&$this, 'groups_page'));
	}

	/**
	 * Register widget
	 */
	public function register_widget() {
		register_widget( 'WPSMS_Widget' );
	}

	/**
	 * Modify destination number
	 * @param  array $to
	 * @return array/string
	 */
	public function modify_bulk_send($to) {
		if(!$this->sms->bulk_send) {
			return array($to[0]);
		}

		return $to;
	}

	/**
	 * Sending sms admin page
	 *
	 * @param  Not param
	 */
	public function send_page() {
		global $wpsms_option;

		wp_enqueue_script('functions', plugin_dir_url(__FILE__) . 'assets/js/functions.js', true, '1.0');
		
		$get_group_result = $this->db->get_results("SELECT * FROM `{$this->tb_prefix}sms_subscribes_group`");
		$get_users_mobile = $this->db->get_col("SELECT `meta_value` FROM `{$this->tb_prefix}usermeta` WHERE `meta_key` = 'mobile'");
		
		if($wpsms_option['gateway_name'] && !$this->sms->GetCredit()) {
			$get_bloginfo_url = WP_SMS_ADMIN_URL . "admin.php?page=wp-sms-settings&tab=web-service";
			echo '<br><div class="update-nag">'.sprintf(__('Your credit for send sms is low!', 'wp-sms'), $get_bloginfo_url).'</div>';
			return;
		} else if(!$wpsms_option['gateway_name']) {
			return;
		}
		
		if(isset($_POST['SendSMS'])) {
			if($_POST['wp_get_message']) {
				if($_POST['wp_send_to'] == "wp_subscribe_username") {
					if( $_POST['wpsms_group_name'] == 'all' ) {
						$this->sms->to = $this->db->get_col("SELECT mobile FROM {$this->tb_prefix}sms_subscribes WHERE `status` = '1'");
					} else {
						$this->sms->to = $this->db->get_col("SELECT mobile FROM {$this->tb_prefix}sms_subscribes WHERE `status` = '1' AND `group_ID` = '".$_POST['wpsms_group_name']."'");
					}
				} else if($_POST['wp_send_to'] == "wp_users") {
					$this->sms->to = $get_users_mobile;
				} else if($_POST['wp_send_to'] == "wp_tellephone") {
					$this->sms->to = explode(",", $_POST['wp_get_number']);
				}
				
				$this->sms->msg = $_POST['wp_get_message'];

				if( isset($_POST['wp_flash']) ) {
					$this->sms->isflash = true;
				} else {
					$this->sms->isflash = false;
				}

				// Send sms
				$response = $this->sms->SendSMS();

				if( is_wp_error($response) )  {
					if( is_array($response->get_error_message()) ) {
						$response = print_r($response->get_error_message(), 1);
					} else {
						$response = $response->get_error_message();
					}

					echo "<div class='error'><p>" . sprintf(__('SMS was not delivered! results received: %s', 'wp-sms'), $response) . "</p></div>";
				} else {
					echo "<div class='updated'><p>" . __('SMS was sent with success', 'wp-sms') . "</p></div>";
					update_option('wp_last_credit', $this->sms->GetCredit());
				}
			} else {
				echo "<div class='error'><p>" . __('Please enter a message', 'wp-sms') . "</p></div>";
			}
		}
		
		include_once dirname( __FILE__ ) . "/includes/templates/send/send-sms.php";
	}
	
	/**
	 * Outbox sms admin page
	 *
	 * @param  Not param
	 */
	public function outbox_page() {
		include_once dirname( __FILE__ ) . '/includes/class-wp-sms-outbox.php';
		
		//Create an instance of our package class...
		$list_table = new WP_SMS_Outbox_List_Table();
		
		//Fetch, prepare, sort, and filter our data...
		$list_table->prepare_items();
		
		include_once dirname( __FILE__ ) . "/includes/templates/outbox/outbox.php";
	}
	
	/**
	 * Subscribe admin page
	 *
	 * @param  Not param
	 */
	public function subscribe_page() {
		
		if(isset($_GET['action'])) {
			// Add subscriber page
			if($_GET['action'] == 'add') {
				include_once dirname( __FILE__ ) . "/includes/templates/subscribe/add-subscriber.php";
				
				if(isset($_POST['wp_add_subscribe'])) {
					$result = $this->subscribe->add_subscriber($_POST['wp_subscribe_name'], $_POST['wp_subscribe_mobile'], $_POST['wpsms_group_name']);
					echo $this->notice_result($result['result'], $result['message']);
				}
				
				return;
			}
			
			// Edit subscriber page
			if($_GET['action'] == 'edit') {
				if(isset($_POST['wp_update_subscribe'])) {
					$result = $this->subscribe->update_subscriber($_GET['ID'], $_POST['wp_subscribe_name'], $_POST['wp_subscribe_mobile'], $_POST['wpsms_group_name'], $_POST['wpsms_subscribe_status']);
					echo $this->notice_result($result['result'], $result['message']);
				}
				
				$get_subscribe = $this->subscribe->get_subscriber($_GET['ID']);
				include_once dirname( __FILE__ ) . "/includes/templates/subscribe/edit-subscriber.php";
				
				return;
			}
			
			// Import subscriber page
			if($_GET['action'] == 'import') {
				include_once dirname( __FILE__ ) . "/import.php";
				include_once dirname( __FILE__ ) . "/includes/templates/subscribe/import.php";
				
				return;
			}
			
			// Export subscriber page
			if($_GET['action'] == 'export') {
				include_once dirname( __FILE__ ) . "/includes/templates/subscribe/export.php";
				
				return;
			}
		}
		
		include_once dirname( __FILE__ ) . '/includes/class-wp-sms-subscribers-table.php';
		
		//Create an instance of our package class...
		$list_table = new WP_SMS_Subscribers_List_Table();
		
		//Fetch, prepare, sort, and filter our data...
		$list_table->prepare_items();
		
		include_once dirname( __FILE__ ) . "/includes/templates/subscribe/subscribes.php";
	}
	
	/**
	 * Subscribe groups admin page
	 *
	 * @param  Not param
	 */
	public function groups_page() {
		
		if(isset($_GET['action'])) {
			// Add group page
			if($_GET['action'] == 'add') {
				include_once dirname( __FILE__ ) . "/includes/templates/subscribe/add-group.php";
				if(isset($_POST['wp_add_group'])) {
					$result = $this->subscribe->add_group($_POST['wp_group_name']);
					echo $this->notice_result($result['result'], $result['message']);
				}
				
				return;
			}
			
			// Manage group page
			if($_GET['action'] == 'edit') {
				if(isset($_POST['wp_update_group'])) {
					$result = $this->subscribe->update_group($_GET['ID'], $_POST['wp_group_name']);
					echo $this->notice_result($result['result'], $result['message']);
				}
				
				$get_group = $this->subscribe->get_group($_GET['ID']);
				include_once dirname( __FILE__ ) . "/includes/templates/subscribe/edit-group.php";
				
				return;
			}
		}
		
		include_once dirname( __FILE__ ) . '/includes/class-wp-sms-groups-table.php';
		
		//Create an instance of our package class...
		$list_table = new WP_SMS_Subscribers_Groups_List_Table();
		
		//Fetch, prepare, sort, and filter our data...
		$list_table->prepare_items();
		
		include_once dirname( __FILE__ ) . "/includes/templates/subscribe/groups.php";
	}

	/**
	 * Show message notice in admin
	 *
	 * @param  Not param
	 */
	public function notice_result($result, $message) {
		if(empty($result))
			return;
		
		if($result == 'error')
			return '<div class="updated settings-error notice error is-dismissible"><p><strong>'.$message.'</strong></p><button class="notice-dismiss" type="button"><span class="screen-reader-text">'.__('Close', 'wp-sms').'</span></button></div>';
		
		if($result == 'update')
			return '<div class="updated settings-update notice is-dismissible"><p><strong>'.$message.'</strong></p><button class="notice-dismiss" type="button"><span class="screen-reader-text">'.__('Close', 'wp-sms').'</span></button></div>';
	}

	/**
	 * Admin newsletter
	 *
	 * @param  Not param
	 */
	public function admin_newsletter() {
		include_once dirname( __FILE__ ) . '/includes/templates/wp-sms-admin-newsletter.php';
	}
	
	/**
	 * Shortcodes plugin
	 *
	 * @param  Not param
	 */
	public function shortcode( $atts, $content = null ) {
		
	}
}