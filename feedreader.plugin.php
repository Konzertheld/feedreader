<?php

/**
 * FeedReader allows you to use your blog to read your feeds. Your blogflow will be turned into a flow of feed entries. You can use content types to store your content appropriate. Also, feeds can be grouped by type, tag, author or your personal choice.
  * This plugin contains large code segments from feedlist by Owen Winkler and Chris Meller. Thanks for creating code to access atom feeds.
 */

class FeedReader extends Plugin
{
	/**
	 * Plugin init action, executed when plugins are initialized.
	 */ 
	public function action_init()
	{
		// Register block template
		$this->add_template( 'block.readernav', dirname(__FILE__) . '/block.readernav.php' );
		$this->add_template( 'block.readerbar', dirname(__FILE__) . '/block.readerbar.php' );
		$this->add_template('admin.feeds', dirname(__FILE__) . '/admin.feeds.php');
		$this->add_template('admin.feeds_items', dirname(__FILE__) . '/admin.feeds_items.php');
		
		$rule = new RewriteRule(array(
			'name' => "display_feedcontent",
			// this scary regex...
			'parse_regex' => "#^(?P<context>group|feed)/(?P<feedslug>[^/]+)(?:/page/(?P<page>\d+))?/?$#i",
			// just matches requests that look like this, not regarding the case:
			'build_str' => '{$context}/{$feedslug}(/page/{$page})',
			'handler' => 'PluginHandler',
			'action' => 'display_feedcontent',
			'description' => "Display a certain feed or a group of feeds",
		));

		$this->add_rule($rule, 'display_feedcontent');
	}

	/**
	 * Plugin plugin_activation action, executed when any plugin is activated
	 * @param string $file The filename of the plugin that was activated.
	 */ 
	public function action_plugin_activation( $file ='' )
	{
		// Was this plugin activated?
		if ( Plugins::id_from_file( $file ) == Plugins::id_from_file( __FILE__ ) ) { 
			$this->install();
		}
	}

	/**
	 * Plugin plugin_deactivation action, executes when any plugin is deactivated.
	 * @param string $plugin_id The filename of the plguin that was deactivated.
	 */

	public function action_plugin_deactivation( $file )
	{
		// Was this plugin deactivated?
		if ( Plugins::id_from_file( $file ) == Plugins::id_from_file( __FILE__ ) ) {
			$this->uninstall();
		}
	}
	
	private function install()
	{
		// Add read and unread statuses and vocabulary to store feeds
		Post::add_new_status('read');
		Post::add_new_status('unread');
		Vocabulary::create(array('description' => 'Feeds to collect posts from', 'name' => 'feeds', 'features' => array('hierarchical')));
		// Add a periodical execution event to be triggered hourly
		CronTab::add_hourly_cron( 'feedreader', 'load_feeds', 'Load feeds for feedreader plugin.' );
	}
	
	private function uninstall()
	{
		// Remove the periodical execution event
		CronTab::delete_cronjob( 'feedreader' );
		// Remove statuses and vocabulary
		if(Vocabulary::exists('feeds')) Vocabulary::get('feeds')->delete();
		Post::delete_post_status('read');
		Post::delete_post_status('unread');
	}
	
	/**
	 * Admin: Redirect post requests to get thingy
	 */
	public function alias()
	{
		return array(
			'action_admin_theme_get_manage_feeds' => 'action_admin_theme_post_manage_feeds'
		);
	}
	
	/**
	 * Admin: Allow access
	 */
	public function filter_admin_access( $access, $page, $post_type ) {
		if ( $page != 'manage_feeds') {
			return $access;
		}
	 
		return true;
	}
	
	/**
	 * Add styling to admin
	 */
	public function action_admin_header($theme)
	{
		if ( $theme->page == 'manage_feeds' ) {
			Stack::add('admin_stylesheet', array($this->get_url() . '/admin.css', 'screen'));
			Stack::add('admin_footer_javascript', $this->get_url() . '/admin.js', 'feedreader_js', 'jquery');
		}
	}
	
