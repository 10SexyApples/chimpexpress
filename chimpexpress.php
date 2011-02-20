<?php
/**
 * Plugin Name: ChimpExpress
 * Plugin URI: http://www.chimpexpress.com
 * Description: Wordpress MailChimp Integration - Create MailChimp campaigns from within Wordpress and include blog posts or import recent campaigns into Wordpress to create blog posts or landing pages. Requires PHP5.
 * Version: 1.0
 * Author: freakedout
 * Author URI: http://www.freakedout.de
 * License: GNU/GPL 2
 * Copyright (C) 2011  freakedout (www.freakedout.de)
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2, as 
 * published by the Free Software Foundation.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/> 
 * or write to the Free Software Foundation, Inc., 51 Franklin St, 
 * Fifth Floor, Boston, MA  02110-1301  USA
**/

// no direct access
defined( 'ABSPATH' ) or die( 'Restricted Access' );

defined( 'DS' ) or define('DS', DIRECTORY_SEPARATOR);

if( ! is_admin() ){
	return;
}

class chimpexpress
{
	private $_settings;
	private $_errors = array();
	private $_notices = array();
	static $instance = false;
	
	private $_optionsName = 'chimpexpress';
	private $_optionsGroup = 'chimpexpress-options';
	private $_url = "https://us1.api.mailchimp.com/1.2/";
	private $_listener_query_var = 'chimpexpressListener';
	private $_timeout = 30;
	
	private $MCAPI = false;
	
//	public $_api = false;
	
	private function __construct() {
		
		require_once( WP_PLUGIN_DIR . DS . 'chimpexpress' . DS . 'class-MCAPI.php' );
		if( ! $this->MCAPI ){
			$this->MCAPI = new chimpexpressMCAPI;
			if( ! isset( $_SESSION['MCping'] ) || ! $_SESSION['MCping'] ){
				$ping = $this->MCAPI->ping();
				$_SESSION['MCping'] = $ping;
				if($ping){
					$MCname = $this->MCAPI->getAccountDetails();
					$_SESSION['MCusername'] = $MCname['username'];
				}
			}
		}
		
		$this->_getSettings();
		
		// Get the datacenter from the API key
		$datacenter = substr( strrchr($this->_settings['apikey'], '-'), 1 );
		if ( empty( $datacenter ) ) {
			$datacenter = "us1";
		}
		// Put the datacenter and version into the url
		$this->_url = "https://{$datacenter}.api.mailchimp.com/{$this->_settings['version']}/";
		
		require_once( WP_PLUGIN_DIR . DS . 'chimpexpress' . DS . 'class-JG_Cache.php' );
		
		/**
		 * Add filters and actions
		 */
		add_filter( 'init', array( $this, 'chimpexpressLoadLanguage') );
		add_action( 'admin_init', array($this,'registerOptions') );
		add_action( 'admin_menu', array($this,'adminMenu') );
		add_action( 'template_redirect', array( $this, 'listener' ));
//		add_filter( 'query_vars', array( $this, 'addMailChimpListenerVar' ));
		register_activation_hook( __FILE__, array( $this, 'activatePlugin' ) );
		add_filter( 'pre_update_option_' . $this->_optionsName, array( $this, 'optionUpdate' ), null, 2 );
	//	add_action( 'admin_notices', array($this->MCAPI, 'showMessages') );
		
		// add css files
		wp_enqueue_style( 'chimpexpress', plugins_url( 'css' . DS . 'chimpexpress.css', __FILE__ ) );
		wp_enqueue_style( 'colorbox', plugins_url( 'css' . DS . 'colorbox.css', __FILE__ ) );
		// add js files
		wp_enqueue_script( 'chimpexpress', plugins_url( 'js' . DS . 'jquery.colorbox-min.js', __FILE__ ) );
		// compose ajax callbacks
		add_action('wp_ajax_compose_clear_cache', array($this,'compose_clear_cache_callback'));
		add_action('wp_ajax_compose_gotoStep', array($this,'compose_gotoStep_callback'));
		add_action('wp_ajax_compose_removeDraft', array($this,'compose_removeDraft_callback'));
		// import ajax callbacks
		add_action('wp_ajax_import', array($this,'import_callback'));
		
	//	add_filter('admin_head', array($this,'ShowTinyMCE'));
	}
	
