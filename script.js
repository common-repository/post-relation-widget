jQuery(document).ready(function($) {
	var options = prw_script;
	var ajaxurl = options['ajaxurl'];

	$.prw_post_request = function( action, data, my_function ) {
		var thisData = {
				action: action,				
				post_id: options['post_id'],
				data: data,				
			};
		$.ajax({
		    url: ajaxurl,
		    type: 'POST',
		    data: thisData,
		    beforeSend: function () {
		    	//jQuery("#post-relation-widget-loader").css('display', 'inline');
		    },
		    success: function (response) {		    	
		    	//jQuery("#post-relation-widget-loader").css('display', 'none');
		    	if ( response || (my_function != undefined && typeof my_function == 'function') ) {
		    		my_function(response);
		    	}
		    }
		});
	}

	function prw_related_search_list(response) {
		$("#prw-search-results").html( response );
		prw_action_handlers();
	}
	function prw_output_all(response) {		
		$("#post-relation-widget-wrapper").html( response );
		prw_action_handlers();
	}
	function prw_post_request_success(response) {
		$("#prw-ajax-msgs").html( response ); //output errors and msgs
		$.prw_post_request( 'prw_ajax_output_all', '', prw_output_all );
	}

	$("#prw-search-wrapper").css('display', 'none');
	$("#prw-show-search").click(function() { 
		$("#prw-show-search").css('display', 'none');
		$("#prw-search-wrapper").slideDown("fast");
		$('#prw-search').focus();
		return false;
	});
	$("#prw-search-results").css('display', 'none');
	$("#prw-search").focus(function() {
		$("#prw-search-results").slideDown("fast");
	});
	
	$("#prw-search").keyup(function() {	
		delay(function(){
			$.prw_post_request( 'prw_ajax_search', $("#prw-search").val(), prw_related_search_list );
		}, 500 );
		
	});
	function prw_action_handlers() {
		$(".prw-add-relation").click( function() {
			var data = new Object();			
			data['rel_post'] = $(this).attr('prw_post_ID');
			data['relation'] = $(this).parent().attr('prw_relation_ID');
			$(this).slideUp("fast");
			$.prw_post_request( 'prw_add_relation', data, prw_post_request_success );
			return false;
		});

		$(".prw-delete-relation").click( function() {
			var data = new Object();
			data['id'] = $(this).attr('prw_ID');
			data['rel_post']= $(this).attr('prw_post_ID');
			data['relation'] = $(this).parent().attr('prw_relation_ID');
			$(this).slideUp("fast");
			$.prw_post_request( 'prw_delete_relation', data, prw_post_request_success );
			return false;
		});
	}
	prw_action_handlers();

	function prw_admin_create_relation(response) {
		var before_relations = $("#prw-relations-table").html();
		$("#prw-relations-table").html( before_relations + response )
	}
	$("#prw-admin-create-relation").click(function() {
		var data = parseInt( $(this).attr('prw_relation_ID') );
		$(this).attr('prw_relation_ID', data+1);
		$.prw_post_request( 'prw_new_relation_section', data, prw_admin_create_relation );
		return false;
	});

	var delay = (function(){
		var timer = 0;
		return function(callback, ms){
			clearTimeout (timer);
			timer = setTimeout(callback, ms);
		};
	})();
});