	/**
	 * Admin: Display page
	 */
	public function action_admin_theme_get_manage_feeds( AdminHandler $handler, Theme $theme )
	{
		$vocab = Vocabulary::get('feeds');
		
		// Handle added feeds
		if(isset($handler->handler_vars['add_feed']) && isset($handler->handler_vars['new_feedurl'])) {
			// Make sure there is no slash at the end (avoid duplicate entries because of endslashes)
			$url = $handler->handler_vars['new_feedurl'];
			$url = (substr($url, -1) == "/") ? substr($url, 0, -1) : $url;
			$term = $vocab->get_term(Utils::slugify($url));
			if(!$term) {
				$term = $vocab->add_term(new Term(array('term' => Utils::slugify($url), 'term_display' => $url)));
				$term->info->url = $url;
				$term->update();
			}
			
			// Force the new feed to be updated. Also gets feed URLs from other URLs
			if($this->update_feed($term, true)) {
				Session::notice("Feed added successfully");
			}
			else {
				Session::error("There was a problem adding the feed");
			}
		}
		// Handle filters and actions
		else if(isset($_POST['update'])) {
			foreach($_POST['feed_slugs'] as $slug) {
				$this->update_feed($vocab->get_term($slug), true);
			}
		}
		else if(isset($_POST['delete'])) {
			foreach($_POST['feed_slugs'] as $slug) {
				$this->delete_feed($vocab->get_term($slug));
			}
		}
		else if(isset($_POST['applygroup'])) {
			if(isset($_POST['group']) && !in_array($_POST['group'], array('new', 'all', 'none'))) {
				// Get group
				$groupterm = $vocab->get_term($_POST['group']);
				if(isset($groupterm)) {
					// Get the first child of that group
					$desc = $groupterm->descendants()[0];
					if(isset($desc)) {
						// Bump all selected terms to that position
						foreach($_POST['feed_slugs'] as $slug) {
							$term = $vocab->get_term($slug);
							$vocab->move_term($term, $desc);
						}
					}
				}
			}
			else if(isset($_POST['group']) && $_POST['group'] == 'new' && !empty($_POST['new_group']) && count($_POST['feed_slugs'])) {
				// Create group
				$newgroup = $vocab->add_term(Utils::slugify($_POST['new_group']));
				$newgroup->term_display = $_POST['new_group'];
				// Create fake descendant (dirty workaround because I don't know better)
				$desc = $vocab->add_term(Utils::slugify("fake"), $newgroup);
				foreach($_POST['feed_slugs'] as $slug) {
					$term = $vocab->get_term($slug);
					$vocab->move_term($term, $desc);
				}
				$desc->delete();
			}
			else if(isset($_POST['group']) && $_POST['group'] == 'none') {
				$root = $vocab->get_root_terms()[0];
				foreach($_POST['feed_slugs'] as $slug) {
					$term = $vocab->get_term($slug);
					$vocab->move_term($term, $root);
				}
			}
		}
		// @todo cleanup empty groups
				
		// Get the feeds
		$feeds = array();
		$groups = array();
		$groups['all'] = _t("all", __CLASS__);
		$groups['new'] = _t("new", __CLASS__);
		$groups['none'] = _t("none", __CLASS__);
		foreach($vocab->get_root_terms() as $term) {
			$feeds = $this->collect_feeds($feeds, $groups, $term);
		}

		// Display
		$theme->feeds = $feeds;
		$theme->groups = $groups;
		$theme->group = (isset($_POST['group'])) ? $_POST['group'] : 'all';
		$theme->itemstatus = (isset($_POST['items'])) ? $_POST['items'] : '';
		$theme->display( 'admin.feeds' );
	 
		// End everything
		exit;
	}
	
	/**
	 * Delete a feed and all it's posts
	 */
	function delete_feed($term)
	{
		$posts = $term->objects('post');
		foreach($posts as $post_id) {
			$post = Post::get($post_id);
			if($post) {
				$post->delete();
			}
		}
		$term->delete();
	}
	
	function collect_feeds($feeds, &$groups, $term, $parent = null)
	{
		try {
			$date = HabariDateTime::date_create($_POST['updated_before'])->int;
		} catch(Exception $e) {}
		
		if(count($term->descendants()) > 0) {
			$groups[$term->term] = $term->term_display;
			// // Cheesy check if the selected group is the current one that we just added to the list
			// if($handler->handler_vars['group'] != 0 && $handler->handler_vars['group'] != count($groups) - 1) {
				// continue;
			// }
			foreach($term->descendants() as $d) {
				$feeds = $this->collect_feeds($feeds, $groups, $d, $term);
			}
		}
		else {
			$item = $this->term_to_menu($term);
			
			// Unread count filter
			if(isset($_POST['filter']) && (($_POST['items'] == 1 && $item['count'] > 0) || ($_POST['items'] == 2 && $item['count'] == 0))) {
				return $feeds;
			}
			// Date filter
			if(isset($_POST['filter']) && isset($date) && (int) $item['lastcheck'] >= $date) {
				return $feeds;
			}
			// Broken filter
			if(isset($_POST['filter']) && isset($_POST['only_broken']) && (!isset($term->info->broken_text) || empty($term->info->broken_text))) {
				return $feeds;
			}
			// Regex filter
			if(isset($_POST['filter']) && isset($_POST['title_regex']) && $_POST['title_regex'] != _t("title regex filter", __CLASS__) && !preg_match($_POST['title_regex'], $item['title'], $dummy)) {
				return $feeds;
			}
			// Group filter
			if(isset($_POST['filter']) && isset($_POST['group']) && !in_array($_POST['group'], array('new', 'all', 'none')) && (!isset($parent) || $parent->term != $_POST['group'])) {
				return $feeds;
			}
			if(isset($_POST['filter']) && isset($_POST['group']) && $_POST['group'] == 'none' && isset($parent)) {
				return $feeds;
			}
			
			$item['group'] = $parent;
			$item['brokentext'] = $term->info->broken_text;
			
			$feeds[] = $item;
		}
		return $feeds;
	}
	