	function ShowTinyMCE() {
		// conditions here
		wp_enqueue_script( 'common' );
		wp_enqueue_script( 'jquery-color' );
		wp_print_scripts('editor');
		if (function_exists('add_thickbox')) add_thickbox();
		wp_print_scripts('media-upload');
		if (function_exists('wp_tiny_mce')) wp_tiny_mce();
		wp_admin_css();
		wp_enqueue_script('utils');
		do_action("admin_print_styles-post-php");
		do_action('admin_print_styles');
	}
	
	public function optionUpdate( $newvalue, $oldvalue ) {
		if ( !empty( $_POST['get-apikey'] ) ) {
			unset( $_POST['get-apikey'] );

			// If the user set their username at the same time as they requested an API key or changes the username
			if ( empty($this->_settings['username']) || $oldvalue['username'] != $newvalue['username'] ) {
				$this->_settings['username'] = $newvalue['username'];
				$this->_updateSettings();
			}

			// If the user set their password at the same time as they requested an API key or changed the password
			if ( empty($this->_settings['password']) || $oldvalue['password'] != $newvalue['password'] ) {
				$this->_settings['password'] = $newvalue['password'];
				$this->_updateSettings();
			}
			$this->_getSettings();
			// Get API keys, if one doesn't exist, the login will create one
			$keys = $this->MCAPI->apikeys();
		//	var_dump($keys);die;
			
			// Set the API key
			if ( is_array($keys) && !empty( $keys ) && !is_wp_error($keys) ) {
				$newvalue['apikey'] = $keys[0]['apikey'];
				$this->MCAPI->_addNotice( __('API Key saved', 'chimpexpress').": {$newvalue['apikey']}");
			}
		} elseif ( !empty( $_POST['expire-apikey'] ) ) {
			unset( $_POST['expire-apikey'] );

			// If the user set their username at the same time as they requested to expire the API key
			if ( empty($this->_settings['username']) ) {
				$this->_settings['username'] = $newvalue['username'];
			}

			// If the user set their password at the same time as they requested to expire the API key
			if ( empty($this->_settings['password']) ) {
				$this->_settings['password'] = $newvalue['password'];
			}

			// Get API keys, if one doesn't exist, the login will create one
			$expired = $this->MCAPI->apikeyExpire( $this->_settings['username'], $this->_settings['password'] );

			// Empty the API key and add a notice
			if ( empty($expired['error']) ) {
				$newvalue['apikey'] = '';
				$this->MCAPI->_addNotice( __('API Key expired', 'chimpexpress').": {$oldvalue['apikey']}");
			}
		} 
		/*
		elseif ( !empty( $_POST['regenerate-security-key']) ) {
			unset( $_POST['expire-apikey'] );

			$newvalue['listener_security_key'] = $this->_generateSecurityKey();
			$this->MCAPI->_addNotice("New Security Key: {$newvalue['listener_security_key']}");
		}
		*/
		
		// clear cache if present
		$cacheDir = ABSPATH . 'wp-content' .DS. 'plugins' .DS. 'chimpexpress' .DS. 'cache' .DS;
		$cache = new JG_Cache( $cacheDir );
		$templates = $cache->get('templates');
		if ( $templates ){ 
			$this->compose_clear_cache_callback();
		}

		return $newvalue;
	}
	
	public function activatePlugin() {
		$this->_updateSettings();
	}
	
	public function getSetting( $settingName, $default = false ) {
		if ( empty( $this->_settings ) ) {
			$this->_getSettings();
		}
		if ( isset( $this->_settings[$settingName] ) ) {
			return $this->_settings[$settingName];
		} else {
			return $default;
		}
	}
	
	private function _getSettings() {
		if (empty($this->_settings)) {
			$this->_settings = get_option( $this->_optionsName );
		}
		if ( !is_array( $this->_settings ) ) {
			$this->_settings = array();
		}
		$defaults = array(
			'username'				=> '',
			'password'				=> '',
			'apikey'				=> '',
			'debugging'				=> 'off',
			'debugging_email'		=> '',
			'listener_security_key'	=> $this->_generateSecurityKey(),
			'version'				=> '1.3',
			'GAprofile'				=> ''
		);
		$this->_settings = wp_parse_args($this->_settings, $defaults);
	}
	
	private function _generateSecurityKey() {
		return sha1(time());
	}
	
	private function _updateSettings() {
		update_option( $this->_optionsName, $this->_settings );
	}
	
	public function registerOptions() {
		register_setting( $this->_optionsGroup, $this->_optionsName );
	}

