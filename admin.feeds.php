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

	<div class="item clear">
		<span class="pct5">&nbsp;</span>
		<span class="time pct20"><?php _e('Last Update'); ?></span>
		<span class="pct5"><?php _e('ERR'); ?></span>
		<span class="pct15"><?php _e('Group'); ?></span>
		<span class="pct10"><?php _e('Unread'); ?></span>
		<span class="pct25"><?php _e('Title'); ?></span>
		<ul class="dropbutton pct20">
			<li style="display: block;"><input type="submit" name="filter" value="<?php _e('Apply filter'); ?>"></li>
			<li><input type="submit" name="delete" value="<?php _e('Delete selected'); ?>"></li>
			<li><input type="submit" name="edit" value="<?php _e('Edit selected'); ?>"></li>
			<li><input type="submit" name="update" value="<?php _e('Update selected'); ?>"></li>
			<li><input type="submit" name="applygroup" value="<?php _e('Apply group to selected'); ?>"></li>
		</ul>
	</div>
	
	<div class="item clear">
		<span class="pct5"><input type="checkbox" id="master_checkbox" name="master_checkbox"></span>
		<span class="time pct20"><input id="timefilter" type="text" name="updated_before" value="updated before"></span>
		<span class="pct5">&nbsp;</span>
		<span class="pct15"><?php echo Utils::html_select('group', $groups, $group, array( 'class'=>'pct95')); ?></span>
		<span class="pct10"><?php echo Utils::html_select('items', array('all', 'none', 'some'), $itemstatus, array( 'class'=>'pct95')); ?></span>
		<span class="pct20"><input id="titlefilter" type="text" name="title_regex" value="title regex filter"></span>
		<span class="pct25 feederror"><label for="only_broken">Only broken feeds</label><input id="brokenfilter" type="checkbox" name="only_broken"></span>
	</div>
	
	<div id="new_group_wrapper" class="item clear">
		<span class="time pct100"><input class="pct15" id="new_group" type="text" name="new_group" value="enter name"></span>
	</div>
	
	<?php $theme->display('admin.feeds_items'); ?>

</div>
</form>

<?php $theme->display('footer');?>