	/**
	 * Admin: Add page to menu
	 */
	public function filter_adminhandler_post_loadplugins_main_menu( array $menu )
	{
		$item_menu = array( 'manage_feeds' => array(
			'url' => URL::get( 'admin', 'page=manage_feeds'),
			'title' => _t('Manage Feeds'),
			'text' => _t('Manage Feeds'),
			'hotkey' => 'F',
			'selected' => false
		) );
	 
		$slice_point = array_search( 'themes', array_keys( $menu ) );
		$pre_slice = array_slice( $menu, 0, $slice_point);
		$post_slice = array_slice( $menu, $slice_point);
	 
		$menu = array_merge( $pre_slice, $item_menu, $post_slice );
	 
		return $menu;
	}
	
	/**
	 * Make block available
	 */
	public function filter_block_list( $blocklist )
	{
		$blocklist[ 'readernav' ] = _t( 'FeedReader navigation' );
		$blocklist[ 'readerbar' ] = _t( 'FeedReader action bar' );
		return $blocklist;
	}
	
	/**
	 * Add block config
	 */
	public function action_block_form_readernav( $form, $block )
	{
		$form->append( 'checkbox', 'hide_subitems', $block, _t( 'Hide grouped subfeeds:', __CLASS__ ) );
	}
	
	/**
	 * Collect feeds and create a navigation
	 */
	public function action_block_content_readernav( $block )
	{
		$nav = $this->create_navigation();
		$block->navigation = $nav;
	}
		
	private function create_navigation()
	{
		//todo: replace by recursive function
		$nav = array();

		foreach(Vocabulary::get('feeds')->get_root_terms() as $term) {
			if(count($term->descendants()) > 0) {
				$group = array();
				$group['internal_url'] = URL::get('display_feedcontent', array('context' => 'group', 'feedslug' => $term->term));
				$group['title'] = $term->term_display;
				$group['count'] = Posts::get(array('status' => 'unread', 'content_type' => Post::type('entry'), 'nolimit'=>1, 'count' => '*', 'vocabulary' => array('any' => $term->descendants())));
				$group['subitems'] = array();
				foreach($term->descendants() as $d) {
					$group['subitems'][] = $this->term_to_menu($d);
				}
				$nav[] = $group;
			}
			else {
				$nav[] = $this->term_to_menu($term);
			}
		}
		
		return $nav;
	}
	
	private function term_to_menu($term)
	{
		$entry = array();
		$entry['internal_url'] = URL::get('display_feedcontent', array('context' => 'feed', 'feedslug' => $term->term));
		$entry['title'] = $term->term_display;
		$entry['slug'] = $term->term;
		$entry['url'] = $term->info->url;
		$entry['lastcheck'] = $term->info->lastcheck;
		$entry['lastupdate'] = $term->info->lastupdate;
		$entry['count'] = $term->info->count;
		return $entry;
	}

	/**
	 * Executes when the admin plugins page wants to know if plugins have configuration links to display.
	 * 
	 * @param array $actions An array of existing actions for the specified plugin id. 
	 * @param string $plugin_id A unique id identifying a plugin.
	 * @return array An array of supported actions for the named plugin
	 */
	public function filter_plugin_config( $actions, $plugin_id )
	{
		// Is this plugin the one specified?
		if($plugin_id == $this->plugin_id()) {
			// Add a 'configure' action in the admin's list of plugins
			$actions['configure']= _t('Configure');
			$actions['update'] = _t('Update All Now');
			$actions['resetbroken'] = _t('Reset all broken feeds');
			$actions['import'] = _t('Import OPML file');
			$actions['reinstall'] = _t('Re-install (DANGEROUS)');
		}
		return $actions;
	}
	
	/**
	 * Executes when the admin plugins page wants to display the UI for a particular plugin action.
	 * Displays the plugin's UI.
	 * 
	 * @param string $plugin_id The unique id of a plugin
	 * @param string $action The action to display
	 */
	public function action_plugin_ui( $plugin_id, $action )
	{
		// Display the UI for this plugin?
		if($plugin_id == $this->plugin_id()) {
			// Depending on the action specified, do different things
			switch($action) {
			case 'configure':
				$ui = new FormUI( __CLASS__ );
				// Display the form
				$ui->append( 'text', 'autoremove_days', __CLASS__ . '__autoremove_days', _t('Remove read posts after X days', __CLASS__) );
				$ui->autoremove_days->add_validator('validate_regex', '/^[0-9]+$/', _t('Only numbers may be entered.', __CLASS__));
				$ui->append('checkbox', 'verbose_logging', __CLASS__ . '__verbose_logging', _t('Enable very verbose logging', __CLASS__));
				$ui->append( 'submit', 'save', _t( 'Save' ) );
				$ui->on_success( array($this, 'save_config') );
				$ui->out();
				break;
			case 'update':
				// Reset the cronjob so that it runs immediately
				CronTab::delete_cronjob( 'feedreader' );
				CronTab::add_hourly_cron( 'feedreader', 'load_feeds', 'Load feeds for feedreader plugin.' );
				Session::notice(_t("The cronjob has been triggered and the update is now running in the background.", __CLASS__));
				break;
			case 'resetbroken':
				// Reset broken counters and messages
				$feedterms = Vocabulary::get('feeds')->get_tree();
				foreach($feedterms as $term) {
					if(count($term->descendants()) > 0) {
						// Just a group term
						continue;
					}
					$term->info->broken_text = "";
					$term->update();
				}
				Session::notice( _t("All feeds have been set to ok.", __CLASS__) );
				break;
			case 'import':
				$ui = new FormUI( __CLASS__ );
				$ui->append('file', 'import', 'null', "Choose subscription list file");
				$ui->on_success( array( $this, 'do_import') );
				$ui->append( 'submit', 'save', _t( 'Save' ) );
				$ui->out();
				break;
			case "reinstall":
				$this->uninstall();
				$posts = Posts::get(array("status" => "any", "nolimit" => 1));
				if(!empty($posts)) $posts->delete();
				$this->install();
				EventLog::log("Deleted all posts and feed terms");
				break;
			}
		}
	}
	