	public function adminMenu() {
		
		add_menu_page(
			__('Dashboard', 'chimpexpress'),
			'ChimpExpress', 
			'manage_options',
			'ChimpExpressDashboard', 
			array($this, 'main'),
			plugins_url( 'images' . DS . 'logo_16.png', __FILE__ ),
			26
		);
		add_submenu_page( 
			'ChimpExpressDashboard',
			__('Import', 'chimpexpress'),
			__('Import', 'chimpexpress'), 
			'manage_options',
			'ChimpExpressImport', 
			array($this, 'import'),
			'',
			27
		);
		add_submenu_page( 
			'ChimpExpressDashboard',
			__('Compose', 'chimpexpress'),
			__('Compose', 'chimpexpress'), 
			'manage_options',
			'ChimpExpressCompose', 
			array($this, 'compose'),
			'',
			27
		);
		add_submenu_page( 
			'ChimpExpressDashboard',
			__('Landing Page Archive', 'chimpexpress'),
			__('Landing Pages', 'chimpexpress'), 
			'manage_options',
			'ChimpExpressArchive', 
			array($this, 'archive'),
			'',
			27
		);
		// invisible menus
		add_submenu_page( 
			'ChimpExpressArchive',
			__('Edit Landing Page', 'chimpexpress'),
			__('Edit Landing Page', 'chimpexpress'), 
			'manage_options',
			'ChimpExpressEditLandingPage', 
			array($this, 'editLP'),
			'',
			27
		);
		
		add_options_page(
			__('Settings', 'chimpexpress'),
			'ChimpExpress', 
			'manage_options', 
			'ChimpExpressConfig', 
			array($this, 'options')
		);
	}


	function compose_clear_cache_callback(){
		$dir = ABSPATH . 'wp-content' .DS. 'plugins' .DS. 'chimpexpress' .DS. 'cache';
		$objects = scandir( $dir );
		foreach ($objects as $object) {
			if ($object != "." && $object != ".." && $object != "index.html" ) {
				if (filetype($dir.DS.$object) == "dir") {
					$this->rrmdir($dir.DS.$object);
				} else {
					unlink($dir.DS.$object);
				}
			}
		}
		reset($objects);
		return;
	}
	
	function compose_gotoStep_callback() {
		include( WP_PLUGIN_DIR . DS . 'chimpexpress' . DS . 'compose.php' );
		die;
	}
	
	function compose_removeDraft_callback() {
		$cid = $_POST['cid'];
		$this->MCAPI->campaignDelete($cid);
		
		if ( is_dir( WP_PLUGIN_DIR . DS . 'chimpexpress' .DS. 'tmp' ) ){
			$this->rrmdir( WP_PLUGIN_DIR . DS . 'chimpexpress' .DS. 'tmp' );
		}
		die;
	}
	
