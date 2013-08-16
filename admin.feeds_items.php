<?php if ( !defined( 'HABARI_PATH' ) ) { die('No direct access'); } ?>
<?php foreach ( $feeds as $feed ):
// if($i!=1) {Utils::debug($feed);$i=1;}?>
	
	<div class="item clear">
		<span class="pct5"><span><input type="checkbox" name="feed_slugs[]" value="<?php echo $feed['slug']; ?>"></span></span>
		<span class="time pct20 minor"><span><?php isset($feed['lastcheck']) && !empty($feed['lastcheck']) ? print HabariDateTime::date_create($feed['lastcheck'])->format() : print 'none'; ?></span></span>
		<span class="pct5 minor"><span>&nbsp;<?php if(isset($feed['brokencount'])) echo $feed['brokencount']; ?></span></span>
		<span class="pct20 minor"><span><?php if(isset($feed['group'])) echo $feed['group']; else echo "&nbsp;"; ?></span></span>
		<span class="pct5 minor"><span>&nbsp;<?php if(isset($feed['count'])) echo $feed['count']; ?></span></span>
		<span class="pct45 minor"><span><?php if(isset($feed['title'])) echo $feed['title']; else echo "&nbsp;"; ?></span></span>
		<div style="clear:both;">
			<span class="pct5 minor"><span>&nbsp;</span></span>
			<span class="pct70 minor"><span><?php if(isset($feed['url'])) echo $feed['url']; else echo "&nbsp;"; ?></span></span>
			<span class="pct5 minor"><span>&nbsp;</span></span>
			<span class="pct20 minor error feederror"><span><?php if(isset($feed['brokentext'])) echo $feed['brokentext']; else echo "&nbsp;"; ?></span></span>
		</div>
	</div>
<?php endforeach; ?>