	function save_config($form)
	{
		$form->save();
		CronTab::delete_cronjob( 'feedreader_cleanup' );
		CronTab::add_daily_cron( 'feedreader_cleanup', 'clean_feeds', 'Cleanup old posts from feedreader plugin.' );
		return false;
	}
	
	/**
	 * The cron for cleaning old read posts
	 */
	function filter_clean_feeds($result)
	{
		$days = Options::get(__CLASS__ . '_autoremove_days');
		$posts = Posts::get(array('before' => strtotime("-$days days"), 'status' => Post::status('read'), 'nolimit' => 1));
		$count = (string) count($posts);
		if($posts->delete()) {
			EventLog::log(_t("%s read posts automatically removed", array($count), __CLASS__), 'info');
			return $result;
		}
		else {
			return false;
		}
	}
	
	/**
	 * Handle the submitted import form aka do the import
	 */
	public function do_import($ui)
	{
		$xmlstring = file_get_contents($ui->import->tmp_file);
		$xml = new SimpleXMLElement($xmlstring);
		$vocab = Vocabulary::get('feeds');
		$feeds = 0;
		$groups = 0;
		foreach($xml->body->outline as $o) {
			if(count($o->outline)) {
				// This is a group
				$term = $vocab->get_term(Utils::slugify($o['title']));
				if(!$term) {
					$term = $vocab->add_term(new Term(Utils::slugify($o['title'])));
				}
				$groups++;
				foreach($o->outline as $feed) {
					$urlterm = $vocab->get_term(Utils::slugify($feed['xmlUrl']));
					if(!$urlterm) {
						$urlterm = $vocab->add_term(new Term(Utils::slugify($feed['xmlUrl'])), $term);
					}
					$urlterm->info->url = (string) $feed['xmlUrl'];
					$urlterm->term_display = (string) $feed['title'];
					$urlterm->update();
					$feeds++;
				}
			}
			else {
				$urlterm = $vocab->get_term(Utils::slugify($o['xmlUrl']));
				if(!$urlterm) {
					$urlterm = $vocab->add_term(new Term(Utils::slugify($o['xmlUrl'])));
				}
				$urlterm->info->url = (string) $o['xmlUrl'];
				$urlterm->term_display = (string) $o['title'];
				$urlterm->update();
				$feeds++;
			}
		}
		Session::notice(_t('Imported %1$d feeds and %2$d groups', array($feeds, $groups), __CLASS__));
		
		// Reset the cronjob so that it runs immediately with the change
		CronTab::delete_cronjob( 'feedreader' );
		CronTab::add_hourly_cron( 'feedreader', 'load_feeds', 'Load feeds for feedreader plugin.' );
	}

	/**
	 * Plugin load_feeds filter, executes for the cron job defined in action_plugin_activation()
	 * @param boolean $result The incoming result passed by other sinks for this plugin hook
	 * @return boolean True if the cron executed successfully, false if not.
	 */
	public function filter_load_feeds( $result )
	{
		// Check internet connection
		$dummy = RemoteRequest::get_contents("http://google.com");
		if($dummy == null) {
			EventLog::log( _t("Skipping feed updating, no internet connection", __CLASS__), 'notice');
			return $result;
		}
		
		$feedterms = Vocabulary::get('feeds')->get_tree();
		$menu = Vocabulary::get('FeedReader');
		EventLog::log( _t("Updating feeds...", __CLASS__), 'debug' );

		foreach( $feedterms as $term ) {
			if(count($term->descendants()) > 0) {
				// Just a group term
				continue;
			}
					
			$this->update_feed($term);
		}
		
		// This should only happen if any feed was updated
		$this->create_navigation();
		
		// log that we finished
		EventLog::log( _t( 'Finished processing %1$d feed term(s).', array(count($feedterms)), __CLASS__), 'info');
				
		return $result;		// only change a cron result to false when it fails
	}
	