	function import_callback() {
		
		global $wpdb, $current_user;
		$type = $_POST['type'];
		$cid  = $_POST['cid'];
		$subject  = html_entity_decode( $_POST['subject'] );
		$fileName = html_entity_decode( $_POST['fileName'] );
		// get next post/page id
		$table_status = $wpdb->get_results( $wpdb->prepare("SHOW TABLE STATUS LIKE '$wpdb->posts'") );
		$next_increment = $table_status[0]->Auto_increment;
		
		if($type=='post'){
			// create permalink
			$guid = get_option('home') . '/?p=' . $next_increment;
		//	var_dump($campaignContent['html']);die;
			if( $_POST['datatype'] == 'html' ){
				// get campaign contents
				$campaignContent = $this->MCAPI->campaignContent( $cid, false );
				// process html contents
				$html = $campaignContent['html'];
				$html = preg_replace( '!<head>(.*)</head>!i', '', $html );
				$html = str_replace( array('<html>','</html>','<body>','</body>'), '', $html );
				
				// remove MERGE tags
				// anchors containing a merge tag
				$html = preg_replace( '!<a(.*)(\*(\||%7C)(.*)(\||%7C)\*)(.*)</a>!', '', $html);
				// all other merge tags
				$html = preg_replace( '!\*(\||%7C)(.*)(\||%7C)\*!', '', $html);
			} else {
				// get campaign contents
				$campaignContent = $this->MCAPI->campaignContent( $cid, true );
				// process html contents
				$html = $campaignContent['text'];
				// convert links to html anchors
				$html = preg_replace( '!(http://(.*)(<|\s))!isU', '<a href="$1">$1</a>', $html);
				// remove MERGE tags
				$html = preg_replace( '!\*(\||%7C)(.*)(\||%7C)\*!', '', $html);
			}
			
			
			
			/*
			$campaignTemplateContent = $this->MCAPI->campaignTemplateContent( $cid );
			if($campaignTemplateContent){
				$html = $campaignTemplateContent['main'];
				// append sidecolumn content if exists
				if( isset($campaignTemplateContent['sidecolumn']) && $campaignTemplateContent['sidecolumn'] != '' ){
					$html .= '<br />'.$campaignTemplateContent['sidecolumn'];
				}
			} else {
				// clear errors (we dont need to be notified that this campaign doesn't use a template)
				$this->MCAPI->_emptyErrors();
				// campaign didn't use a template so we have to use the text version
				$html = $this->MCAPI->generateText( 'cid', $cid );
				// convert links to html anchors
				$html = preg_replace( '!(http://(.*)(<|\s))!isU', '<a href="$1">$1</a>', $html);
				
				// remove MERGE tags
				// sentences containing a merge tag
				$html = preg_replace( '!\.\s(.*)\*\|(.*)\|\*(.*)\.!sU', '.', $html);
				$html = preg_replace( '!\.\s(.*)\*%7C(.*)%7C\*(.*)\.!sU', '.', $html);
				
				$html = preg_replace( '!>(.*)\*\|(.*)\|\*(.*)\.!sU', '>', $html);
				$html = preg_replace( '!>(.*)\*%7C(.*)%7C\*(.*)\.!sU', '>', $html);
				// anchors containing a merge tag
				$html = preg_replace( '!<a(.*)\*\|(.*)\|\*(.*)(</a>)?!isU', '', $html);
				$html = preg_replace( '!<a(.*)(\*%7C)(.*)(%7C\*)(</a>)?!isU', '', $html);
				// all other merge tags
				$html = preg_replace( '!\*\|(.*)\|\*!isU', '', $html);
				$html = preg_replace( '!(\*%7C)(.*)(%7C\*)!isU', '', $html);
			}
			*/
			
			$now = date('Y-m-d H:i:s');
			$now_gmt = gmdate('Y-m-d H:i:s');
			
			$data = array(
				'post_author' => $current_user->ID,
				'post_date' => $now,
				'post_date_gmt' => $now_gmt,
				'post_content' => $html,
				'post_excerpt' => '',
				'post_status' => 'draft',
				'post_title' => $subject,
				'post_type' => $type,
				'post_name' => sanitize_title( $subject ),
				'post_modified' => $now,
				'post_modified_gmt' => $now_gmt,
				'guid' => $guid,
				'comment_count' => 0,
				'to_ping' => '',
				'pinged' => '',
				'post_content_filtered' => ''
			);
			$wpdb->insert( $wpdb->posts, $data );
			
			echo $next_increment;
			die;
			
		} else { // create landing page
			
			// create permalink
			$guid = get_option('home') . '/?page_id=' . $next_increment;
			// get campaign content
			$campaign = $this->MCAPI->campaignContent( $cid, false );
			$html = $campaign['html'];
			// set page title
			if( ! preg_match( '!<title>(.*)</title>!i', $html ) ){
				$html = str_replace( '</head>', "<title>".$fileName."</title>\n</head>", $html );
			} else {
				$html = preg_replace( '!<title>(.*)</title>!i', '<title>'.$fileName.'</title>', $html );
			}
			
			// insert google analytics
			if( $this->_settings['GAprofile'] ){
				$script = "\n<script type=\"text/javascript\">\n".
							"var _gaq = _gaq || [];\n".
							"_gaq.push(['_setAccount', '".$this->_settings['GAprofile']."']);\n".
							"_gaq.push(['_trackPageview']);\n".
							"(function() {\n".
							"var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;\n".
							"ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';\n".
							"var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);\n".
							"})();\n".
							"</script>";
				$html = str_replace( '</head>', $script."\n</head>", $html );
			}
			
			// remove MERGE tags
			// sentences containing a merge tag
		//	$html = preg_replace( '!\.\s(.*)\*(\||%7C)(.*)(\||%7C)\*(.*)\.!sU', '###', $html);
		//	$html = preg_replace( '!>(.*)\*(\||%7C)(.*)(\||%7C)\*(.*)\.!sU', '>', $html);
			// anchors containing a merge tag
			$html = preg_replace( '!<a(.*)(\*(\||%7C)(.*)(\||%7C)\*)(.*)</a>!', '', $html);
			// all other merge tags
			$html = preg_replace( '!\*(\||%7C)(.*)(\||%7C)\*!', '', $html);
			
			// create html file
			$archiveDirAbs = ABSPATH . 'archive/';
			$archiveDirRel = get_option('home') . '/archive/';
			$safeSubject = sanitize_title( $fileName );
		//	$this->rrmdir($archiveDirAbs);
			// create archive directory if it doesn't exist
			if ( ! is_dir( $archiveDirAbs ) ){
				mkdir( $archiveDirAbs );
			}
			// open and write landing page html file
			$f = @fopen( $archiveDirAbs . $safeSubject . '.html', 'w' );
			@fwrite( $f, $html );
			@fclose( $f );
			
			$fileName = $archiveDirRel . $safeSubject . '.html';
			echo $fileName;
			die;
		}
		
		echo $next_increment;
		die;
	}
	
