<ul>
<a href="#" onclick="FeedReader.toggle_empty();">Toggle empty</a>
<?php foreach($content->navigation as $entry): ?>
	<li <?php if(!$entry['count']) echo 'class="empty"'; ?>><a href="<?php echo $entry['url']; ?>"><?php echo $entry['title']; ?> (<?php echo $entry['count']; ?>)</a>
	<?php if(isset($entry['subitems'])): ?>
	<ul>
	<?php foreach($entry['subitems'] as $item): ?>
		<li <?php if(!$item['count']) echo 'class="empty"'; ?>><a href="<?php echo $item['url']; ?>"><?php echo $item['title']; ?> (<?php echo $item['count']; ?>)</a></li>
	<?php endforeach; ?>
	</ul>
	<?php endif; ?>
	</li>
<?php endforeach; ?>
</ul>