	/**
	 * Update a single feed
	 */
	public function update_feed($term, $force = false)
	{
		$verbose = Options::get(__CLASS__ . '__verbose_logging', false);
		
		if(!$force && isset($term->info->lastcheck) && HabariDateTime::date_create()->int - HabariDateTime::date_create($term->info->lastcheck)->int < 600) {
			// Don't check more than every 10 minutes
			if($verbose) EventLog::log( _t('Feed %s skipped because the last check was less than 10 minutes ago.', array($term->term), __CLASS__), 'debug' );
			return false;
		}
			
		$feed_url = $term->info->url;
		
		if ( $feed_url == '' ) {
			if($verbose) EventLog::log( _t('Feed %1$s is missing the URL.', array($term->term), __CLASS__), 'warning' );
			$term->info->broken_text = _t("URL is missing", __CLASS__);
			$term->update();
			return false;
		}
				
		// load the XML data
		$xml = RemoteRequest::get_contents( $feed_url );
		if ( !$xml ) {
			if($verbose) EventLog::log( _t('Unable to fetch feed %1$s data from %2$s.', array($term->term, $feed_url), __CLASS__), 'warning' );
			$term->info->broken_text = _t("Unable to fetch data", __CLASS__);
			$term->update();
			return false;
		}
		
		$dom = new DOMDocument();
		// @ to hide parse errors
		@$dom->loadXML( $xml );

		if( $dom->getElementsByTagName('rss')->length > 0 || $dom->getElementsByTagName('channel')->length > 0 ) {
			$type = "rss";
		}
		else if( $dom->getElementsByTagName('feed')->length > 0 ) {
			$type = "atom";
		}
		else {
			// it might be a non-feed, but valid URL, try to get the feed URL
			@$dom->loadHTML( $xml );
			$links = $dom->getElementsByTagName("head")->item(0)->getElementsByTagName("link");
			foreach($links as $link)
			{
				if($link->getAttribute("rel") == "alternate") {
					if(!isset($rss) && stripos($link->getAttribute("type"), "application/rss") !== false) {
						$rss = $link->getAttribute("href");
					}
					if(!isset($atom) && stripos($link->getAttribute("type"), "application/atom") !== false) {
						$atom = $link->getAttribute("href");
					}
				}
			}
			
			if(isset($atom) || isset($rss)) {
				// we found the URL to the feed, save it and try again. Prefer Atom feeds
				$term->info->url = (isset($atom)) ? $atom : $rss;
				$term->update();
				return $this->update_feed($term, $force);
			}
			else {
				// no success, the URL is useless for us
				if($verbose) EventLog::log( _t('Feed %1$s is an unsupported format.', array($term->term), __CLASS__), 'warning' );
				$term->info->broken_text = _t("Unsupported format", __CLASS__);
				$term->update();
				return false;
			}
		}
		
		// Check if the feed itself says it wasn't updated. Looks awful because there are many ways to do so
		$term_lastcheck = $term->info->lastcheck;
		if( isset($term_lastcheck) && !$force ) {
			if( $dom->getElementsByTagName('pubDate')->length > 0 && $dom->getElementsByTagName('pubDate')->item(0)->parentNode->tagName == "rss") {
				$feed_updated = $dom->getElementsByTagName('pubDate')->item(0)->nodeValue;
			}
			else if( $dom->getElementsByTagName('lastBuildDate')->length > 0 && $dom->getElementsByTagName('lastBuildDate')->item(0)->parentNode->tagName == "rss") {
				// Wordpress style
				$feed_updated = $dom->getElementsByTagName('lastBuildDate')->item(0)->nodeValue;
			}
			else if( $dom->getElementsByTagName('updated')->length > 0 && $dom->getElementsByTagName('updated')->item(0)->parentNode->tagName == "feed") {
				$feed_updated = $dom->getElementsByTagName('updated')->item(0)->nodeValue;
			}
			if(isset($feed_updated)) {
				try {
					$feed_updated = HabariDateTime::date_create($feed_updated);
					if( $feed_updated->int < HabariDateTime::date_create($term->info->lastcheck)->int ) {
						if($verbose) EventLog::log( _t('Feed %s was not updated since the last check.', array($term->term), __CLASS__), 'info' );
						return true;
					}
				}
				catch(Exception $e) { /* discard invalid dates */ }
			}
		}
		
		if($type == "rss") {
			$items = $this->parse_rss( $dom );
		}
		else if($type == "atom") {
			$items = $this->parse_atom( $dom );
		}

		// Save the feed title
		$term->term_display = $dom->getElementsByTagName('title')->item(0)->nodeValue;
		
		// Check if the feed content was okay
		if($items === false) {
			// There were empty or invalid posts
			if($verbose) EventLog::log( _t('Feed %1$s had invalid posts.', array($term->term), __CLASS__), 'warning' );
			$term->info->broken_text = _t("Invalid posts", __CLASS__);
			$term->update();
			// return true anyway, the feed might contain readable posts
			return true;
		}
		else {
			// Everything is okay. Save and log success.
			$changed = $this->replace( $term, $items );
			if($changed) {
				$term->info->count = Posts::get(array('status' => 'unread', 'content_type' => Post::type('entry'), 'nolimit'=>1, 'count' => '*', 'vocabulary' => array('any' => array($term))));
				$term->info->lastupdate = HabariDateTime::date_create()->int;
			}
			$term->info->lastcheck = HabariDateTime::date_create()->int;
			unset($term->info->broken_text);
			$term->update();
			if($verbose) EventLog::log( _t( 'Successfully updated feed %1$s', array($term->term), __CLASS__ ), 'info' );
			return true;
		}
	}
	