	function rrmdir($dir) {
		if (is_dir($dir)) {
			$objects = scandir($dir);
			foreach ($objects as $object) {
				if ($object != "." && $object != "..") {
					if (filetype($dir."/".$object) == "dir") $this->rrmdir($dir."/".$object); else unlink($dir."/".$object);
				}
			}
			reset($objects);
			rmdir($dir);
		}
	} 

	public function main(){
		require_once( WP_PLUGIN_DIR . DS . 'chimpexpress' . DS . 'main.php' );
	}
	function compose(){
		echo '<div class="wrap">';
		$cacheDir = ABSPATH . 'wp-content' .DS. 'plugins' .DS. 'chimpexpress' .DS. 'cache' .DS;
		$cache = new JG_Cache( $cacheDir );
		$templates = $cache->get('templates');
		if ($templates === FALSE){
			echo '<div id="preloaderContainer"><div id="preloader">'.__('Retrieving templates and lists ...', 'chimpexpress').'</div></div>';
		}
		require_once( WP_PLUGIN_DIR . DS . 'chimpexpress' . DS . 'compose.php' );
		echo '</div>';
	}
	function import(){
		require_once( WP_PLUGIN_DIR . DS . 'chimpexpress' . DS . 'import.php' );
	}
	function archive(){
		require_once( WP_PLUGIN_DIR . DS . 'chimpexpress' . DS . 'archive.php' );
	}
	function editLP(){
		require_once( WP_PLUGIN_DIR . DS . 'chimpexpress' . DS . 'editLP.php' );
	}
	
