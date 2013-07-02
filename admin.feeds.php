<?php $theme->display('header');?>

<form method="post" action="<?php URL::out('admin', array( 'page' => 'manage_feeds' ) ); ?>" class="buttonform">
<div class="container">
	<h2>Add</h2>
	<div class="item clear">
		<span class="pct85"><input class="pct95" type="text" name="new_feedurl"></span>
		<span class="pct10"><input type="submit" name="add_feed" value="<?php _e('Add'); ?>"></span>
	</div>
</div>

<div class="container">

	<div class="head clear">
		<span class="checkbox pct5">&nbsp;</span>
		<span class="time pct20"><?php _e('Last Update'); ?></span>
		<span class="pct5"><?php _e('ERR'); ?></span>
		<span class="pct15"><?php _e('Group'); ?></span>
		<span class="pct10"><?php _e('Unread'); ?></span>
		<span class="pct45"><?php _e('Title'); ?></span>
	</div>
	
	<div class="item clear">
		<span class="checkbox pct5"><input type="checkbox" id="master_checkbox" name="master_checkbox"></span>
		<span class="time pct20"><input id="timefilter" type="text" name="updated_before" value="updated before"></span>
		<span class="pct5">&nbsp;</span>
		<span class="pct15"><?php echo Utils::html_select('group', $groups, $group, array( 'class'=>'pct95')); ?></span>
		<span class="pct10"><?php echo Utils::html_select('items', array('all', 'none', 'some'), $itemstatus, array( 'class'=>'pct95')); ?></span>
		<span class="pct20"><input id="titlefilter" type="text" name="updated_before" value="title regex filter"></span>
		<ul class="dropbutton pct20">
			<li><input type="submit" name="filter" value="<?php _e('Apply filter'); ?>"></li>
			<li><input type="submit" name="delete" value="<?php _e('Delete selected'); ?>"></li>
			<li><input type="submit" name="edit" value="<?php _e('Edit selected'); ?>"></li>
			<li><input type="submit" name="applygroup" value="<?php _e('Apply group to selected'); ?>"></li>
		</ul>
	</div>
	
	<?php $theme->display('admin.feeds_items'); ?>

</div>
</form>

<?php $theme->display('footer');?>