	/**
	 * Parse out RSS 2.0 feed items.
	 * 
	 * See the example feed: http://www.rss-tools.com/rss-example.htm
	 * 
	 * @param DOMDocument $dom
	 * @return array Array of items.
	 */
	private function parse_rss ( DOMDocument $dom ) {
		// each item is an 'item' tag in RSS2
		$items = $dom->getElementsByTagName('item');
		$verbose = Options::get(__CLASS__ . '__verbose_logging', false);
		$feed_items = array();
		foreach ( $items as $item ) {
			
			$feed = array();
			
			if($item->getElementsByTagName('title')->length > 0) {
				$feed['title'] = $item->getElementsByTagName('title')->item(0)->nodeValue;
			}
			else {
				if($verbose) EventLog::log( _t("Item with no title, something is wrong with this feed", __CLASS__), 'err');
				return false;
			}
			if($item->getElementsByTagName('encoded')->length > 0) {
				// Wordpress-style fulltext content
				$feed['content'] = $item->getElementsByTagName('encoded')->item(0)->nodeValue;
			}
			else if($item->getElementsByTagName('description')->length > 0) {
				$feed['content'] = $item->getElementsByTagName('description')->item(0)->nodeValue;
			}
			else {
				if($verbose) EventLog::log( _t("Item with no content, something is wrong with this feed", __CLASS__), 'err');
				return false;
			}
			if($item->getElementsByTagName('link')->length > 0) {
				$feed['link'] = $item->getElementsByTagName('link')->item(0)->nodeValue;
			}
			else {
				if($verbose) EventLog::log( _t("Item with no URL, something is wrong with this feed", __CLASS__), 'err');
				return false;
			}
			if($item->getElementsByTagName('pubDate')->length > 0) {
				try {
					$feed['published'] = HabariDateTime::date_create($item->getElementsByTagName('pubDate')->item(0)->nodeValue);
				}
				catch(Exception $e) {
					if($verbose) EventLog::log( _t("Item with invalid date format %s, something is wrong with this feed", array($item->getElementsByTagName('pubDate')->item(0)->nodeValue), __CLASS__), 'err');
					return false;
				}
			}
			else if($item->getElementsByTagName('date')->length > 0) {
				try {
					$feed['published'] = HabariDateTime::date_create($item->getElementsByTagName('date')->item(0)->nodeValue);
				}
				catch(Exception $e) {
					if($verbose) EventLog::log( _t("Item with invalid date format %s, something is wrong with this feed", array($item->getElementsByTagName('date')->item(0)->nodeValue), __CLASS__), 'err');
					return false;
				}
			}
			else {
				if($verbose) EventLog::log( _t("RSS: Item with no date, something is wrong with this feed", __CLASS__), 'err');
				return false;
			}
			$feed['updated'] = $feed['published'];
			if($item->getElementsByTagName('creator')->length > 0) {
				// Wordpress-style author names
				if($item->getElementsByTagName('creator')->item(0)->getElementsByTagName('name')->length > 0) {
					$feed['author'] = $item->getElementsByTagName('creator')->item(0)->getElementsByTagName('name')->item(0)->nodeValue;
				}
				else {
					$feed['author'] = $item->getElementsByTagName('creator')->item(0)->nodeValue;
				}
			}
			elseif($item->getElementsByTagName('author')->length > 0) {
				$feed['author'] = $item->getElementsByTagName('author')->item(0)->nodeValue;
			}
			if($item->getElementsByTagName('guid')->length > 0) {
				$feed['guid'] = $item->getElementsByTagName('guid')->item(0)->nodeValue;
			}
			else {
				$feed['guid'] = Utils::slugify($feed['title'] . $feed['link']);
				if($verbose) EventLog::log( _t('No GUID found in %1$s (from %2$s). A GUID was created automatically.', array($feed['title'], Utils::slugify($feed['link'])), __CLASS__), 'notice' );
			}
			
			$feed_items[] = $feed;
			
		}
		
		return $feed_items;
		
	}
	
