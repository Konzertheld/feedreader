$("#master_checkbox").click(function() {
	if($("#master_checkbox").attr("checked") == undefined) {
		$("[name='feed_slugs[]']").attr("checked", false);
	}
	else {
		$("[name='feed_slugs[]']").attr("checked", true);
	}
});

$("#group").change(function() {
	if($(this).val() == "new") {
		$("#new_group_wrapper").slideDown();
	}
	else {
		$("#new_group_wrapper").fadeOut();
	}
});