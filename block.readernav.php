<ul>
<a href="#" onclick="FeedReader.toggle_empty();">Toggle empty</a>
<?php foreach($content->navigation as $entry): ?>
	<li class="<?php if(!$entry['count']) echo 'empty'; else echo 'unread'; ?>"><a href="<?php echo $entry['url']; ?>"><?php echo $entry['title']; ?> (<?php echo $entry['count']; ?>)</a>
	<?php if(isset($entry['subitems'])): ?>
	<ul>
	<?php foreach($entry['subitems'] as $item): ?>
		<li class="<?php if(!$item['count']) echo 'empty'; else echo 'unread'; ?>"><a href="<?php echo $item['url']; ?>"><?php echo $item['title']; ?> (<?php echo $item['count']; ?>)</a></li>
	<?php endforeach; ?>
	</ul>
	<?php endif; ?>
	</li>
<?php endforeach; ?>
</ul>