	/**
	 * Parse out ATOM feed items.
	 * 
	 * See the example feed: http://www.atomenabled.org/developers/syndication/#sampleFeed
	 * 
	 * @param DOMDocument $dom
	 * @return array Array of items.
	 */
	private function parse_atom ( DOMDocument $dom )
	{
		// each item is an 'entry' tag in ATOM
		$items = $dom->getElementsByTagName('entry');
		$verbose = Options::get(__CLASS__ . '__verbose_logging', false);
		$feed_items = array();
		foreach ( $items as $item ) {
			
			$feed = array();
			
			if($item->getElementsByTagName('title')->length > 0) {
				$feed['title'] = $item->getElementsByTagName('title')->item(0)->nodeValue;
			}
			else {
				if($verbose) EventLog::log( _t("Item with no title, something is wrong with this feed", __CLASS__), 'err');
				return false;
			}
			if($item->getElementsByTagName('content')->length > 0) {
				$feed['content'] = $item->getElementsByTagName('content')->item(0)->nodeValue;
			}
			elseif($item->getElementsByTagName('summary')->length > 0) {
				// Google groups style
				$feed['content'] = $item->getElementsByTagName('summary')->item(0)->nodeValue;
			}
			else {
				if($verbose) EventLog::log( _t("Item with no content, something is wrong with this feed", __CLASS__), 'err');
				return false;
			}
			if($item->getElementsByTagName('link')->length > 0) {
				foreach($item->getElementsByTagName('link') as $link) {
					// make sure we use the HTML version of the post itself
					if($link->hasAttribute("rel") == false || (($link->getAttribute("rel") == "self" || $link->getAttribute("rel") == "alternate") && $link->getAttribute("type") == "text/html")) {
						$feed['link'] = $link->getAttribute('href');
					}
				}
			}
			else {
				if($verbose) EventLog::log( _t("Item with no URL, something is wrong with this feed", __CLASS__), 'err');
				return false;
			}
			if($item->getElementsByTagName('published')->length > 0) {
				try {
					$feed['published'] = HabariDateTime::date_create($item->getElementsByTagName('published')->item(0)->nodeValue);
				}
				catch(Exception $e) {
					if($verbose) EventLog::log( _t("Item with invalid date format %s, something is wrong with this feed", array($item->getElementsByTagName('published')->item(0)->nodeValue), __CLASS__), 'err');
					return false;
				}
			}
			if($item->getElementsByTagName('updated')->length > 0) {
				try {
					$feed['updated'] = HabariDateTime::date_create($item->getElementsByTagName('updated')->item(0)->nodeValue);
				}
				catch(Exception $e) {
					if($verbose) EventLog::log( _t("Item with invalid date format %s, something is wrong with this feed", array($item->getElementsByTagName('updated')->item(0)->nodeValue), __CLASS__), 'err');
				}
				if(!isset($feed['published'])) {
					$feed['published'] = $feed['updated'];
				}
			}
			elseif(isset($feed['published'])) {
				$feed['updated'] = $feed['published'];
			}
			else {
				if($verbose) EventLog::log( _t("Atom: Item with no date, something is wrong with this feed", __CLASS__), 'err');
				return false;
			}
			if($item->getElementsByTagName('creator')->length > 0) {
				// Wordpress-style author names
				if($item->getElementsByTagName('creator')->item(0)->getElementsByTagName('name')->length > 0) {
					$feed['author'] = $item->getElementsByTagName('creator')->item(0)->getElementsByTagName('name')->item(0)->nodeValue;
				}
				else {
					$feed['author'] = $item->getElementsByTagName('creator')->item(0)->nodeValue;
				}
			}
			elseif($item->getElementsByTagName('author')->length > 0) {
				$feed['author'] = $item->getElementsByTagName('author')->item(0)->getElementsByTagName('name')->item(0)->nodeValue;
			}
			if($item->getElementsByTagName('id')->length > 0) {
				$feed['guid'] = $item->getElementsByTagName('id')->item(0)->nodeValue;
			}
			else {
				$feed['guid'] = Utils::slugify($feed['title'] . $feed['link']);
				if($verbose) EventLog::log( _t('No GUID found in %1$s (from %2$s). A GUID was created automatically.', array($feed['title'], Utils::slugify($feed['link'])), __CLASS__), 'notice' );
			}
			
			$feed_items[] = $feed;
			
		}
		
		return $feed_items;
	}
	
	/**
	 * Insert all the feed items as posts and modify existing posts
	 */
	private function replace ( $term, $items ) {
		$updated = false;
		
		$verbose = Options::get(__CLASS__ . '__verbose_logging', false);
		
		foreach ( $items as $item ) {
			// Check date
			if($term->info->lastcheck > $item["updated"]->int) {
				continue;
			}
			
			// Sanity checks
			if(empty($item["content"])) {
				if($verbose) Eventlog::log( _t("Skipping item %s because it has no content.", array($term->term), __CLASS__), 'err' );
				continue;
			}
			
			$changed = false;
			
			// Get existing post or create new one
			$post = Post::get(array('all:info' => array('guid' => $item["guid"])));
			if(!$post) {
				$post = new Post();
				$post->content_type = 1;
				$post->user_id = 1;
				$post->status = Post::status('unread');
				$changed = true;
			}
			
			// Save fields
			$changed = $changed || $post->title != $item["title"];
			$post->title = (!empty($item["title"])) ? $item["title"] : _t("Untitled", __CLASS__);
			$changed = $changed || $post->content != $item["content"];
			$post->content = $item["content"];
			$changed = $changed || $post->updated != $item["updated"];
			$post->updated = $item["updated"]->int;
			$changed = $changed || $post->pubdate != $item["published"];
			$post->pubdate = $item["published"]->int;
			$changed = $changed || $post->info->guid != $item["guid"];
			$post->info->guid = $item["guid"];
			$changed = $changed || $post->info->link != $item["link"];
			$post->info->link = $item["link"];
			if(isset($item['author'])) {
				$changed = $changed || $post->author != $item["author"];
				$post->info->author = $item["author"];
			}
			
			$post->info->feed = $term;

			$result = ($post->id) ? $post->update() : $post->insert();
			$term->associate('post', $post->id);
			
			if ( !$result ) {
				if($verbose) Eventlog::log( _t("There was an error saving item %s", array($term->term), __CLASS__), 'err' );
			}
			else {
				// If we got here and there was no error, at least one item was created or updated.
				$updated = $updated || $changed;
			}
		}
		
		return $updated;
	}
	
