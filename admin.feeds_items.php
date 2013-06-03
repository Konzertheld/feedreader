<?php if ( !defined( 'HABARI_PATH' ) ) { die('No direct access'); } ?>
<?php foreach ( $feeds as $feed ): ?>
	<div class="item clear">
		<span class="checkbox pct5"><span><input type="checkbox" class="checkbox" name="checkbox_ids"></span></span>
		<span class="time pct15 minor"><span><?php isset($feed['lastcheck']) ? print HabariDateTime::date_create($feed['lastcheck'])->format() : print 'none'; ?></span></span>
		<span class="pct15 minor"><span><?php if(isset($feed['group'])) echo $feed['group']; else echo "&nbsp;"; ?></span></span>
		<span class="pct5 minor"><span><?php echo $feed['count']; ?></span></span>
		<span class="pct30 minor"><span><?php echo $feed['url']; ?></span></span>
		<span class="pct25 minor"><span><?php echo $feed['title']; ?></span></span>
	</div>
<?php endforeach; ?>
