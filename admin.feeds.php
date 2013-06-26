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
	<h2>Filter</h2>
	<div class="head clear">
		<span class="time pct20"><?php _e('Last Update Before'); ?></span>
		<span class="time pct20"><?php _e('Last Update After'); ?></span>
		<span class="pct25"><?php _e('Group'); ?></span>
		<span class="pct15"><?php _e('Items'); ?></span>
		<span class="pct15">&nbsp;</span>
	</div>
	
	<div class="item clear">
		<span class="pct20"><input type="text" name="updated_before"></span>
		<span class="pct20"><input type="text" name="updated_after"></span>
		<span class="pct25"><?php echo Utils::html_select('group', $groups, $group, array( 'class'=>'pct90')); ?></span>
		<span class="pct15"><?php echo Utils::html_select('items', array('all', 'empty', 'not empty'), $itemstatus, array( 'class'=>'pct90')); ?></span>
		<span class="pct15"><input type="submit" name="filter" value="<?php _e('Filter'); ?>"></span>
	</div>
</div>

<div class="container transparent item controls">
	<span class="checkboxandselected pct30">
		<input type="checkbox" id="master_checkbox" name="master_checkbox">
		<label class="selectedtext minor none" for="master_checkbox"><?php _e('None selected'); ?></label>
	</span>
</div>

<div class="container">

	<div class="head clear">
		<span class="checkbox pct5">&nbsp;</span>
		<span class="time pct20"><?php _e('Last Update'); ?></span>
		<span class="pct5"><?php _e('Broken'); ?></span>
		<span class="pct20"><?php _e('Group'); ?></span>
		<span class="pct5"><?php _e('Items'); ?></span>
		<span class="pct45"><?php _e('Title'); ?></span>
	</div>
	
	<?php $theme->display('admin.feeds_items'); ?>

</div>
</form>

<?php $theme->display('footer');?>