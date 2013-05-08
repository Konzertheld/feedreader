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
					$item['group'] = $term->term_display;
					
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
		$nav = Cache::get('feedreader_nav');
		if($nav == null) {
			$nav = $this->create_navigation();
		}
		$block->navigation = $nav;
	}
	
	private function create_navigation()
	{
		$nav = array();

		foreach(Vocabulary::get('feeds')->get_root_terms() as $term) {
			if(count($term->descendants()) > 0) {
				$group = array();
				$group['url'] = URL::get('display_feedcontent', array('context' => 'group', 'feedslug' => $term->term));
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
		Cache::set('feedreader_nav', $nav, 60 * 60 * 24 * 7);
		
		return $nav;
	}
	
	private function term_to_menu($term)
	{
		$entry = Cache::get(array('feedreader_navitems', $term->term));
		if($entry == null) {
			$entry = array();
			$entry['url'] = URL::get('display_feedcontent', array('context' => 'feed', 'feedslug' => $term->term));
			$entry['title'] = ($term->info->title) ? $term->info->title : $term->term_display;
			$entry['count'] = Posts::get(array('status' => 'unread', 'content_type' => Post::type('entry'), 'nolimit'=>1, 'count' => '*', 'vocabulary' => array('any' => array($term))));
			Cache::set(array('feedreader_navitems', $term->term), $entry, 60 * 60 * 24 * 7);
		}
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
			// For the action 'configure':
			case 'configure':
				$ui = new FormUI( __CLASS__ );
				// Display the form
				$ui->append( 'submit', 'save', _t( 'Save' ) );
				$ui->out();
				break;
			case 'update':
				$result = $this->filter_load_feeds(true);
				if($result) {
					Session::notice('Feeds Successfully Updated');
				}
				else {
					Session::error('Feeds Did Not Successfully Update');
				}
				//@todo redirect
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
	 * UNUSED FUNCTION
	 */
	public function updated_config( $ui )
	{
		// Save general options and sorted feedlist
		$feedlist = explode( "\n", $ui->feedlist->value );
		// Make sure no url ends with a slash (for unifying and to avoid duplicates)
		array_walk($feedlist, create_function('&$url', '$url = (substr($url, -1) == "/") ? substr($url, 0, -1) : $url;'));
		$feedlist = array_unique($feedlist);
		natsort( $feedlist );
		// Dirty hack that will be removed when the new FormUI arrives
		$_POST[$ui->feedlist->field] =  implode( "\n", $feedlist );
		$ui->save();
		
		// Get feeds
		$groupedfeeds = array();
		$feeds = array();
		foreach($feedlist as $f) {
			if(strpos($f, '=')) {
				list($title, $urlstring) = explode('=', $f);
				$urls = explode(';', $urlstring);
			}
			else {
				$urls = explode(';', $f);
				$title = $urls[0];
			}
			if(empty($title)) continue;
			$groupedfeeds[$title] = $urls;
			$feeds = array_merge($feeds, $urls);
		}
		
		$feeds = array_unique($feeds);
		
		$vocab = Vocabulary::get('feeds');
			
		// Cleanup inactive and unused feed terms
		// $tree = $vocab->get_tree();
		// foreach($tree as $term) {
			// if(!in_array($term->term_display, $feeds)) {
				// The user removed the feed, deactivate it
				// $term->info->active = false;
				// $term->update();
			// }
			// if(!$term->info->active) {
				// If this feed is deactivated, check if there are posts associated and if not, remove it
				// $posts = Posts::get(array('vocabulary' => array('all' => array($term)), 'count' => '*'));
				// if(!$posts) {
					// $vocab->delete_term($term);
				// }
			// }
		// }
		
		// Process urls and add new terms
		$roots = 0;
		$groups = 0;
		$feeds = 0;
		
		foreach($groupedfeeds as $title => $group) {
			if(count($group) == 1) {
				// ungrouped feed
				$term = $vocab->get_term(Utils::slugify($group[0]));
				if(!$term) {
					$term = $vocab->add_term(new Term(array('term' => Utils::slugify($group[0]), 'term_display' => $group[0])));
				}
				$term->info->active = true;
				$term->update();
				$roots++;
			}
			elseif(count($group) > 1) {
				$term = $vocab->get_term(Utils::slugify($title));
				if(!$term) {
					$term = $vocab->add_term(new Term(array('term' => Utils::slugify($title), 'term_display' => $title)));
				}
				$groups++;
				foreach($group as $url) {
					$urlterm = $vocab->get_term(Utils::slugify($url));
					if(!$urlterm) {
						$urlterm = $vocab->add_term(new Term(array('term' => Utils::slugify($url), 'term_display' => $url)), $term);
					}
					$urlterm->info->active = true;
					$urlterm->update();
					$feeds++;
				}
			}
		}
		
		Eventlog::log('Updated feeds from config. %1$d feeds in %2$d groups and %3$d ungrouped feeds.', array($feeds, $groups, $roots), __CLASS__);

		// Reset the cronjob so that it runs immediately with the change
		CronTab::delete_cronjob( 'feedreader' );
		CronTab::add_hourly_cron( 'feedreader', 'load_feeds', 'Load feeds for feedreader plugin.' );

		return false;
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
					$term = $vocab->add_term(new Term(array('term' => Utils::slugify($o['title']), 'term_display' => $o['title'])));
				}
				$groups++;
				foreach($o->outline as $feed) {
					$urlterm = $vocab->get_term(Utils::slugify($feed['xmlUrl']));
					if(!$urlterm) {
						$urlterm = $vocab->add_term(new Term(array('term' => Utils::slugify($feed['xmlUrl']), 'term_display' => $feed['xmlUrl'])), $term);
					}
					$urlterm->info->active = true;
					$urlterm->info->title = (string) $feed['title'];
					$urlterm->update();
					$feeds++;
				}
			}
			else {
				$urlterm = $vocab->get_term(Utils::slugify($o['xmlUrl']));
				if(!$urlterm) {
					$urlterm = $vocab->add_term(new Term(array('term' => Utils::slugify($o['xmlUrl']), 'term_display' => $o['xmlUrl'])));
				}
				$urlterm->info->active = true;
				$urlterm->info->title = (string) $o['title'];
				$urlterm->update();
				$feeds++;
			}
		}
		Session::notice(_t('Imported %1$d feeds and %2$d groups', array($feeds, $groups), __CLASS__));
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
		Eventlog::log("Updating feeds...");

		foreach( $feedterms as $term ) {
			if(count($term->descendants()) > 0) {
				// Just a group term
				Eventlog::log("Skipped root group " . $term->term_display);
				continue;
			}
			if(!$term->info->active) {
				Eventlog::log("Skipped inactive feed " . $term->term_display);
				continue;
			}
			
			$feed_url = $term->term_display;
			
			if ( $feed_url == '' ) {
				EventLog::log( sprintf( _t('Feed ID %1$d has an invalid URL.'), $feed_id ), 'warning' );
				continue;
			}
			
			if(isset($term->info->lastcheck) && HabariDateTime::date_create()->int - HabariDateTime::date_create($term->info->lastcheck)->int < 600) {
				// Don't check more than every 10 minutes
				continue;
			}
			
			// load the XML data
			$xml = RemoteRequest::get_contents( $feed_url );
			if ( !$xml ) {
				EventLog::log( sprintf( _t('Unable to fetch feed %1$s data.'), $feed_url ), 'warning' );
				$term->info->broken = true;
				$term->update();
				continue;
			}
			
			$dom = new DOMDocument();
			// @ to hide parse errors
			@$dom->loadXML( $xml );
			
			if ( $dom->getElementsByTagName('rss')->length > 0 ) {
				$items = $this->parse_rss( $dom );
			}
			else if ( $dom->getElementsByTagName('feed')->length > 0 ) {
				$items = $this->parse_atom( $dom );
			}
			else {
				// it's an unsupported format
				EventLog::log( sprintf( _t('Feed %1$s is an unsupported format and has been deactivated.'), $feed_url), 'warning' );
				$term->info->active = false;
				$term->update();
				continue;
			}
			
			// At least now we got a human-readable feed title, save it
			$term->info->title = $dom->getElementsByTagName('title')->item(0)->nodeValue;
			$term->update();
			$this->replace( $term, $items );
			$term->info->lastcheck = HabariDateTime::date_create()->int;
			$term->update();
			
			// log that the feed was updated
			EventLog::log( sprintf( _t( 'Updated feed %1$s' ), $feed_url ), 'info' );
		}
		
		// This should only happen if any feed was updated
		$this->create_navigation();
		
		// log that we finished
		EventLog::log( sprintf( _t( 'Finished updating %1$d feed(s).' ), count( $feedterms ) ), 'info');
				
		return $result;		// only change a cron result to false when it fails
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
			
			// snag all the child tags we need
			$feed['title'] = $item->getElementsByTagName('title')->item(0)->nodeValue;
			if($item->getElementsByTagName('encoded')->length > 0) {
				// Wordpress-style fulltext content
				$feed['content'] = $item->getElementsByTagName('encoded')->item(0)->nodeValue;
			}
			else {
				$feed['content'] = $item->getElementsByTagName('description')->item(0)->nodeValue;
			}
			if($item->getElementsByTagName('creator')->length > 0) {
				// Wordpress-style author names
				$feed['author'] = $item->getElementsByTagName('creator')->item(0)->nodeValue;
			}
			elseif($item->getElementsByTagName('author')->length > 0) {
				$feed['author'] = $item->getElementsByTagName('author')->item(0)->nodeValue;
			}
			if($item->getElementsByTagName('link')->length > 0) {
				$feed['link'] = $item->getElementsByTagName('link')->item(0)->nodeValue;
			}
			else {
				Eventlog::log("No link found in " . $dom->getElementsByTagName('title')->item(0)->nodeValue, "warning");
			}
			if($item->getElementsByTagName('guid')->length > 0) {
				$feed['guid'] = $item->getElementsByTagName('guid')->item(0)->nodeValue;
			}
			else {
				Eventlog::log("No guid found in " . $dom->getElementsByTagName('title')->item(0)->nodeValue, "warning");
			}
			if($item->getElementsByTagName('pubDate')->length > 0) {
				$feed['published'] = $item->getElementsByTagName('pubDate')->item(0)->nodeValue;
			}
			else {
				Eventlog::log("No pubDate found in " . $dom->getElementsByTagName('title')->item(0)->nodeValue, "warning");
			}
			
			try {
				$feed['published'] = HabariDateTime::date_create( $feed['published'] );
			} catch(Exception $e) {}
			
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
			
			// snag all the child tags we need
			$feed['title'] = $item->getElementsByTagName('title')->item(0)->nodeValue;
			if($item->getElementsByTagName('creator')->length > 0) {
				// Wordpress-style author names
				$feed['author'] = $item->getElementsByTagName('creator')->item(0)->getElementsByTagName('name')->item(0)->nodeValue;
			}
			elseif($item->getElementsByTagName('author')->length > 0) {
				$feed['author'] = $item->getElementsByTagName('author')->item(0)->getElementsByTagName('name')->item(0)->nodeValue;
			}
			$feed['content'] = $item->getElementsByTagName('content')->item(0)->nodeValue;
			$feed['link'] = $item->getElementsByTagName('link')->item(0)->getAttribute('href');
			if($item->getElementsByTagName('id')->length > 0) {
				$feed['guid'] = $item->getElementsByTagName('id')->item(0)->nodeValue;
			}
			if($item->getElementsByTagName('published')->length > 0) {
				$feed['published'] = $item->getElementsByTagName('published')->item(0)->nodeValue;
			}
			if($item->getElementsByTagName('updated')->length > 0) {
				$feed['updated'] = $item->getElementsByTagName('updated')->item(0)->nodeValue;
			}
			
			$feed_items[] = $feed;
			
		}
		
		return $feed_items;
	}
	
	/**
	 * Insert all the feed items as posts and modify existing posts
	 * 
	 * @param int $feed_id The feed ID stored in the DB.
	 * @param array $items Array of items parsed from the feed to add.
	 */
	private function replace ( $term, $items ) {
		$changed = false;
		
		foreach ( $items as $item ) {
			// Sanity checks
			if(empty($item["content"])) {
				Eventlog::log( _t("Skipping item %s because it has no content.", array($term->term), __CLASS__), 'err' );
				continue;
			}
			
			// Create dates from date values. Handle missing and invalid dates.
			if(isset($item["published"])) {
				try {
					$pubdate = HabariDateTime::date_create($item["published"])->int;
				} catch(Exception $e) {
					$pubdate = HabariDateTime::date_create()->int;
				}
			}
			else {
				$pubdate = HabariDateTime::date_create()->int;
			}
			if(isset($item["updated"])) {
				try {
					$updated = HabariDateTime::date_create($item["updated"])->int;
				} catch(Exception $e) {
					$updated = $pubdate;
				}
			}
			else {
				$updated = $pubdate;
			}
			
			// Get existing post or create new one
			$post = Post::get(array('all:info' => array('guid' => $item["guid"])));
			if(!$post) {
				$post = new Post();
				$post->content_type = 1;
				$post->user_id = 1;
				$post->status = Post::status('unread');
			}
			else {
				// Check if the post was modified
				if($post->updated->int >= $updated) {
					continue;
				}
			}
			
			// Save fields
			$post->title = (!empty($item["title"])) ? $item["title"] : _t("Untitled", __CLASS__);
			$post->content = $item["content"];
			$post->updated = $updated;
			$post->published = $pubdate;
			//@todo This is bad because it creates duplicates for modified posts
			if(empty($item["guid"])) {
				$item["guid"] = Utils::slugify(md5($item["content"]));
			}
			$post->info->guid = $item["guid"];
			if(isset($item['link'])) {
				$post->info->link = $item["link"];
			}
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
		
		if($changed){
			// At least one item has changed. Expire the navigation cache for this feed.
			Cache::expire(array('feedreader_navitems', $term->term));
		}
	}
	
	/**
	 * Grab the posts requested by the matched rewrite rule and display them in the theme
	 */
	public function theme_route_display_feedcontent($theme, $params)
	{
		$term = Vocabulary::get('feeds')->get_term($params['feedslug']);
		if($term) {
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
			$theme->act_display(array('user_filters' => array('status' => Post::status('unread'), 'vocabulary' => array('feeds:term' => $termlist))));
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
				}
				else {
					$post->status = Post::status('read');
				}
				if($post->update(true)) {
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
