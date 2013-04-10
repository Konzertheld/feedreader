<?php

/**
 * FeedReader allows you to use your blog to read your feeds. Your blogflow will be turned into a flow of feed entries. You can use content types to store your content appropriate. Also, feeds can be grouped by type, tag, author or your personal choice.
  * This plugin contains large code segments from feedlist by Owen Winkler and Chris Meller. Thanks for creating code to access atom feeds.
  * Most of the Admin UI is from the core menus plugin by Mike Lietz. Thanks for creating a nice interface to manage link structures.
 */

class FeedReader extends Plugin
{ 

	/**
	 * Plugin init action, executed when plugins are initialized.
	 */ 
	public function action_init()
	{
		// Register block template
		$this->add_template( 'block.feedlist', dirname(__FILE__) . '/block.feedlist.php' );
		
		// create the post display rule for one addon
		$rule = new RewriteRule(array(
			'name' => "display_feedcontent",
			// this scary regex...
			'parse_regex' => "#^(?P<context>group|feed)/(?P<feedslug>[^/]+)/?$#i",
			// just matches requests that look like this, not regarding the case:
			'build_str' => '{$context}/{$feedslug}',
			'handler' => 'PluginHandler',
			'action' => 'display_feedcontent',
			'description' => "Display an addon catalog post of a particular type",
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
			// Register a default event log type for this plugin
			EventLog::register_type( "default", "FeedList" );
			// Add a periodical execution event to be triggered hourly
			CronTab::add_hourly_cron( 'feedlist', 'load_feeds', 'Load feeds for feedlist plugin.' );
			// Log the cron creation event
			EventLog::log('Added hourly cron for feed updates.');
			// Create vocabulary for the feeds
			Vocabulary::create(array('description' => 'Feeds to collect posts from', 'name' => 'feeds'));
			// Add read and unread statuses
			Post::add_new_status('read');
			Post::add_new_status('unread');
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
			// Remove the periodical execution event
			CronTab::delete_cronjob( 'feedlist' );
			// Log the cron deletion event.
			EventLog::log('Deleted cron for feed updates.');
			// Remove statuses
			Post::delete_post_status('read');
			Post::delete_post_status('unread');
		}
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
				// Create a new Form called 'feedlist'
				$ui = new FormUI( 'feedlist' );
				// Add a text control for the number of feed items shown
				$itemcount = $ui->append('text', 'itemcount', 'feedlist__itemcount', 'Number of shown Feed Items');
				// Add a text control for the feed URL
				$feedurl = $ui->append('textmulti', 'feedurl', 'feedlist__feedurl', 'Feed URL');
				// Mark the field as required
				$feedurl->add_validator( 'validate_required' );
				// Mark the field as requiring a valid URL
//				$feedurl->add_validator( 'validate_url' );
				// When the form is successfully completed, call $this->updated_config()
				$ui->on_success( array( $this, 'updated_config') );
				$ui->set_option( 'success_message', _t( 'Configuration updated' ) );
				// Display the form
				$ui->append( 'submit', 'save', _t( 'Save' ) );
				$ui->out();
				break;
			case 'update':
				$result = $this->filter_load_feeds(true);
				if($result) {
					Session::notice('RSS Feeds Successfully Updated');
				}
				else {
					Session::error('RSS Feeds Did Not Successfully Update');
				}
				//@todo redirect
				break;
			}
		}
	}
	
	/**
	 * Perform actions when the admin plugin form is successfully submitted. 
	 * 
	 * @param FormUI $ui The form that successfully completed
	 * @return boolean True if the normal processing should occur to save plugin options from the form to the database
	 */
	public function updated_config( $ui )
	{
		// Save general options
		// @todo eventually get rid of the stored textmulti when the new FormUI arrives, so we don't store the list twice
		$ui->save();
		
		$vocab = Vocabulary::get('feeds');
			
		// Cleanup inactive and unused feed terms
		$tree = $vocab->get_tree();
		foreach($tree as $term) {
			if(!in_array($term->term_display, $ui->feedurl->value)) {
				// The user removed the feed, deactivate it
				$term->info->active = false;
				$term->update();
			}
			if(!$term->info->active) {
				// If this feed is deactivated, check if there are posts associated and if not, remove it
				$posts = Posts::get(array('vocabulary' => array('all' => array($term)), 'count' => '*'));
				if(!$posts) {
					$vocab->delete_term($term);
				}
			}
		}
		
		// Process urls and add new terms
		foreach($ui->feedurl->value as $url) {
			$term = $vocab->get_term($url);
			if(!$term) {
				$term = $vocab->add_term($url);
			}
			$term->info->active = true;
			$term->update();
		}
		
		// Reset the cronjob so that it runs immediately with the change
		CronTab::delete_cronjob( 'feedlist' );
		CronTab::add_hourly_cron( 'feedlist', 'load_feeds', 'Load feeds for feedlist plugin.' );

		return false;
	} 

	/**
	 * Plugin load_feeds filter, executes for the cron job defined in action_plugin_activation()
	 * @param boolean $result The incoming result passed by other sinks for this plugin hook
	 * @return boolean True if the cron executed successfully, false if not.
	 */
	public function filter_load_feeds( $result )
	{
		$feedterms = Vocabulary::get('feeds')->get_tree();

		foreach( $feedterms as $term ) {
			if(!$term->info->active) {
				continue;
			}
			
			$feed_url = $term->term_display;
			
			if ( $feed_url == '' ) {
				EventLog::log( sprintf( _t('Feed ID %1$d has an invalid URL.'), $feed_id ), 'warning', 'feedlist', 'feedlist' );
				continue;
			}
			
			// load the XML data
			$xml = RemoteRequest::get_contents( $feed_url );
			
			if ( !$xml ) {
				EventLog::log( sprintf( _t('Unable to fetch feed %1$s data.'), $feed_url ), 'err', 'feedlist', 'feedlist' );
			}
			
			$dom = new DOMDocument();
			// @ to hide parse errors
			@$dom->loadXML( $xml );
			
			if ( $dom->getElementsByTagName('rss')->length > 0 ) {
				$term->info->title = $dom->getElementsByTagName('title')->item(0)->nodeValue;
				$term->update();
				$items = $this->parse_rss( $dom );
				$this->replace( $term, $items );
			}
			else if ( $dom->getElementsByTagName('feed')->length > 0 ) {
				$term->info->title = $dom->getElementsByTagName('title')->item(0)->nodeValue;
				$term->update();
				$items = $this->parse_atom( $dom );
				$this->replace( $term, $items );
			}
			else {
				// it's an unsupported format
				EventLog::log( sprintf( _t('Feed %1$s is an unsupported format.'), $feed_url), 'err', 'feedlist', 'feedlist' );
				continue;
			}
			
			// log that the feed was updated
			EventLog::log( sprintf( _t( 'Updated feed %1$s' ), $feed_url ), 'info', 'feedlist', 'feedlist' );
			
		}
		
		// log that we finished
		EventLog::log( sprintf( _t( 'Finished updating %1$d feed(s).' ), count( $feedurls ) ), 'info', 'feedlist', 'feedlist' );
				
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
			$feed['link'] = $item->getElementsByTagName('link')->item(0)->nodeValue;
			$feed['guid'] = $item->getElementsByTagName('guid')->item(0)->nodeValue;
			$feed['published'] = $item->getElementsByTagName('pubDate')->item(0)->nodeValue;
			
			// try to blindly make sure the date is a HDT object - it should be a pretty standard PHP-parseable format
			$feed['published'] = HabariDateTime::date_create( $feed['published'] );
			
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
			$feed['content'] = $item->getElementsByTagName('content')->item(0)->nodeValue;
			$feed['link'] = $item->getElementsByTagName('link')->item(0)->getAttribute('href');
			$feed['guid'] = $item->getElementsByTagName('id')->item(0)->nodeValue;
			$feed['published'] = $item->getElementsByTagName('updated')->item(0)->nodeValue;
			
			// try to blindly make sure the date is a HDT object - it should be a pretty standard PHP-parseable format
			$feed['published'] = HabariDateTime::date_create( $feed['published'] );
			
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
		foreach ( $items as $item ) {
			$post = Post::get(array('all:info' => array('guid' => $item["guid"])));
			if(!$post) {
				$post = new Post();
				$post->content_type = 1;
				$post->user_id = 1;
				$post->status = Post::status('unread');
			}
			$post->title = $item["title"];
			$post->content = $item["content"];
			$post->info->guid = $item["guid"];
			$post->info->link = $item["link"];
			$post->updated = HabariDateTime::date_create($item["published"])->int;
			$post->pubdate = HabariDateTime::date_create($item["published"])->int;
			$result = ($post->id) ? $post->update() : $post->insert();
			$term->associate('post', $post->id);
			
			if ( !$result ) {
				EventLog::log( 'There was an error saving a feed item.', 'err', 'feedlist', 'feedlist' );
			}
		}
	}
	
	/**
	 * Grab the posts requested by the matched rewrite rule and display them in the theme
	 */
	public function theme_route_display_feedcontent($theme, $params)
	{
		$theme->act_display(array('user_filters' => array('status' => Post::status('unread'), 'vocabulary' => array('feeds:term' => array($params['feedslug'])), 'nolimit' => 1)));
	}
}	

?>