	public function options() {
?>
		<style type="text/css">
			#wp_chimpexpress table tr th a {
				cursor:help;
			}
			.large-text{width:99%;}
			.regular-text{width:25em;}	
		</style>
		<div class="wrap">
			<div id="dashboardButton">
			<a class="button" id="next" href="admin.php?page=ChimpExpressDashboard" title="ChimpExpress <?php _e('Dashboard', 'chimpexpress'); ?> &raquo;">ChimpExpress <?php _e('Dashboard', 'chimpexpress'); ?> &raquo;</a>
			</div>
			<h2 class="componentHeading">ChimpExpress <?php _e('Settings', 'chimpexpress') ?></h2>
			<?php $this->MCAPI->showMessages(); ?>
			<form action="options.php" method="post" id="wp_chimpexpress">
				<?php settings_fields( $this->_optionsGroup ); ?>
				<table class="form-table">
					<?php /*
					<tr valign="top">
						<th scope="row">
							<label for="<?php echo $this->_optionsName; ?>_username">
								<?php _e('MailChimp Username', 'chimpexpress'); ?>
							</label>
						</th>
						<td>
							<input type="text" name="<?php echo $this->_optionsName; ?>[username]" value="<?php echo esc_attr($this->_settings['username']); ?>" id="<?php echo $this->_optionsName; ?>_username" class="regular-text code" />
							<a class="chimpexpress_help" title="<?php _e('Click for Help!', 'chimpexpress'); ?>" href="#" onclick="jQuery('#mc_username').toggle(); return false;">
								<?php _e('[?]', 'chimpexpress'); ?></a>
							<div style="display:inline-block;">
							<ol id="mc_username" style="display:none; list-style-type:decimal;">
								<li>
									<?php echo sprintf(__('You need a MailChimp account. If you do not have one, <a href="%s" target="_blank">sign up for free</a>', 'chimpexpress'), 'http://www.mailchimp.com/signup/?pid=worpmailer&source=website'); ?>
								</li>
							</ol>
							</div>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="<?php echo $this->_optionsName; ?>_password">
								<?php _e('MailChimp Password', 'chimpexpress') ?>
							</label>
						</th>
						<td>
							<input type="password" name="<?php echo $this->_optionsName; ?>[password]" value="<?php echo esc_attr($this->_settings['password']); ?>" id="<?php echo $this->_optionsName; ?>_password" class="regular-text code" />
							<a class="chimpexpress_help" title="<?php _e('Click for Help!', 'chimpexpress'); ?>" href="#" onclick="jQuery('#mc_password').toggle(); return false;">
								<?php _e('[?]', 'chimpexpress'); ?></a>
							<div style="display:inline-block;">
							<ol id="mc_password" style="display:none; list-style-type:decimal;">
								<li>
									<?php echo sprintf(__('You need a MailChimp account. If you do not have one, <a href="%s" target="_blank">sign up for free</a>', 'chimpexpress'), 'http://www.mailchimp.com/signup/?pid=joomailer&source=chimpexpress'); ?>
								</li>
							</ol>
							</div>
						</td>
					</tr>
					*/ ?>
					<tr valign="top">
						<th scope="row">
							<label for="<?php echo $this->_optionsName; ?>_apikey">
								<?php _e('MailChimp API Key', 'chimpexpress') ?>
							</label>
						</th>
						<td>
							<input type="text" name="<?php echo $this->_optionsName; ?>[apikey]" style="text-align:center;width:270px;" maxlength="36" value="<?php echo esc_attr($this->_settings['apikey']); ?>" id="<?php echo $this->_optionsName; ?>_apikey" class="regular-text code" />
							<?php /* if ( empty($this->_settings['apikey']) ) {
							?>
							<input type="submit" name="get-apikey" value="<?php _e('Get API Key', 'chimpexpress'); ?>" />
							<?php
							} else {
							?>
							<input type="submit" name="expire-apikey" value="<?php _e('Expire API Key', 'chimpexpress'); ?>" />
							<?php
							}
							*/
							?>
							<script type="text/javascript">
							jQuery(document).ready(function($) {
								if ( jQuery('#<?php echo $this->_optionsName; ?>_apikey').val() == '' ){
									jQuery('#mc_apikey').toggle();
								}
							});
							</script>
							<a class="chimpexpress_help" title="<?php _e('Click for Help!', 'chimpexpress'); ?>" href="#" onclick="jQuery('#mc_apikey').toggle(); return false;">
								<?php _e('[?]', 'chimpexpress'); ?></a>
							<div>
							<ol id="mc_apikey" style="display:none; list-style-type:decimal; margin-top: 1em;">
								<li>
									<?php echo sprintf(__('You need a MailChimp account. If you do not have one, <a href="%s" target="_blank">sign up for free</a>', 'chimpexpress'), 'http://www.mailchimp.com/signup/?pid=worpmailer&source=website'); ?>
								</li>
								<li>
									<?php echo sprintf(__('<a href="%s" target="_blank">Grab your API Key</a>', 'chimpexpress'), 'http://admin.mailchimp.com/account/api-key-popup'); ?>
								</li>
							</ol>
							</div>
						</td>
					</tr>
					<?php /*
					<tr valign="top">
						<th scope="row">
							<label for="<?php echo $this->_optionsName; ?>_version">
								<?php _e('MailChimp API version', 'chimpexpress') ?>
								<a title="<?php _e('Click for Help!', 'chimpexpress'); ?>" href="#" onclick="jQuery('#mc_version').toggle(); return false;">
									<?php _e('[?]', 'chimpexpress'); ?>
								</a>
							</label>
						</th>
						<td>
							<input type="text" name="<?php echo $this->_optionsName; ?>[version]" value="<?php echo esc_attr($this->_settings['version']); ?>" id="<?php echo $this->_optionsName; ?>_version" class="small-text" />
							<small id="mc_version" style="display:none;">
								This is the default version to use if one isn't
								specified.
							</small>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<?php _e('Debugging Mode', 'chimpexpress') ?>
							<a title="<?php _e('Click for Help!', 'chimpexpress'); ?>" href="#" onclick="jQuery('#mc_debugging').toggle(); return false;">
								<?php _e('[?]', 'chimpexpress'); ?>
							</a>
						</th>
						<td>
							<input type="radio" name="<?php echo $this->_optionsName; ?>[debugging]" value="on" id="<?php echo $this->_optionsName; ?>_debugging-on"<?php checked('on', $this->_settings['debugging']); ?> />
							<label for="<?php echo $this->_optionsName; ?>_debugging-on"><?php _e('On', 'chimpexpress'); ?></label><br />
							<input type="radio" name="<?php echo $this->_optionsName; ?>[debugging]" value="webhooks" id="<?php echo $this->_optionsName; ?>_debugging-webhooks"<?php checked('webhooks', $this->_settings['debugging']); ?> />
							<label for="<?php echo $this->_optionsName; ?>_debugging-webhooks"><?php _e('Partial - Only WebHook Messages', 'chimpexpress'); ?></label><br />
							<input type="radio" name="<?php echo $this->_optionsName; ?>[debugging]" value="off" id="<?php echo $this->_optionsName; ?>_debugging-off"<?php checked('off', $this->_settings['debugging']); ?> />
							<label for="<?php echo $this->_optionsName; ?>_debugging-off"><?php _e('Off', 'chimpexpress'); ?></label><br />
							<small id="mc_debugging" style="display:none;">
								<?php _e('If this is on, debugging messages will be sent to the E-Mail addresses set below.', 'chimpexpress'); ?>
							</small>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="<?php echo $this->_optionsName; ?>_debugging_email">
								<?php _e('Debugging E-Mail', 'chimpexpress') ?>
								<a title="<?php _e('Click for Help!', 'chimpexpress'); ?>" href="#" onclick="jQuery('#mc_debugging_email').toggle(); return false;">
									<?php _e('[?]', 'chimpexpress'); ?>
								</a>
							</label>
						</th>
						<td>
							<input type="text" name="<?php echo $this->_optionsName; ?>[debugging_email]" value="<?php echo esc_attr($this->_settings['debugging_email']); ?>" id="<?php echo $this->_optionsName; ?>_debugging_email" class="regular-text" />
							<small id="mc_debugging_email" style="display:none;">
								<?php _e('This is a comma separated list of E-Mail addresses that will receive the debug messages.', 'chimpexpress'); ?>
							</small>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="<?php echo $this->_optionsName; ?>_listener_security_key">
								<?php _e('MailChimp WebHook Listener Security Key', 'chimpexpress'); ?>
								<a title="<?php _e('Click for Help!', 'chimpexpress'); ?>" href="#" onclick="jQuery('#mc_listener_security_key').toggle(); return false;">
									<?php _e('[?]', 'chimpexpress'); ?>
								</a>
							</label>
						</th>
						<td>
							<input type="text" name="<?php echo $this->_optionsName; ?>[listener_security_key]" value="<?php echo esc_attr($this->_settings['listener_security_key']); ?>" id="<?php echo $this->_optionsName; ?>_listener_security_key" class="regular-text code" />
							<input type="submit" name="regenerate-security-key" value="<?php _e('Regenerate Security Key', 'chimpexpress'); ?>" />
							<div id="mc_listener_security_key" style="display:none; list-style-type:decimal;">
								<p><?php echo _e('This is used to make the listener a little more secure. Usually the key that was randomly generated for you is fine, but you can make this whatever you want.', 'chimpexpress'); ?></p>
								<p class="error"><?php echo _e('Warning: Changing this will change your WebHook Listener URL below and you will need to update it in your MailChimp account!', 'chimpexpress'); ?></p>
							</div>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<?php _e('MailChimp WebHook Listener URL', 'chimpexpress') ?>
							<a title="<?php _e('Click for Help!', 'chimpexpress'); ?>" href="#" onclick="jQuery('#mc_listener_url').toggle(); return false;">
								<?php _e('[?]', 'chimpexpress'); ?>
							</a>
						</th>
						<td>
							<?php echo $this->_getListenerUrl(); ?>
							<div id="mc_listener_url" style="display:none;">
								<p><?php _e('To set this in your MailChimp account:', 'chimpexpress'); ?></p>
								<ol style="list-style-type:decimal;">
									<li>
										<?php echo sprintf(__('<a href="%s">Log into your MailChimp account</a>', 'chimpexpress'), 'https://admin.mailchimp.com/'); ?>
									</li>
									<li>
										<?php _e('Navigate to your <strong>Lists</strong>', 'chimpexpress'); ?>
									</li>
									<li>
										<?php _e("Click the <strong>View Lists</strong> button on the list you want to configure.", 'chimpexpress'); ?>
									</li>
									<li>
										<?php _e('Click the <strong>List Tools</strong> menu option at the top.', 'chimpexpress'); ?>
									</li>
									<li>
										<?php _e('Click the <strong>WebHooks</strong> link.', 'chimpexpress'); ?>
									</li>
									<li>
										<?php echo sprintf(__("Configuration should be pretty straight forward. Copy/Paste the URL shown above into the callback URL field, then select the events and event sources (see the <a href='%s'>MailChimp documentation for more information on events and event sources) you'd like to have sent to you.", 'chimpexpress'), 'http://www.mailchimp.com/api/webhooks/'); ?>
									</li>
									<li>
										<?php _e("Click save and you're done!", 'chimpexpress'); ?>
									</li>
								</ol>
							</div>
						</td>
					</tr>
					*/ ?>
					