	/**
	 * Add the posts to the blog home and it's pagination pages
	 */
	public function filter_template_user_filters( $filters ) 
	{
		$filters['status'] = array('unread');
		return $filters;
	}
	
	/**
	 * Grab the posts requested by the matched rewrite rule and display them in the theme
	 * Process mark as read and all the other action bar actions
	 */
	public function theme_route_display_feedcontent($theme, $params)
	{
		$redirect = "";
		$term = Vocabulary::get('feeds')->get_term($params['feedslug']);
		if($term) {
			// Add action bar form
			$form = new FormUI(__CLASS__);
			$form->append('submit', 'mark_page_read', 'Mark page read');
			$form->append('submit', 'mark_all_read', 'Mark all read');
			$form->append('submit', 'show_read', 'Re-display read posts');
			$form->append('submit', 'delete_feed', 'Unsubscribe feed');
			$theme->mark_all_read_form = $form;
			
			// Remove feed if requested
			if(!empty($form->delete_feed->value) && User::identify()->loggedin) {
				$this->delete_feed($term);
				Utils::redirect(Site::get_url('habari'));
			}
			
			// Select posts
			if($params['context'] == 'feed') {
				$termlist = array($params['feedslug']);
			}
			elseif($params['context'] == 'group') {
				$termlist = array();
				foreach($term->descendants() as $d) {
					$termlist[] = $d->term;
				}
			}
			else return;
			
			$filters = array('vocabulary' => array('feeds:term' => $termlist), 'status' => array(Post::status('unread')));
					
			// Process "mark ALL as read"
			if(!empty($form->mark_all_read->value)) {
				$filters['nolimit'] = 1;
				$term->info->count = 0;
				$term->update();
				foreach($term->descendants() as $d) {
					$d->info->count = 0;
					$d->update();
				}
				
				// Proceed to next feed / group
				foreach($term->siblings() as $s) {
					// todo: this is a poor solution. we will get rid of it as soon as we get rid of all the feed/group differenciation
					if($term->term != $s->term && $s->info->count > 0 && count($s->descendants()) == 0) {
						$redirect = URL::get('display_feedcontent', array('context' => 'feed', 'feedslug' => $s->term));
						break;
					}
				}
			}
			
			// Process "show read"
			if(!empty($form->show_read->value)) {
				$filters['status'][] = Post::status('read');
			}
			
			// Get posts
			$posts = Posts::get($filters);
		
			// Process "mark * as read"
			if(!empty($form->mark_page_read->value) || !empty($form->mark_all_read->value)) {
				foreach($posts as $post) {
					$post->status = Post::status('read');
					$post->update();
				}
			}
			
			if(!empty($redirect)) {
				Utils::redirect($redirect);
			}
			
			$theme->feedterm = $term;
			$theme->act_display(array('posts' => $posts));
		}
		else $theme->act_display_404();
	}
	
	/**
	 * Provide a JS-clickable img in $post->toggle_status_link
	 */
	public function filter_post_toggle_status_link($toggle_status_link, $post)
	{
		$class = ($post->status == Post::status('read')) ? "read" : "unread";
		return "<a id='toggle-$post->id' onclick='FeedReader.toggle($post->id);' class='$class'><img src='" . $this->get_url("/$class.png") . "' id='statusimg-$post->id' alt='$class' title='$class' class='statusimg'></a>";
	}
	
	/**
	 * Check if an article is read when requested via JS and invert it's read status
	 * This is an AJAX callback that should be linked to the above img
	 **/
	public function action_auth_ajax_toggle_readstatus($handler)
	{
		$user = User::identify();
		//if($user->can('feature_content')) {
			// Get the data that was sent
			$id = $handler->handler_vars[ 'q' ];
			// Do actual work
			if(is_numeric($id))
			{
				$post = Post::get(array('id' => $id));
				if($post->status == Post::status('read')) {
					$post->status = Post::status('unread');
					$c = 1;
				}
				else {
					$post->status = Post::status('read');
					$c = -1;
				}
				if($post->update(true)) {
					$terms = Vocabulary::get('feeds')->get_object_terms('post', $post->id);
					foreach($terms as $term) {
						$term->info->count += $c;
						$term->update();
					}
					echo Post::status_name($post->status);
				}
				else {
					echo "error";
				}
			}
		//}
	}
}	

?>
