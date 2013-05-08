<?php $theme->display('header');?>

<form method="post" action="<?php URL::out('admin', array( 'page' => 'logs' ) ); ?>" class="buttonform">

<div class="container transparent item controls">
	<span class="checkboxandselected pct30">
		<input type="checkbox" id="master_checkbox" name="master_checkbox">
		<label class="selectedtext minor none" for="master_checkbox"><?php _e('None selected'); ?></label>
	</span>
</div>

<div class="container">

	<div class="head clear">

		<span class="checkbox pct5">&nbsp;</span>
		<span class="time pct15"><?php _e('Last Update'); ?></span>
		<span class="pct10"><?php _e('Group'); ?></span>
		<span class="pct5"><?php _e('Items'); ?></span>
		<span class="pct35"><?php _e('URL'); ?></span>
		<span class="pct20"><?php _e('Title'); ?></span>

	</div>
	
	<?php $theme->display('admin.feeds_items'); ?>

</div>

<div class="container transparent item controls">

	<span class="checkboxandselected pct30">
		<input type="checkbox" id="master_checkbox_2" name="master_checkbox_2">
		<label class="selectedtext minor none" for="master_checkbox_2"><?php _e('None selected'); ?></label>
	</span>
	<ul class="dropbutton">
		<?php $page_actions = array(
			'delete' => array('action' => 'itemManage.update(\'delete\');return false;', 'title' => _t('Delete Selected'), 'label' => _t('Delete Selected') ),
			'purge' => array('action' => 'itemManage.update(\'purge\');return false;', 'title' => _t('Purge Logs'), 'label' => _t('Purge Logs') ),
		);
		$page_actions = Plugins::filter('logs_manage_actions', $page_actions);
		foreach( $page_actions as $page_action ) : ?>
			<li><a href="*" onclick="<?php echo $page_action['action']; ?>" title="<?php echo $page_action['title']; ?>"><?php echo $page_action['label']; ?></a></li>
		<?php endforeach; ?>
	</ul>

</div>

</form>

<?php $theme->display('footer');?>