					<?php if ( $this->MCAPI->ping() ){ ?>
					<tr valign="top">
						<th scope="row">
							<label><?php _e('Connected as', 'chimpexpress') ?></label>
						</th>
						<td>
							<span style="font-size:12px;"><?php echo $_SESSION['MCusername'];?></span>
						</td>
					</tr>
					<?php } ?>
					<tr valign="top">
						<th scope="row">
							<label><?php _e('Current MailChimp Status', 'chimpexpress') ?></label>
						</th>
						<td>
							<span id="mc_ping">
								<?php echo ($this->MCAPI->ping()) ? '<span style="color:green;">'.$this->MCAPI->ping().'</span>' : '<span style="color:red;">'.__('Not connected', 'chimpexpress').'</span>'; ?>
							</span>
							<a class="chimpexpress_help" title="<?php _e('Click for Help!', 'chimpexpress'); ?>" href="#" onclick="jQuery('#mc_status').toggle(); return false;">
								<?php _e('[?]', 'chimpexpress'); ?></a>
							<div style="display:inline-block;">
								<span id="mc_status" style="display:none;"><?php _e("The current status of your server's connection to MailChimp", 'chimpexpress'); ?></span>
							</div>
						</td>
					</tr>
					
					<tr valign="top">
						<th scope="row">
							<label><?php _e('Google Analytics Profile ID', 'chimpexpress') ?></label>
						</th>
						<td>
							<input type="text" name="<?php echo $this->_optionsName; ?>[GAprofile]" style="text-align:center;width:270px;" maxlength="36" value="<?php echo esc_attr($this->_settings['GAprofile']); ?>" id="<?php echo $this->_optionsName; ?>_GAprofile" class="regular-text code" />
							<a class="chimpexpress_help" title="<?php _e('Click for Help!', 'chimpexpress'); ?>" href="#" onclick="jQuery('#ga_info').toggle(); return false;">
								<?php _e('[?]', 'chimpexpress'); ?></a>
							<div style="display:inline-block;">
								<span id="ga_info" style="display:none;"><?php _e("Enter your Google Analytics Profile ID if you want to be able to track your landing pages in Analytics. The ID should look like: UA-1234567-8", 'chimpexpress'); ?></span>
							</div>
						</td>
					</tr>
					
