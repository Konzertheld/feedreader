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
		// Add a periodical execution event to be triggered hourly
		CronTab::add_hourly_cron( 'feedreader', 'load_feeds', 'Load feeds for feedreader plugin.' );
		// Log the cron creation event
		EventLog::log('Added hourly cron for feed updates.');
		// Create vocabulary for the feeds
		Vocabulary::create(array('description' => 'Feeds to collect posts from', 'name' => 'feeds', 'features' => array('hierarchical')));
		// Add read and unread statuses
		Post::add_new_status('read');
		Post::add_new_status('unread');
	}
	
	private function uninstall()
	{
		// Remove the periodical execution event
		CronTab::delete_cronjob( 'feedlist' );
		// Log the cron deletion event.
		EventLog::log('Deleted cron for feed updates.');
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
			
			// Reset the cronjob so that it runs immediately with the change
			CronTab::delete_cronjob( 'feedreader' );
			CronTab::add_hourly_cron( 'feedreader', 'load_feeds', 'Load feeds for feedreader plugin.' );
		}
				
		// Get the feeds
		$feeds = array();
		$groups = array();
		$groups[] = "all";
		
		foreach($vocab->get_root_terms() as $term) {
			if(count($term->descendants()) > 0) {
				$groups[] = $term->term_display;
				// Cheesy check if the selected group is the current one that we just added to the list
				if($handler->handler_vars['group'] != 0 && $handler->handler_vars['group'] != count($groups) - 1) {
					continue;
				}
				foreach($term->descendants() as $d) {
					$item = $this->term_to_menu($d);
					if(($handler->handler_vars['items'] == 1 && $item['count'] > 0) || ($handler->handler_vars['items'] == 2 && $item['count'] == 0)) {
						continue;
					}
					$item['group'] = $term;
					
					$feeds[] = $item;
				}
			}
			else {
				$item = $this->term_to_menu($term);
				if(($handler->handler_vars['items'] == 1 && $item['count'] > 0) || ($handler->handler_vars['items'] == 2 && $item['count'] == 0)) {
						continue;
					}
				$feeds[] = $item;
			}
		}
		
		// Display
		$theme->feeds = $feeds;
		$theme->groups = $groups;
		$theme->group = ($handler->handler_vars['group']) ? $handler->handler_vars['group'] : $groups[0];
		$theme->itemstatus = ($handler->handler_vars['items']) ? $handler->handler_vars['items'] : '';
		$theme->display( 'admin.feeds' );
	 
		// End everything
		exit;
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
		$entry['url'] = $term->info->url;
		$entry['lastcheck'] = $term->info->lastcheck;
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
			//$actions['configure']= _t('Configure');
			$actions['update'] = _t('Update All Now');
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
			// case 'configure':
				// $ui = new FormUI( __CLASS__ );
				// // Display the form
				// $ui->append( 'submit', 'save', _t( 'Save' ) );
				// $ui->out();
				// break;
			case 'update':
				// Reset the cronjob so that it runs immediately
				CronTab::delete_cronjob( 'feedreader' );
				CronTab::add_hourly_cron( 'feedreader', 'load_feeds', 'Load feeds for feedreader plugin.' );
				Session::notice(_t("The cronjob has been triggered and the update is now running in the background.", __CLASS__));
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
				Eventlog::log("Deleted all posts and feed terms");
				break;
			}
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
		$feedterms = Vocabulary::get('feeds')->get_tree();
		$menu = Vocabulary::get('FeedReader');
		Eventlog::log( _t("Updating feeds...", __CLASS__), 'info' );

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
	
	public function update_feed($term, $force = false)
	{	
		if(!$force && isset($term->info->lastcheck) && HabariDateTime::date_create()->int - HabariDateTime::date_create($term->info->lastcheck)->int < 600) {
			// Don't check more than every 10 minutes
			return false;
		}
		
		if(!$force && isset($term->info->broken) && $term->info->broken) {
			// Feed was marked as broken and needs manual fixing
			return false;
		}
		
		$feed_url = $term->info->url;
		
		if ( $feed_url == '' ) {
			EventLog::log( _t('Feed %s is missing the URL. Feed deactivated.', array($term->term), __CLASS__), 'warning' );
			$term->info->broken = true;
			$term->update();
			return false;
		}
				
		// load the XML data
		$xml = RemoteRequest::get_contents( $feed_url );
		if ( !$xml ) {
			EventLog::log( _t('Unable to fetch feed %1$s data from %2$s. Feed deactivated.', array($term->term, $feed_url), __CLASS__), 'warning' );
			$term->info->broken = true;
			$term->update();
			return false;
		}
		
		$dom = new DOMDocument();
		// @ to hide parse errors
		@$dom->loadXML( $xml );
		
		if( $dom->getElementsByTagName('rss')->length > 0 ) {
			if( !$force && $dom->getElementsByTagName('updated')->length > 0 && HabariDateTime::date_create($item->getElementsByTagName('updated')->item(0)->nodeValue)->int < HabariDateTime::date_create($term->info->lastcheck) ) {
				EventLog::log( _t('Feed %s was not updated since the last check.', array($term->term), __CLASS__), 'info' );
				return false;
			}
			$items = $this->parse_rss( $dom );
		}
		else if( $dom->getElementsByTagName('feed')->length > 0 ) {
			$items = $this->parse_atom( $dom );
		}
		else {
			// it's an unsupported format
			EventLog::log( _t('Feed %1$s is an unsupported format and has been deactivated.', array($term->term), __CLASS__), 'warning' );
			$term->info->broken = true;
			$term->update();
			return false;
		}
		
		// Save the feed title
		$term->term_display = $dom->getElementsByTagName('title')->item(0)->nodeValue;
		
		// Check if the feed content was okay
		if($items === false) {
			// There were empty or invalid posts
			EventLog::log( _t('Feed %1$s had invalid posts and has been deactivated.', array($term->term), __CLASS__), 'warning' );
		}
		else {
			// Everything is okay. Save and log success.
			$this->replace( $term, $items );
			$term->info->count = Posts::get(array('status' => 'unread', 'content_type' => Post::type('entry'), 'nolimit'=>1, 'count' => '*', 'vocabulary' => array('any' => array($term))));
			$term->update();
			EventLog::log( _t( 'Updated feed %1$s', array($term->term), __CLASS__ ), 'info' );
		}
		
		// Everything is done. Save the time so we don't check this feed again soon
		$term->info->lastcheck = HabariDateTime::date_create()->int;
		$term->update();
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
		
		$feed_items = array();
		foreach ( $items as $item ) {
			
			$feed = array();
			
			if($item->getElementsByTagName('title')->length > 0) {
				$feed['title'] = $item->getElementsByTagName('title')->item(0)->nodeValue;
			}
			else {
				// Item with no title, something is wrong with this feed
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
				// Item with no content, something is wrong with this feed
				return false;
			}
			if($item->getElementsByTagName('link')->length > 0) {
				$feed['link'] = $item->getElementsByTagName('link')->item(0)->nodeValue;
			}
			else {
				// Item with no URL, something is wrong with this feed
				return false;
			}
			if($item->getElementsByTagName('pubDate')->length > 0) {
				$feed['published'] = HabariDateTime::date_create($item->getElementsByTagName('pubDate')->item(0)->nodeValue);
			}
			else {
				// Item with no date, something is wrong with this feed
				return false;
			}
			$feed['updated'] = $feed['published'];
			if($item->getElementsByTagName('creator')->length > 0) {
				// Wordpress-style author names
				$feed['author'] = $item->getElementsByTagName('creator')->item(0)->nodeValue;
			}
			elseif($item->getElementsByTagName('author')->length > 0) {
				$feed['author'] = $item->getElementsByTagName('author')->item(0)->nodeValue;
			}
			if($item->getElementsByTagName('guid')->length > 0) {
				$feed['guid'] = $item->getElementsByTagName('guid')->item(0)->nodeValue;
			}
			else {
				$feed['guid'] = Utils::slugify($feed['title'] . $feed['link']);
				EventLog::log( _t('No GUID found in %1$s (from %2$s). A GUID was created automatically.', array($feed['title'], Utils::slugify($feed['link'])), __CLASS__), 'notice' );
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
	private function parse_atom ( DOMDocument $dom ) {
		
		// each item is an 'entry' tag in ATOM
		$items = $dom->getElementsByTagName('entry');
		
		$feed_items = array();
		foreach ( $items as $item ) {
			
			$feed = array();
			
			if($item->getElementsByTagName('title')->length > 0) {
				$feed['title'] = $item->getElementsByTagName('title')->item(0)->nodeValue;
			}
			else {
				// Item with no title, something is wrong with this feed
				return false;
			}
			if($item->getElementsByTagName('content')->length > 0) {
				$feed['content'] = $item->getElementsByTagName('content')->item(0)->nodeValue;
			}
			else {
				// Item with no content, something is wrong with this feed
				return false;
			}
			if($item->getElementsByTagName('link')->length > 0) {
				$feed['link'] = $item->getElementsByTagName('link')->item(0)->getAttribute('href');
			}
			else {
				// Item with no URL, something is wrong with this feed
				return false;
			}
			if($item->getElementsByTagName('published')->length > 0) {
				$feed['published'] = HabariDateTime::date_create($item->getElementsByTagName('published')->item(0)->nodeValue);
			}
			else {
				// Item with no date, something is wrong with this feed
				return false;
			}
			if($item->getElementsByTagName('updated')->length > 0) {
				$feed['updated'] = HabariDateTime::date_create($item->getElementsByTagName('updated')->item(0)->nodeValue);
			}
			else {
				$feed['updated'] = $feed['published'];
			}
			if($item->getElementsByTagName('creator')->length > 0) {
				// Wordpress-style author names
				$feed['author'] = $item->getElementsByTagName('creator')->item(0)->getElementsByTagName('name')->item(0)->nodeValue;
			}
			elseif($item->getElementsByTagName('author')->length > 0) {
				$feed['author'] = $item->getElementsByTagName('author')->item(0)->getElementsByTagName('name')->item(0)->nodeValue;
			}
			if($item->getElementsByTagName('id')->length > 0) {
				$feed['guid'] = $item->getElementsByTagName('id')->item(0)->nodeValue;
			}
			else {
				$feed['guid'] = Utils::slugify($feed['title'] . $feed['link']);
				EventLog::log( _t('No GUID found in %1$s (from %2$s). A GUID was created automatically.', array($feed['title'], Utils::slugify($feed['link'])), __CLASS__), 'notice' );
			}
			
			$feed_items[] = $feed;
			
		}
		
		return $feed_items;
	}
	
	/**
	 * Insert all the feed items as posts and modify existing posts
	 */
	private function replace ( $term, $items ) {
		$changed = false;
		
		foreach ( $items as $item ) {
			// Sanity checks
			if(empty($item["content"])) {
				Eventlog::log( _t("Skipping item %s because it has no content.", array($term->term), __CLASS__), 'err' );
				continue;
			}
			
			// Get existing post or create new one
			$post = Post::get(array('all:info' => array('guid' => $item["guid"])));
			if(!$post) {
				$post = new Post();
				$post->content_type = 1;
				$post->user_id = 1;
				$post->status = Post::status('unread');
			}
			
			// Save fields
			$post->title = (!empty($item["title"])) ? $item["title"] : _t("Untitled", __CLASS__);
			$post->content = $item["content"];
			$post->updated = $item["updated"]->int;
			$post->pubdate = $item["published"]->int;
			$post->info->guid = $item["guid"];
			$post->info->link = $item["link"];
			if(isset($item['author'])) {
				$post->info->author = $item["author"];
			}

			$result = ($post->id) ? $post->update() : $post->insert();
			$term->associate('post', $post->id);
			
			if ( !$result ) {
				Eventlog::log( _t("There was an error saving item %s", array($term->term), __CLASS__), 'err' );
			}
			else {
				// If we got here and there was no error, at least one item was created or updated.
				$changed = true;
			}
		}
	}
	
	/**
	 * Grab the posts requested by the matched rewrite rule and display them in the theme
	 * Process mark as read
	 */
	public function theme_route_display_feedcontent($theme, $params)
	{
		$term = Vocabulary::get('feeds')->get_term($params['feedslug']);
		if($term) {
			// Add action bar form
			$form = new FormUI(__CLASS__);
			$form->append('submit', 'mark_page_read', 'Mark page read');
			$form->append('submit', 'mark_all_read', 'Mark all read');
			$form->append('submit', 'show_read', 'Re-display read posts');
			$theme->mark_all_read_form = $form;
			
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
					
			// Process "show read"
			if(empty($form->show_read->value)) {
				//$filters = array('user_filters' => array('status' => Post::status('unread'), 'vocabulary' => array('feeds:term' => $termlist)));
				$filters = array('status' => Post::status('unread'), 'vocabulary' => array('feeds:term' => $termlist));
			}
			else {
				//$filters = array('user_filters' => array('status' => array(Post::status('unread'), Post::status('read')), 'vocabulary' => array('feeds:term' => $termlist)));
				$filters = array('status' => array(Post::status('unread'), Post::status('read')), 'vocabulary' => array('feeds:term' => $termlist));
			}
			
			// Process "mark ALL as read"
			if(!empty($form->mark_all_read->value)) {
				$filters['nolimit'] = 1;
				$term->info->count = 0;
				$term->update();
				foreach($term->descendants() as $d) {
					$d->info->count = 0;
					$d->update();
				}
			}
			
			// Get posts
			$posts = Posts::get($filters);
		
			// Process "mark * as read"
			if(!empty($form->mark_page_read->value) || !empty($form->mark_all_read->value)) {
				foreach($posts as $post) {
					$post->status = Post::status('read');
					$post->update();
					//Utils::debug($post);
				}
			}
			
			$theme->feedterm = $term;
			$theme->act_display(array('user_filters' => $filters));
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
