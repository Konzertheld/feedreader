<ul>
<?php foreach($content->navigation as $entry): ?>
	<li><a href="<?php echo $entry['url']; ?>"><?php echo $entry['title']; ?></a>
	<?php if(isset($entry['subitems'])): ?>
	<ul>
	<?php foreach($entry['subitems'] as $item): ?>
		<li><a href="<?php echo $item['url']; ?>"><?php echo $item['title']; ?></a></li>
	<?php endforeach; ?>
	</ul>
	<?php endif; ?>
	</li>
<?php endforeach; ?>
</ul>