					<tr valign="top">
						<th scope="row"></th>
						<td>
							<input type="submit" name="Submit" class="button" value="<?php _e('Update Settings &raquo;', 'chimpexpress'); ?>" />
						</td>
					</tr>
				</table>
			</form>
		</div>
<?php
	}
	
	
	
	
	private function _getListenerUrl() {
		return get_bloginfo('url').'/?'.$this->_listener_query_var.'='.urlencode($this->_settings['listener_security_key']);
	}
	
	public function setTimeout($seconds){
		$this->_timeout = absint($seconds);
		return true;
	}
	
	public function getTimeout(){
		return $this->timeout;
	}
	
	
	public static function getInstance() {
		if ( !self::$instance ) {
			self::$instance = new self;
		}
		return self::$instance;
	}
	
	// load language files
	function chimpexpressLoadLanguage() {
		if (function_exists('load_plugin_textdomain')) {
			$currentlocale = get_locale();
			if(!empty($currentlocale)) {
				$moFile = dirname(__FILE__) . DS . "languages" . DS . $currentlocale . "-" . $this->_optionsName . ".mo";
				if(@file_exists($moFile) && is_readable($moFile)) {
					load_textdomain( $this->_optionsName, $moFile);
				}
			}
		}
	}
	
}

// Instantiate our class
$chimpexpress = chimpexpress::getInstance();


